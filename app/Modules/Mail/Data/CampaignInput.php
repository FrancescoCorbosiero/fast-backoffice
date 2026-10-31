<?php

declare(strict_types=1);

namespace App\Modules\Mail\Data;

use App\Modules\Contacts\Enums\ContactRole;
use App\Support\Shape;

final readonly class CampaignInput
{
    /** @var list<ContactRole> */
    public array $audienceRoles;

    /**
     * @param  list<ContactRole>  $audienceRoles  empty: every mailable contact
     */
    public function __construct(
        public string $name,
        public string $subject,
        public string $bodyHtml,
        public ?string $preheader = null,
        array $audienceRoles = [],
    ) {
        $unique = [];
        foreach ($audienceRoles as $role) {
            $unique[$role->value] = $role;
        }
        $this->audienceRoles = array_values($unique);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $roles = [];
        $raw = $data['audience_roles'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            $role = is_string($value) ? ContactRole::tryFrom($value) : null;
            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return new self(
            name: trim(Shape::string($data, 'name')),
            subject: trim(Shape::string($data, 'subject')),
            bodyHtml: Shape::string($data, 'body_html'),
            preheader: Shape::nullableString($data, 'preheader'),
            audienceRoles: $roles,
        );
    }
}
