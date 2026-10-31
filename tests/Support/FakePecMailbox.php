<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Invoicing\Integrations\Pec\IncomingPecMessage;
use App\Modules\Invoicing\Integrations\Pec\OutgoingPecMessage;
use App\Modules\Invoicing\Integrations\Pec\PecMailbox;
use RuntimeException;

/**
 * The mailbox in memory: what went out, what waits to be read, what was flagged.
 */
final class FakePecMailbox implements PecMailbox
{
    /** @var list<OutgoingPecMessage> */
    public array $sent = [];

    /** @var list<IncomingPecMessage> */
    public array $incoming = [];

    /** @var list<string> */
    public array $processed = [];

    public bool $configured = true;

    public ?string $failWith = null;

    private int $counter = 0;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(OutgoingPecMessage $message): string
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $this->sent[] = $message;
        $this->counter++;

        return "fake-{$this->counter}@studio.test";
    }

    public function fetchUnprocessed(int $limit = 50): array
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        return array_slice(array_values(array_filter($this->incoming, fn (IncomingPecMessage $m): bool => ! in_array($m->uid, $this->processed, true))), 0, $limit);
    }

    public function markProcessed(IncomingPecMessage $message): void
    {
        $this->processed[] = $message->uid;
    }

    public function receive(string $raw): IncomingPecMessage
    {
        $message = new IncomingPecMessage('uid-'.(count($this->incoming) + 1), $raw);
        $this->incoming[] = $message;

        return $message;
    }
}
