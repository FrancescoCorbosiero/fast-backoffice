<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Filament\Resources\Repositories\Pages;

use App\Modules\Repositories\Filament\Resources\Repositories\RepositoryActions;
use App\Modules\Repositories\Filament\Resources\Repositories\RepositoryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageRepositories extends ManageRecords
{
    protected static string $resource = RepositoryResource::class;

    /**
     * Reached as /admin/repositories?contact_id=12&website_id=3 from a
     * contact's or a site's page, the create modal already knows them.
     * Read once when the page loads: the modal opens in a later Livewire
     * request, which no longer carries the page's query string.
     *
     * @var array<string, int>
     */
    public array $prefill = [];

    public function mount(): void
    {
        parent::mount();

        $this->prefill = $this->prefillFromQuery();
    }

    protected function getHeaderActions(): array
    {
        return [
            RepositoryActions::create($this->prefill),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function prefillFromQuery(): array
    {
        $prefill = [];

        foreach (['contact_id', 'website_id'] as $field) {
            $value = request()->query($field);

            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                $prefill[$field] = (int) $value;
            }
        }

        return $prefill;
    }
}
