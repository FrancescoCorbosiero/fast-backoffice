<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Pipeline\Enums\ImageFormat;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Integrations\CommandRunner;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\ChangeRequestAttachment;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Modules\Pipeline\Support\AttachmentStore;
use App\Modules\Pipeline\Support\Images\ImageSanitizer;
use App\Modules\Pipeline\Support\PreviewCopier;
use App\Modules\Repositories\Models\Repository;
use App\Modules\Websites\Models\Website;
use Tests\Support\FakeCommandRunner;

/**
 * A client with a website whose repository is linked, and the fake runner
 * the pipeline tests run against, with the workspace and preview roots
 * under storage/framework/testing.
 */
final class Site
{
    /**
     * @return array{contact: Contact, website: Website, repository: Repository, pipeline: WebsitePipeline}
     */
    public static function make(PipelineStatus $status = PipelineStatus::Ready, array $contact = []): array
    {
        $client = Contact::factory()->company('Pasticceria Bellavista S.r.l.')->create($contact);
        $website = Website::factory()->create(['contact_id' => $client->id]);
        $repository = Repository::factory()->create(['contact_id' => $client->id, 'website_id' => $website->id]);
        $pipeline = WebsitePipeline::factory()->create(['website_id' => $website->id, 'repository_id' => $repository->id, 'status' => $status]);

        return ['contact' => $client, 'website' => $website, 'repository' => $repository, 'pipeline' => $pipeline];
    }

    public static function fake(): FakeCommandRunner
    {
        $root = storage_path('framework/testing/pipeline');
        (new PreviewCopier)->delete($root);
        config()->set('pipeline.workspace_root', $root.'/workspace');
        config()->set('pipeline.preview_root', $root.'/previews');
        config()->set('pipeline.preview_domain', 'preview.example.test');
        config()->set('pipeline.git.env', ['GIT_SSH_COMMAND' => 'ssh -i /run/secrets/pipeline_deploy_key -o IdentitiesOnly=yes']);

        $fake = new FakeCommandRunner;
        $fake->files = ['pipeline.yml' => FakeCommandRunner::manifest(), 'CLAUDE.md' => "# Sito\nHugo, contenuti in content/."];
        app()->instance(CommandRunner::class, $fake);

        return $fake;
    }

    public static function previewDir(string $slug): string
    {
        return storage_path('framework/testing/pipeline/previews/'.$slug);
    }

    /**
     * A photo on a request, as SubmitChangeRequest would have stored it:
     * cleaned, on the attachments disk (fake it first), with its row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function attach(ChangeRequest $request, string $bytes, string $name = 'foto.jpg', int $position = 1, array $attributes = []): ChangeRequestAttachment
    {
        $image = (new ImageSanitizer)->sanitize($bytes, ImageFormat::cases(), 50);

        return ChangeRequestAttachment::factory()->create([
            'change_request_id' => $request->id,
            'original_name' => $name,
            'stored_path' => app(AttachmentStore::class)->put($image->bytes, $image->format),
            'mime' => $image->format,
            'size_bytes' => strlen($image->bytes),
            'sha256' => hash('sha256', $image->bytes),
            'width' => $image->width,
            'height' => $image->height,
            'position' => $position,
            ...$attributes,
        ]);
    }
}
