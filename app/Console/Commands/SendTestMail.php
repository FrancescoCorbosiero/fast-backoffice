<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Config\Repository as Config;
use Illuminate\Console\Command;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Message;
use Throwable;

/**
 * Sends one email now, not through the queue, so the mail provider's answer
 * shows up here instead of in a failed job: the check after setting up or
 * changing the mail settings ("bin/prod mail-test" in production,
 * docs/runbook.md).
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test
        {to : The address to send the test email to}
        {--mailer= : A mailer from config/mail.php (the default mailer when omitted)}';

    protected $description = 'Send a test email now and show what the mail provider answered';

    public function handle(MailFactory $mail, Config $config): int
    {
        $to = trim((string) $this->argument('to'));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("\"{$to}\" is not an email address.");

            return self::FAILURE;
        }

        $mailer = $this->option('mailer');
        $mailer = is_string($mailer) && $mailer !== '' ? $mailer : $config->string('mail.default');
        $from = $config->string('mail.from.address');
        $app = $config->string('app.name');

        try {
            $mail->mailer($mailer)->raw(
                "Questa è un'email di prova di {$app}, inviata con il mailer {$mailer}: se la leggi, la posta in uscita funziona.",
                fn (Message $message): Message => $message->to($to)->subject("Email di prova da {$app}"),
            );
        } catch (Throwable $exception) {
            $this->error("The {$mailer} mailer could not send from {$from} to {$to}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Sent through the {$mailer} mailer, from {$from} to {$to}.");
        $this->line('Not in the inbox within a few minutes? Look in the spam folder, then in the provider\'s suppression list.');

        return self::SUCCESS;
    }
}
