<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Folds a duplicate into the contact that stays: blanks on the kept
 * contact are filled from the duplicate, roles are joined, consent is
 * kept if either gave it and suppression if either had it. The duplicate
 * is marked merged and disappears from every list; ContactsMerged lets
 * the other modules repoint their references in the same transaction.
 */
final readonly class MergeContacts
{
    private const array FILLABLE_BLANKS = [
        'first_name', 'last_name', 'business_name', 'email', 'phone', 'tax_code', 'sdi_code', 'pec',
        'street', 'street_number', 'postcode', 'city', 'province',
    ];

    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    public function merge(int $keepId, int $duplicateId): ContactData
    {
        if ($keepId === $duplicateId) {
            throw new InvalidArgumentException('A contact cannot be merged into itself.');
        }

        return $this->db->transaction(function () use ($keepId, $duplicateId): ContactData {
            $keep = Contact::query()->live()->lockForUpdate()->findOrFail($keepId);
            $duplicate = Contact::query()->live()->lockForUpdate()->findOrFail($duplicateId);

            foreach (self::FILLABLE_BLANKS as $column) {
                if ($keep->getAttribute($column) === null && $duplicate->getAttribute($column) !== null) {
                    $keep->setAttribute($column, $duplicate->getAttribute($column));
                }
            }

            if ($keep->vat_number === null && $duplicate->vat_number !== null) {
                $keep->vat_country = $duplicate->vat_country;
                $keep->vat_number = $duplicate->vat_number;
            }

            $keep->roles = array_values(array_unique([...$keep->roles, ...$duplicate->roles]));
            $keep->email_consent = $keep->email_consent || $duplicate->email_consent;

            if ($keep->email_suppressed_at === null && $duplicate->email_suppressed_at !== null) {
                $keep->email_suppressed_at = $duplicate->email_suppressed_at;
                $keep->email_suppression_reason = $duplicate->email_suppression_reason;
            }

            if ($duplicate->notes !== null) {
                $keep->notes = trim(($keep->notes ?? '')."\n\n".$duplicate->notes);
            }

            // The duplicate keeps its data for the record, but gives up the
            // identifiers the live uniqueness index protects.
            $duplicate->forceFill(['merged_into_id' => $keep->id, 'vat_country' => null, 'vat_number' => null, 'tax_code' => null])->save();
            $keep->save();

            $data = $keep->toData();
            $this->audit->record('contacts.contact.merged', $data->reference(), ['merged' => $duplicateId, 'merged_name' => $duplicate->display_name]);
            $this->events->dispatch(new ContactsMerged($keep->id, $duplicateId));

            return $data;
        });
    }
}
