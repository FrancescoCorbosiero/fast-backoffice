<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Models;

use App\Kernel\Fiscal\VatNumber;
use App\Modules\Contacts\Data\Address;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Database\Factories\ContactFactory;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property ContactKind $kind
 * @property string $display_name
 * @property ?string $first_name
 * @property ?string $last_name
 * @property ?string $business_name
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $vat_country
 * @property ?string $vat_number
 * @property ?string $tax_code
 * @property ?string $sdi_code
 * @property ?string $pec
 * @property ?string $street
 * @property ?string $street_number
 * @property ?string $postcode
 * @property ?string $city
 * @property ?string $province
 * @property string $country
 * @property list<string> $roles
 * @property bool $email_consent
 * @property ?CarbonImmutable $email_suppressed_at
 * @property ?string $email_suppression_reason
 * @property ?string $notes
 * @property ?int $merged_into_id
 */
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ContactKind::class,
            'roles' => 'array',
            'email_consent' => 'boolean',
            'email_suppressed_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    /**
     * Contacts that haven't been merged into another.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeWithRole(Builder $query, ContactRole $role): Builder
    {
        return $query->whereJsonContains('roles', $role->value);
    }

    /**
     * @return list<ContactRole>
     */
    public function roleEnums(): array
    {
        $roles = [];
        foreach ($this->roles as $value) {
            $role = ContactRole::tryFrom($value);
            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    public function toData(): ContactData
    {
        return new ContactData(
            id: $this->id,
            kind: $this->kind,
            displayName: $this->display_name,
            firstName: $this->first_name,
            lastName: $this->last_name,
            businessName: $this->business_name,
            email: $this->email,
            phone: $this->phone,
            vatNumber: $this->vat_number === null ? null : new VatNumber($this->vat_country ?? 'IT', $this->vat_number),
            taxCode: $this->tax_code,
            sdiCode: $this->sdi_code,
            pec: $this->pec,
            address: new Address($this->street, $this->street_number, $this->postcode, $this->city, $this->province, $this->country),
            roles: $this->roleEnums(),
            emailConsent: $this->email_consent,
            emailSuppressedAt: $this->email_suppressed_at,
            emailSuppressionReason: $this->email_suppression_reason,
            notes: $this->notes,
        );
    }
}
