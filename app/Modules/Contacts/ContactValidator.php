<?php

declare(strict_types=1);

namespace App\Modules\Contacts;

use App\Kernel\Fiscal\CodiceFiscale;
use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Validation\ValidationException;

/**
 * The rules every contact write obeys, whatever the caller. Errors are
 * keyed by form field so screens can show them in place.
 */
final class ContactValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(ContactInput $input, ?int $ignoreId = null): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        if ($input->displayName() === '') {
            $errors[$input->kind->value === 'company' ? 'business_name' : 'last_name'][] = 'Serve un nome: la denominazione per un\'azienda, nome e cognome per una persona.';
        }

        if ($input->roles === []) {
            $errors['roles'][] = 'Scegli almeno un ruolo.';
        }

        if ($input->email !== null && filter_var($input->email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'Non è un indirizzo email valido.';
        }

        if ($input->pec !== null && filter_var($input->pec, FILTER_VALIDATE_EMAIL) === false) {
            $errors['pec'][] = 'Non è un indirizzo PEC valido.';
        }

        if ($input->vatNumber !== null && ! $input->vatNumber->isValid()) {
            $errors['vat_number'][] = $input->vatNumber->isItalian()
                ? 'La partita IVA non è valida (11 cifre, l\'ultima di controllo).'
                : "Il numero non ha il formato delle partite IVA di {$input->vatNumber->country}.";
        }

        if ($input->taxCode !== null && ($input->vatNumber === null || $input->vatNumber->isItalian()) && ! CodiceFiscale::isValid($input->taxCode)) {
            $errors['tax_code'][] = 'Il codice fiscale non è valido.';
        }

        if ($input->sdiCode !== null && preg_match('/^[A-Z0-9]{7}$/', $input->sdiCode) !== 1) {
            $errors['sdi_code'][] = 'Il codice destinatario è di 7 caratteri alfanumerici.';
        }

        if (preg_match('/^[A-Z]{2}$/', $input->address->country) !== 1) {
            $errors['country'][] = 'La nazione è un codice di due lettere.';
        }

        if ($input->vatNumber !== null && $this->taken($ignoreId, fn ($query) => $query->where('vat_country', $input->vatNumber->country)->where('vat_number', $input->vatNumber->number))) {
            $errors['vat_number'][] = 'Un altro contatto ha già questa partita IVA.';
        }

        if ($input->taxCode !== null && $this->taken($ignoreId, fn ($query) => $query->where('tax_code', $input->taxCode))) {
            $errors['tax_code'][] = 'Un altro contatto ha già questo codice fiscale.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  callable(\Illuminate\Database\Eloquent\Builder<Contact>): \Illuminate\Database\Eloquent\Builder<Contact>  $where
     */
    private function taken(?int $ignoreId, callable $where): bool
    {
        $query = $where(Contact::query()->live());

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }
}
