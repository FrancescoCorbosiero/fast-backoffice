<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Settings\Data\MailSettings;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Queries\GetMailSettings;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

final readonly class SaveMailSettings
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function save(MailSettings $settings): void
    {
        $errors = [];
        if ($settings->fromAddress !== null && filter_var($settings->fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            $errors['from_address'] = 'Scrivi un indirizzo mittente valido.';
        }
        if ($settings->replyTo !== null && filter_var($settings->replyTo, FILTER_VALIDATE_EMAIL) === false) {
            $errors['reply_to'] = 'Scrivi un indirizzo di risposta valido.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->db->transaction(function () use ($settings): void {
            Setting::write(GetMailSettings::KEY, $settings->toArray());
            $this->audit->record('settings.mail.saved', details: $settings->toArray());
        });
    }
}
