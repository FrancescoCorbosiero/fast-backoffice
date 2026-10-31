<?php

declare(strict_types=1);

namespace App\Modules\Mail\Actions;

use App\Modules\Mail\Data\CampaignData;
use App\Modules\Mail\Data\CampaignInput;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Models\Campaign;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Writes a campaign while it is still a draft (or scheduled and not started).
 */
final readonly class SaveCampaign
{
    /**
     * @throws ValidationException
     */
    public function create(CampaignInput $input): CampaignData
    {
        self::validate($input);

        return Campaign::query()->create([...self::attributes($input), 'status' => CampaignStatus::Draft])->toData();
    }

    /**
     * @throws ValidationException
     * @throws LogicException when the campaign already went out
     */
    public function update(int $campaignId, CampaignInput $input): CampaignData
    {
        self::validate($input);
        $campaign = Campaign::query()->findOrFail($campaignId);

        if (! $campaign->status->isEditable()) {
            throw new LogicException("Campaign {$campaignId} is {$campaign->status->value}: what went out doesn't change.");
        }

        $campaign->fill(self::attributes($input))->save();

        return $campaign->toData();
    }

    /**
     * @throws LogicException unless the campaign is a draft
     */
    public function delete(int $campaignId): void
    {
        $campaign = Campaign::query()->findOrFail($campaignId);
        if ($campaign->status !== CampaignStatus::Draft) {
            throw new LogicException("Campaign {$campaignId} is {$campaign->status->value}: only drafts are deleted.");
        }
        $campaign->delete();
    }

    /**
     * @throws ValidationException
     */
    public static function validate(CampaignInput $input): void
    {
        $errors = [];
        if ($input->name === '') {
            $errors['name'] = 'Dai un nome alla campagna.';
        }
        if ($input->subject === '') {
            $errors['subject'] = 'Serve un oggetto.';
        }
        if (trim(strip_tags($input->bodyHtml)) === '') {
            $errors['body_html'] = 'Scrivi il contenuto.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function attributes(CampaignInput $input): array
    {
        return [
            'name' => $input->name,
            'subject' => $input->subject,
            'preheader' => $input->preheader,
            'body_html' => $input->bodyHtml,
            'audience_roles' => array_map(fn ($role): string => $role->value, $input->audienceRoles),
        ];
    }
}
