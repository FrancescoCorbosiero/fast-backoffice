<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\Pec;

use RuntimeException;

/**
 * The app's PEC mailbox: one message out, the unread messages in. The
 * SMTP/IMAP implementation is the only thing that touches the network;
 * tests bind a fake.
 */
interface PecMailbox
{
    /**
     * Hosts and credentials are present (config/pec.php).
     */
    public function isConfigured(): bool;

    /**
     * @return string the Message-ID the message went out with (without angle brackets)
     *
     * @throws RuntimeException when the message doesn't leave
     */
    public function send(OutgoingPecMessage $message): string;

    /**
     * Messages not yet flagged as processed, oldest first.
     *
     * @return list<IncomingPecMessage>
     *
     * @throws RuntimeException when the mailbox can't be read
     */
    public function fetchUnprocessed(int $limit = 50): array;

    /**
     * Flags the message as processed. Never deletes.
     */
    public function markProcessed(IncomingPecMessage $message): void;
}
