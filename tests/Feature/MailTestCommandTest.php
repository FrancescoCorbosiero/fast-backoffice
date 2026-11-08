<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * @return list<SentMessage>
 */
function sentThroughArrayMailer(): array
{
    $transport = Mail::mailer('array')->getSymfonyTransport();

    return method_exists($transport, 'messages') ? $transport->messages()->values()->all() : [];
}

it('sends a test email now, not queued, through the default mailer', function (): void {
    config(['mail.default' => 'array', 'mail.from.address' => 'noreply@studio.test']);

    $this->artisan('mail:test', ['to' => 'owner@studio.test'])
        ->expectsOutputToContain('Sent through the array mailer, from noreply@studio.test to owner@studio.test.')
        ->assertSuccessful();

    $sent = sentThroughArrayMailer();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe('owner@studio.test');
});

it('shows what the mail provider answered when it refuses the email', function (): void {
    Mail::extend('refusing', fn (): AbstractTransport => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Email address is not verified. The following identities failed the check in region EU-NORTH-1: noreply@example.it');
        }

        public function __toString(): string
        {
            return 'refusing://';
        }
    });
    config(['mail.mailers.refusing' => ['transport' => 'refusing'], 'mail.from.address' => 'noreply@example.it']);

    $this->artisan('mail:test', ['to' => 'owner@studio.test', '--mailer' => 'refusing'])
        ->expectsOutputToContain('The refusing mailer could not send from noreply@example.it to owner@studio.test: Email address is not verified.')
        ->assertFailed();
});

it('says so when the mailer does not exist', function (): void {
    $this->artisan('mail:test', ['to' => 'owner@studio.test', '--mailer' => 'nope'])
        ->expectsOutputToContain('Mailer [nope] is not defined.')
        ->assertFailed();
});

it('sends nothing to something that is not an address', function (): void {
    config(['mail.default' => 'array']);

    $this->artisan('mail:test', ['to' => 'not-an-address'])
        ->expectsOutputToContain('"not-an-address" is not an email address.')
        ->assertFailed();

    expect(sentThroughArrayMailer())->toBe([]);
});
