<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\Pec;

use App\Modules\Invoicing\Data\SdiNotification;
use App\Modules\Invoicing\Enums\PecMessageKind;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use Throwable;

/**
 * Reads SdI's notification files (sdi.md §5) by local name, whatever the
 * namespace prefix: RicevutaConsegna, NotificaScarto,
 * NotificaMancataConsegna, AttestazioneTrasmissioneFattura, and the
 * metadata file MetadatiInvioFile that travels with a supplier invoice.
 */
final class SdiNotificationReader
{
    /**
     * @throws InvalidArgumentException when the file is not an SdI notification
     */
    public function read(string $xml): SdiNotification
    {
        $xpath = $this->xpath($xml);
        $root = $xpath->document->documentElement !== null ? $xpath->document->documentElement->localName : '';

        $kind = match ($root) {
            'RicevutaConsegna' => PecMessageKind::SdiDelivered,
            'NotificaScarto' => PecMessageKind::SdiRejected,
            'NotificaMancataConsegna' => PecMessageKind::SdiNotDelivered,
            'AttestazioneTrasmissioneFattura' => PecMessageKind::SdiAttestation,
            'MetadatiInvioFile', 'NotificaEsito', 'NotificaDecorrenzaTermini', 'NotificaFileNonRecapitabile' => PecMessageKind::SdiOther,
            default => throw new InvalidArgumentException("\"{$root}\" non è una notifica di SdI."),
        };

        $errors = [];
        foreach ($xpath->query('//*[local-name()="ListaErrori"]/*[local-name()="Errore"]') ?: [] as $node) {
            if (! $node instanceof DOMNode) {
                continue;
            }
            $errors[] = [
                'code' => trim($this->text($xpath, 'Codice', $node)),
                'description' => trim($this->text($xpath, 'Descrizione', $node)),
                'hint' => trim($this->text($xpath, 'Suggerimento', $node)) ?: null,
            ];
        }

        return new SdiNotification(
            kind: $kind,
            rootElement: $root,
            sdiIdentifier: $this->text($xpath, 'IdentificativoSdI') ?: null,
            fileName: $this->text($xpath, 'NomeFile') ?: null,
            receivedAt: $this->date($this->text($xpath, 'DataOraRicezione')),
            deliveredAt: $this->date($this->text($xpath, 'DataOraConsegna')),
            description: $this->text($xpath, 'Descrizione', null, true) ?: null,
            errors: $errors,
            note: $this->text($xpath, 'Note') ?: null,
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private function xpath(string $xml): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                throw new InvalidArgumentException('Il file non è XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function text(DOMXPath $xpath, string $name, ?DOMNode $context = null, bool $topLevelOnly = false): string
    {
        $query = $topLevelOnly ? '/*/*[local-name()="'.$name.'"]' : ($context === null ? '//*[local-name()="'.$name.'"]' : './*[local-name()="'.$name.'"]');
        $value = $xpath->evaluate('string('.$query.')', $context);

        return is_string($value) ? trim($value) : '';
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
