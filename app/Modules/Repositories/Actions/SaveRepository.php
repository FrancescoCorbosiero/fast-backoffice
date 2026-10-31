<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Actions;

use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Repositories\Data\RepositoryData;
use App\Modules\Repositories\Data\RepositoryInput;
use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Modules\Repositories\Models\Repository;
use App\Modules\Websites\Queries\Websites;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a repository: a name, a URL (the provider follows
 * from it unless said otherwise), and the client and site it belongs to.
 */
final readonly class SaveRepository
{
    public function __construct(
        private FindContact $contacts,
        private Websites $websites,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(RepositoryInput $input): RepositoryData
    {
        $this->validate($input);

        return Repository::query()->create($this->attributes($input))->toData();
    }

    /**
     * @throws ValidationException
     */
    public function update(int $repositoryId, RepositoryInput $input): RepositoryData
    {
        $this->validate($input, $repositoryId);
        $repository = Repository::query()->findOrFail($repositoryId);
        $repository->fill($this->attributes($input))->save();

        return $repository->toData();
    }

    public function delete(int $repositoryId): void
    {
        Repository::query()->whereKey($repositoryId)->delete();
    }

    /**
     * @throws ValidationException
     */
    private function validate(RepositoryInput $input, ?int $ignoreId = null): void
    {
        $errors = [];
        $parts = parse_url($input->url);

        if ($input->name === '') {
            $errors['name'] = 'Dai un nome al repository.';
        }
        if (! is_array($parts) || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https', 'ssh', 'git'], true)) {
            $errors['url'] = 'Scrivi l\'indirizzo del repository.';
        } elseif (Repository::query()->where('url', $input->url)->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $errors['url'] = 'Questo repository è già registrato.';
        }
        if ($input->contactId !== null && $this->contacts->byId($input->contactId) === null) {
            $errors['contact_id'] = 'Contatto sconosciuto.';
        }
        if ($input->websiteId !== null && $this->websites->find($input->websiteId) === null) {
            $errors['website_id'] = 'Sito sconosciuto.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(RepositoryInput $input): array
    {
        return [
            'name' => $input->name,
            'url' => $input->url,
            'provider' => $input->provider ?? RepositoryProvider::fromUrl($input->url),
            'contact_id' => $input->contactId,
            'website_id' => $input->websiteId,
            'default_branch' => $input->defaultBranch,
            'notes' => $input->notes,
        ];
    }
}
