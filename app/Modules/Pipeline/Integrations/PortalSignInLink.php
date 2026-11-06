<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Integrations;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The sign-in mail for the client portal: one link, valid for a few
 * minutes, no password.
 */
final class PortalSignInLink extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $link,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Il tuo link per entrare nell\'area clienti');
    }

    public function content(): Content
    {
        return new Content(view: 'pipeline.mail.sign-in', text: 'pipeline.mail.sign-in-text', with: ['name' => $this->name, 'link' => $this->link, 'minutes' => $this->minutes]);
    }
}
