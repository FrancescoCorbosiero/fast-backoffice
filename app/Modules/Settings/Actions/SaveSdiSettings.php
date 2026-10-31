<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Settings\Data\SdiSettings;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Queries\GetSdiSettings;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

final readonly class SaveSdiSettings
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function save(SdiSettings $settings): void
    {
        $errors = [];
        if ($settings->pecAddress !== null && filter_var($settings->pecAddress, FILTER_VALIDATE_EMAIL) === false) {
            $errors['pec_address'] = 'Scrivi un indirizzo PEC valido.';
        }
        if (filter_var($settings->sdiAddress, FILTER_VALIDATE_EMAIL) === false) {
            $errors['sdi_address'] = 'Scrivi l\'indirizzo PEC di SdI.';
        }
        if ($settings->pecAddress === null && ($settings->autoSend || $settings->inboxEnabled)) {
            $errors['pec_address'] = 'Serve l\'indirizzo PEC dell\'app per inviare o ricevere.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->db->transaction(function () use ($settings): void {
            Setting::write(GetSdiSettings::KEY, $settings->toArray());
            $this->audit->record('settings.sdi.saved', details: $settings->toArray());
        });
    }
}
