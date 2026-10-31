<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\FatturaPa;

use DOMDocument;
use LibXMLError;

/**
 * Validates an XML against the official schema bundled in
 * resources/fatturapa (sdi.md §4).
 */
final class FatturaPaValidator
{
    public const string SCHEMA = 'fatturapa/Schema_del_file_xml_FatturaPA_v1.2.3.xsd';

    /**
     * @return list<string> the schema errors, empty when the XML is valid
     */
    public function errors(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;

            if (! $document->loadXML($xml)) {
                return array_map($this->format(...), libxml_get_errors());
            }

            if ($document->schemaValidate(resource_path(self::SCHEMA))) {
                return [];
            }

            return array_map($this->format(...), libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function isValid(string $xml): bool
    {
        return $this->errors($xml) === [];
    }

    private function format(LibXMLError $error): string
    {
        return sprintf('line %d: %s', $error->line, trim($error->message));
    }
}
