<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Actions;

use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Repositories\Data\RepositoryData;
use App\Modules\Repositories\Data\RepositoryInput;
use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Modules\Repositories\Models\Repository;
use App\Modules\Repositories\Support\GitUrl;
use App\Modules\Websites\Queries\Websites;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a repository: a name, a URL (the provider follows
 * from it unless said otherwise), and the client and site it belongs to.
 */
final readonly class SaveRepository
{
    public function __construct(
        private ConnectionInterface $db,
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

    /**
     * A repository a website's pipeline is built from stays: the database
     * restricts the delete (Pipeline is a layer above, so this is the only
     * way to know) and the owner is told what to do first. Its own
     * transaction, so the refused statement leaves an enclosing one usable.
     *
     * @throws ValidationException
     */
    public function delete(int $repositoryId): void
    {
        try {
            $this->db->transaction(fn () => Repository::query()->whereKey($repositoryId)->delete());
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23503') {
                throw $exception;
            }

            throw ValidationException::withMessages(['repository' => 'Scollega prima il repository dalla pipeline del sito.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validate(RepositoryInput $input, ?int $ignoreId = null): void
    {
        $errors = [];

        if ($input->name === '') {
            $errors['name'] = 'Dai un nome al repository.';
        }
        if (GitUrl::host($input->url) === null) {
            $errors['url'] = 'Scrivi l\'indirizzo del repository: https://… oppure git@host:… per SSH.';
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
