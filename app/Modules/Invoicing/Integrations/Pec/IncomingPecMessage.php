<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\Pec;

/**
 * A message as the mailbox holds it: its identifier there, and the raw
 * RFC 5322 bytes. Parsing happens in PecEnvelopeParser.
 */
final readonly class IncomingPecMessage
{
    public function __construct(
        public string $uid,
        public string $raw,
    ) {}
}
