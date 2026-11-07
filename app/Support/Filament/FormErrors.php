<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Use cases validate their input and throw ValidationException with the
 * field names of their input ("url", "contact_id"). Shown through a
 * Filament modal, those names must become the modal form's own state
 * paths ("mountedActions.0.data.url"), or the modal stays open with the
 * error shown nowhere. Catch the use case's exception in the action and
 * rethrow it through here:
 *
 *     ->using(function (array $data, Schema $schema): Repository {
 *         try {
 *             return …;
 *         } catch (ValidationException $exception) {
 *             FormErrors::onSchema($schema, $exception);
 *         }
 *     })
 */
final class FormErrors
{
    public static function onSchema(Schema $schema, ValidationException $exception): never
    {
        $prefix = $schema->getStatePath();
        $prefix = $prefix === null || $prefix === '' ? '' : $prefix.'.';
        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            $messages[$prefix.$key] = $errors;
        }

        throw ValidationException::withMessages($messages);
    }
}
