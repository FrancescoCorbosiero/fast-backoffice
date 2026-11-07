<?php

declare(strict_types=1);

namespace App\Support\Filament;

/**
 * Lets a create page be opened with some fields already set from the
 * query string, so a link like /admin/websites/create?contact_id=12 from
 * the contact's own page lands on a form that already knows the client.
 *
 *     class CreateWebsite extends CreateRecord
 *     {
 *         use PrefillsFromQuery;
 *
 *         protected function prefillable(): array
 *         {
 *             return ['contact_id'];
 *         }
 *
 * Only the listed fields are read, only when they are positive integers;
 * everything else in the form keeps its defaults. prefilledUrl() builds
 * the link from the other side.
 */
trait PrefillsFromQuery
{
    /**
     * The fields the query string may set.
     *
     * @return list<string>
     */
    abstract protected function prefillable(): array;

    protected function fillForm(): void
    {
        parent::fillForm();

        $prefill = $this->prefillFromQuery();

        if ($prefill !== []) {
            $this->form->fillPartially($prefill, array_keys($prefill));
        }
    }

    /**
     * @return array<string, int>
     */
    protected function prefillFromQuery(): array
    {
        $prefill = [];

        foreach ($this->prefillable() as $field) {
            $value = request()->query($field);

            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                $prefill[$field] = (int) $value;
            }
        }

        return $prefill;
    }

    /**
     * A link to this page with the given fields prefilled; zero or null
     * values are left out.
     *
     * @param  array<string, int|null>  $fields
     */
    public static function prefilledUrl(array $fields): string
    {
        return static::getUrl(parameters: array_filter($fields, fn (?int $value): bool => $value !== null && $value > 0));
    }
}
