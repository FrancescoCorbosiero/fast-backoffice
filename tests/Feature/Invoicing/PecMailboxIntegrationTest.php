<?php

declare(strict_types=1);

use App\Modules\Invoicing\Integrations\Pec\IncomingPecMessage;
use App\Modules\Invoicing\Integrations\Pec\OutgoingPecMessage;
use App\Modules\Invoicing\Integrations\Pec\PecEnvelopeParser;
use App\Modules\Invoicing\Integrations\Pec\SmtpImapPecMailbox;

/**
 * The real SMTP/IMAP adapter against a local mail server (GreenMail in CI,
 * sdi.md §10). Skipped when none is reachable: set PEC_TEST_HOST to run it.
 */
it('sends a message over SMTP, reads it back over IMAP, flags it and never sees it again', function (): void {
    $host = getenv('PEC_TEST_HOST');
    if (! is_string($host) || $host === '') {
        $this->markTestSkipped('No local mail server: set PEC_TEST_HOST (and PEC_TEST_SMTP_PORT, PEC_TEST_IMAP_PORT) to run the integration test.');
    }

    config()->set('pec.smtp', ['host' => $host, 'port' => (int) (getenv('PEC_TEST_SMTP_PORT') ?: 3025), 'encryption' => 'none', 'username' => 'pec@studio.test', 'password' => 'secret']);
    config()->set('pec.imap', ['host' => $host, 'port' => (int) (getenv('PEC_TEST_IMAP_PORT') ?: 3143), 'encryption' => 'none', 'username' => 'pec@studio.test', 'password' => 'secret', 'folder' => 'INBOX', 'validate_cert' => false]);

    $mailbox = app(SmtpImapPecMailbox::class);
    expect($mailbox->isConfigured())->toBeTrue();

    $marker = 'IT01234567897_'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)).'.xml';
    $messageId = $mailbox->send(new OutgoingPecMessage('pec@studio.test', 'pec@studio.test', $marker, 'In allegato il file.', $marker, '<?xml version="1.0"?><FatturaElettronica/>'));
    expect($messageId)->toContain('@');

    $found = null;
    for ($attempt = 0; $attempt < 20 && $found === null; $attempt++) {
        foreach ($mailbox->fetchUnprocessed() as $message) {
            $envelope = app(PecEnvelopeParser::class)->parse($message->raw);
            if ($envelope->subject === $marker) {
                $found = $message;
                expect($envelope->messageId)->toBe($messageId)
                    ->and(array_map(fn ($a) => $a->name, $envelope->attachments))->toBe([$marker]);
            } else {
                $mailbox->markProcessed($message);
            }
        }
        if ($found === null) {
            usleep(250_000);
        }
    }

    expect($found)->toBeInstanceOf(IncomingPecMessage::class);
    $mailbox->markProcessed($found);

    $remaining = array_filter($mailbox->fetchUnprocessed(), fn (IncomingPecMessage $m): bool => $m->uid === $found->uid);
    expect($remaining)->toBe([]);
});
