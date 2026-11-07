<?php

declare(strict_types=1);

namespace App\Modules\Websites\Filament\Resources\Websites\Pages;

use App\Modules\Websites\Actions\CreateWebsite as CreateWebsiteAction;
use App\Modules\Websites\Data\WebsiteInput;
use App\Modules\Websites\Filament\Resources\Websites\WebsiteResource;
use App\Modules\Websites\Models\Website;
use App\Support\Filament\PrefillsFromQuery;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWebsite extends CreateRecord
{
    use PrefillsFromQuery;

    protected static string $resource = WebsiteResource::class;

    /**
     * @return list<string>
     */
    protected function prefillable(): array
    {
        return ['contact_id'];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $website = app(CreateWebsiteAction::class)->create(WebsiteInput::fromArray($data));

        return Website::query()->findOrFail($website->id);
    }

    protected function getRedirectUrl(): string
    {
        return WebsiteResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
