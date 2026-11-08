<?php

declare(strict_types=1);

use App\Kernel\Reference;
use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Pipeline\Actions\SubmitChangeRequest;
use App\Modules\Pipeline\Actions\UnlinkRepository;
use App\Modules\Pipeline\Auth\ClientUser;
use App\Modules\Pipeline\Data\AttachmentUpload;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Data\ChangeRequestInput;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\ImageFormat;
use App\Modules\Pipeline\Jobs\PruneAttachments;
use App\Modules\Pipeline\Jobs\RunStaticSiteRequest;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\ChangeRequestAttachment;
use App\Modules\Pipeline\Support\Images\ExifOrientation;
use App\Modules\Scheduling\JobRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Pipeline\Site;
use Tests\Support\ImageFixtures;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('pipeline-uploads');
    $this->travelTo('2026-03-15 10:00:00');
    $this->site = Site::make();
});

/**
 * @param  list<UploadedFile>  $files
 */
function submitWithPhotos(array $site, array $files, string $text = 'Metti questa foto nella pagina Chi sono.', ?int $from = null): ChangeRequestData
{
    return app(SubmitChangeRequest::class)->submit(new ChangeRequestInput(
        $site['contact']->id,
        $site['website']->id,
        $text,
        array_map(fn (UploadedFile $file): AttachmentUpload => AttachmentUpload::fromUploadedFile($file), $files),
        $from,
    ));
}

function photo(string $name, string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/**
 * @return array<string, list<string>>
 */
function submitErrors(callable $submit): array
{
    try {
        $submit();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The submit was accepted.');
}

function portalClientOf(Contact $contact): ClientUser
{
    return new ClientUser($contact->id, $contact->display_name, $contact->email);
}

it('stores the photos cleaned, in the client\'s order, with what they really are', function (): void {
    $data = submitWithPhotos($this->site, [photo('Ritratto per Chi sono.JPG', ImageFixtures::jpeg(6)), photo('vetrina.jpg', ImageFixtures::png(1))]);

    $rows = ChangeRequestAttachment::query()->orderBy('position')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->original_name)->toBe('Ritratto per Chi sono.JPG')
        ->and($rows[0]->mime)->toBe(ImageFormat::Jpeg)
        ->and([$rows[0]->width, $rows[0]->height])->toBe([ImageFixtures::HEIGHT, ImageFixtures::WIDTH])
        ->and($rows[0]->stored_path)->toMatch('/^[0-9a-z]{26}\.jpg$/')
        // A PNG named .jpg is a PNG: the type comes from the bytes.
        ->and($rows[1]->mime)->toBe(ImageFormat::Png)
        ->and($rows[1]->stored_path)->toEndWith('.png')
        ->and($rows[1]->position)->toBe(2);

    foreach ($rows as $row) {
        $stored = Storage::disk('pipeline-uploads')->get($row->stored_path);
        expect($stored)->not->toContain(ImageFixtures::CANARY)
            ->and(hash('sha256', (string) $stored))->toBe($row->sha256)
            ->and(strlen((string) $stored))->toBe($row->size_bytes);
    }
    expect(Storage::disk('pipeline-uploads')->get($rows[0]->stored_path))->toContain("Exif\0\0".ExifOrientation::minimalTiff(6));

    expect($data->attachments)->toHaveCount(2)
        ->and($data->attachments[0]->workspacePath())->toBe('pipeline-uploads/1-ritratto-per-chi-sono.jpg')
        ->and($data->attachments[1]->workspaceName())->toBe('2-vetrina.png');
    Queue::assertPushed(RunStaticSiteRequest::class);
    $received = collect(app(AuditTrail::class)->forSubject(Reference::to('change_request', $data->id)))->firstWhere('action', 'pipeline.change_request.received');
    expect($received?->details['attachments'] ?? null)->toBe(2);
});

it('refuses what isn\'t a JPEG, PNG or WebP, and tells AVIF and HEIC apart', function (): void {
    $errors = submitErrors(fn () => submitWithPhotos($this->site, [
        photo('documento.jpg', "%PDF-1.7\n1 0 obj <<>> endobj\n"),
        photo('dal-telefono.heic', "\0\0\0\x18ftypheic\0\0\0\0mif1heic".str_repeat("\0", 32)),
        photo('ok.jpg', ImageFixtures::jpeg(1)),
    ]));

    expect($errors['attachments'])->toBe([
        '«documento.jpg» non è una foto JPEG, PNG o WebP.',
        '«dal-telefono.heic» è in formato AVIF o HEIC: esportala in JPEG e riprova.',
    ])->and(ChangeRequest::query()->count())->toBe(0)
        ->and(Storage::disk('pipeline-uploads')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('holds the photos to their limits: how many, how big, how big in all, how many megapixels', function (): void {
    config()->set('pipeline.attachments.max_files', 2);
    expect(submitErrors(fn () => submitWithPhotos($this->site, [photo('a.jpg', ImageFixtures::jpeg()), photo('b.jpg', ImageFixtures::jpeg()), photo('c.jpg', ImageFixtures::jpeg())]))['attachments'])
        ->toBe(['Puoi allegare al massimo 2 foto.']);

    // The size is the file's own, checked before a byte of it is read.
    config()->set('pipeline.attachments.max_mb', 1);
    expect(submitErrors(fn () => submitWithPhotos($this->site, [photo('grande.jpg', str_repeat('x', 1024 * 1024 + 1))]))['attachments'])
        ->toBe(['«grande.jpg» è troppo grande: al massimo 1 MB.']);

    config()->set('pipeline.attachments.max_mb', 25);
    config()->set('pipeline.attachments.max_total_mb', 1);
    expect(submitErrors(fn () => submitWithPhotos($this->site, [photo('a.jpg', str_repeat('x', 600 * 1024)), photo('b.jpg', str_repeat('x', 600 * 1024))]))['attachments'])
        ->toBe(['Le foto pesano più di 1 MB in tutto: allegane meno, o riducile, e riprova.']);

    config()->set('pipeline.attachments.max_total_mb', 90);
    expect(submitErrors(fn () => submitWithPhotos($this->site, [photo('panorama.png', ImageFixtures::hugePng(10_000, 6_000))]))['attachments'])
        ->toBe(['«panorama.png» è troppo grande: al massimo 50 megapixel. Riducila e riprova.']);

    expect(ChangeRequest::query()->count())->toBe(0)->and(Storage::disk('pipeline-uploads')->allFiles())->toBe([]);
});

it('says what didn\'t arrive, and gives the text\'s errors and the photos\' together', function (): void {
    $errors = submitErrors(fn () => app(SubmitChangeRequest::class)->submit(new ChangeRequestInput($this->site['contact']->id, $this->site['website']->id, '   ', [
        new AttachmentUpload('enorme.jpg', null, UPLOAD_ERR_INI_SIZE),
        new AttachmentUpload('a-metà.jpg', null, UPLOAD_ERR_PARTIAL),
    ])));

    expect($errors)->toBe([
        'request_text' => ['Scrivi cosa vuoi cambiare.'],
        'attachments' => ['«enorme.jpg» è troppo grande: al massimo 25 MB.', '«a-metà.jpg» non è arrivata per intero: riprova.'],
    ]);
});

it('keeps the client\'s file name for display only, cleaned', function (): void {
    submitWithPhotos($this->site, [photo("C:\\Users\\maria\\Foto\x07 \u{202E}gpj.exe  ritratto\u{200B}.jpg", ImageFixtures::jpeg())]);
    $row = ChangeRequestAttachment::query()->sole();

    // The browser's path is gone, and so are the bell, the right-to-left override and the zero-width space.
    expect($row->original_name)->toBe('Foto gpj.exe ritratto.jpg')
        ->and($row->toData()->workspaceName())->toBe('1-foto-gpjexe-ritratto.jpg');
});

it('leaves no file behind when the request can\'t be written', function (): void {
    ChangeRequestAttachment::creating(fn (): never => throw new RuntimeException('database down'));

    expect(fn () => submitWithPhotos($this->site, [photo('a.jpg', ImageFixtures::jpeg()), photo('b.png', ImageFixtures::png())]))->toThrow(RuntimeException::class, 'database down')
        ->and(ChangeRequest::query()->count())->toBe(0)
        ->and(Storage::disk('pipeline-uploads')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('copies the photos still there when a request is repeated, for the same client and site only', function (): void {
    $failed = ChangeRequest::factory()->status(ChangeRequestStatus::Failed)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    $kept = Site::attach($failed, ImageFixtures::jpeg(6), 'ritratto.jpg', 1);
    Site::attach($failed, ImageFixtures::png(), 'vecchia.png', 2, ['removed_at' => now()]);

    $retry = submitWithPhotos($this->site, [], 'Di nuovo: metti la foto nella pagina Chi sono.', $failed->id);

    expect($retry->attachments)->toHaveCount(1)
        ->and($retry->attachments[0]->originalName)->toBe('ritratto.jpg')
        ->and($retry->attachments[0]->sha256)->toBe($kept->sha256)
        ->and($retry->attachments[0]->position)->toBe(1);
    $copy = ChangeRequestAttachment::query()->where('change_request_id', $retry->id)->sole();
    expect($copy->stored_path)->not->toBe($kept->stored_path)
        ->and(Storage::disk('pipeline-uploads')->get($copy->stored_path))->toBe(Storage::disk('pipeline-uploads')->get($kept->stored_path));

    $other = Site::make(contact: ['email' => 'altro@example.test']);
    expect(submitErrors(fn () => submitWithPhotos($other, [], 'Prova', $failed->id))['attachments'][0])->toContain('un\'altra richiesta');

    // A file that no longer matches its checksum is not copied.
    Storage::disk('pipeline-uploads')->put($kept->stored_path, 'altered');
    expect(submitErrors(fn () => submitWithPhotos($this->site, [], 'Ancora', $failed->id))['attachments'][0])->toContain('non è più leggibile');
});

it('stamps the end of a request, the clock its photos are kept by', function (): void {
    $request = ChangeRequest::factory()->status(ChangeRequestStatus::Queued)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    expect($request->finished_at)->toBeNull();

    $request->moveTo(ChangeRequestStatus::Running);
    expect($request->refresh()->finished_at)->toBeNull();

    $this->travelTo('2026-03-15 11:30:00');
    $request->moveTo(ChangeRequestStatus::Failed, ['error' => 'boom']);
    expect($request->refresh()->finished_at?->toDateTimeString())->toBe('2026-03-15 11:30:00');
});

it('sends guests to their own sign-in form', function (): void {
    $request = ChangeRequest::factory()->previewReady()->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    $attachment = Site::attach($request, ImageFixtures::jpeg(6), 'Ritratto.jpg');

    $this->get("/admin/change-requests/{$request->id}/attachments/{$attachment->id}")->assertRedirect('/admin/login');
    $this->get("/area-clienti/richieste/{$request->id}/foto/{$attachment->id}")->assertRedirect('/area-clienti/accedi');
});

it('shows a photo to the owner through the panel\'s route', function (): void {
    $request = ChangeRequest::factory()->previewReady()->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    $attachment = Site::attach($request, ImageFixtures::jpeg(6), 'Ritratto.jpg');
    $other = Site::make(contact: ['email' => 'altro@example.test']);
    $theirs = ChangeRequest::factory()->previewReady()->create(['contact_id' => $other['contact']->id, 'website_id' => $other['website']->id]);

    signInAsOwner();
    $owner = $this->get("/admin/change-requests/{$request->id}/attachments/{$attachment->id}");
    $owner->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($owner->streamedContent())->toBe(Storage::disk('pipeline-uploads')->get($attachment->stored_path))
        ->and(route('filament.admin.pipeline.attachment', ['record' => $request->id, 'attachment' => $attachment->id]))->toEndWith("/admin/change-requests/{$request->id}/attachments/{$attachment->id}");
    // Through another request's address, even the owner gets nothing.
    $this->get("/admin/change-requests/{$theirs->id}/attachments/{$attachment->id}")->assertNotFound();
});

it('shows a photo to the request\'s own client, inline, typed, never sniffed or cached by others', function (): void {
    $request = ChangeRequest::factory()->previewReady()->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    $attachment = Site::attach($request, ImageFixtures::jpeg(6), 'Ritratto.jpg');
    $bytes = (string) Storage::disk('pipeline-uploads')->get($attachment->stored_path);

    $this->actingAs(portalClientOf($this->site['contact']), 'portal');
    $response = $this->get("/area-clienti/richieste/{$request->id}/foto/{$attachment->id}");
    $response->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename=1-ritratto.jpg')
        ->and($response->streamedContent())->toBe($bytes);
});

it('never shows a client another client\'s photo, nor a photo through another request, nor a removed one', function (): void {
    $other = Site::make(contact: ['email' => 'altro@example.test']);
    $theirs = ChangeRequest::factory()->previewReady()->create(['contact_id' => $other['contact']->id, 'website_id' => $other['website']->id]);
    $theirPhoto = Site::attach($theirs, ImageFixtures::jpeg());
    $mine = ChangeRequest::factory()->previewReady()->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    $removed = Site::attach($mine, ImageFixtures::png(), 'tolta.png', 1, ['removed_at' => now()]);

    $this->actingAs(portalClientOf($this->site['contact']), 'portal');
    $this->get("/area-clienti/richieste/{$theirs->id}/foto/{$theirPhoto->id}")->assertNotFound();
    $this->get("/area-clienti/richieste/{$mine->id}/foto/{$theirPhoto->id}")->assertNotFound();
    $this->get("/area-clienti/richieste/{$mine->id}/foto/{$removed->id}")->assertNotFound();
    $this->get("/area-clienti/richieste/{$mine->id}/foto/999999")->assertNotFound();
});

it('keeps the photos when the website\'s pipeline is unlinked', function (): void {
    $request = ChangeRequest::factory()->status(ChangeRequestStatus::Deployed)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    $attachment = Site::attach($request, ImageFixtures::jpeg());

    app(UnlinkRepository::class)->unlink($this->site['pipeline']->id);

    expect(ChangeRequestAttachment::query()->whereKey($attachment->id)->exists())->toBeTrue()
        ->and(Storage::disk('pipeline-uploads')->exists($attachment->stored_path))->toBeTrue();
});

it('deletes the photos of requests over for more than 90 days, keeps their rows, and does nothing more the second time', function (): void {
    $over = fn (ChangeRequestStatus $status, int $daysAgo): ChangeRequest => ChangeRequest::factory()->status($status)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'finished_at' => now()->subDays($daysAgo)]);
    $deployed = $over(ChangeRequestStatus::Deployed, 91);
    $rejected = $over(ChangeRequestStatus::Rejected, 200);
    $recent = $over(ChangeRequestStatus::Failed, 89);
    $open = ChangeRequest::factory()->previewReady()->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'updated_at' => now()->subDays(300)]);
    $gone = [Site::attach($deployed, ImageFixtures::jpeg(), 'a.jpg', 1), Site::attach($deployed, ImageFixtures::png(), 'b.png', 2), Site::attach($rejected, ImageFixtures::webp(), 'c.webp')];
    $kept = [Site::attach($recent, ImageFixtures::jpeg(), 'd.jpg'), Site::attach($open, ImageFixtures::jpeg(), 'e.jpg')];

    $job = app(JobRegistry::class)->get('pipeline.prune-attachments');
    expect($job)->toBeInstanceOf(PruneAttachments::class)
        ->and($job->defaultCron())->toBe('40 3 * * *');
    $result = $job->run();

    expect($result->succeeded())->toBeTrue()->and($result->output)->toBe('3 foto rimosse da 2 richieste.');
    foreach ($gone as $attachment) {
        expect($attachment->refresh()->removed_at?->toDateTimeString())->toBe('2026-03-15 10:00:00')
            ->and(Storage::disk('pipeline-uploads')->exists($attachment->stored_path))->toBeFalse();
    }
    foreach ($kept as $attachment) {
        expect($attachment->refresh()->removed_at)->toBeNull()
            ->and(Storage::disk('pipeline-uploads')->exists($attachment->stored_path))->toBeTrue();
    }
    $entry = collect(app(AuditTrail::class)->forSubject(Reference::to('change_request', $deployed->id)))->firstWhere('action', 'pipeline.change_request.attachments_removed');
    expect($entry?->details)->toBe(['files' => 2, 'keep_days' => 90])
        ->and($deployed->refresh()->toData()->attachments[0]->isRemoved())->toBeTrue();

    expect($job->run()->output)->toBe('0 foto rimosse da 0 richieste.');
});

it('deletes a removed photo that came back from a restore, and after a day a file no row knows', function (): void {
    $request = ChangeRequest::factory()->status(ChangeRequestStatus::Deployed)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'finished_at' => now()->subDays(100)]);
    $photo = Site::attach($request, ImageFixtures::jpeg(), 'a.jpg', 1, ['removed_at' => now()->subDays(10)]);
    $disk = Storage::disk('pipeline-uploads');
    $orphan = '01jq0000000000000000000000.jpg';
    $fresh = '01jq0000000000000000000001.png';
    $disk->put($orphan, 'died before its row');
    $disk->put($fresh, 'being uploaded now');
    $disk->put('README.txt', 'not an attachment: left alone');
    touch($disk->path($orphan), now()->subHours(25)->getTimestamp());
    touch($disk->path($fresh), now()->getTimestamp());

    $result = app(PruneAttachments::class)->run();

    expect($result->output)->toBe("0 foto rimosse da 0 richieste.\n1 foto già rimosse tornate sul disco, cancellate di nuovo.\n1 file senza richiesta cancellati.")
        ->and($disk->exists($photo->stored_path))->toBeFalse()
        ->and($disk->exists($orphan))->toBeFalse()
        ->and($disk->exists($fresh))->toBeTrue()
        ->and($disk->exists('README.txt'))->toBeTrue();
});
