<?php

declare(strict_types=1);

namespace App\Modules\Mail\Integrations;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * One campaign email to one recipient: the sender from settings, our own
 * Message-ID (so SES events find their way back), and RFC 8058 one-click
 * unsubscribe headers pointing at the POST endpoint.
 */
final class CampaignMessage extends Mailable
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $fromAddress,
        public readonly ?string $fromName,
        public readonly ?string $replyToAddress,
        public readonly string $recipientEmail,
        public readonly string $subjectLine,
        public readonly ?string $preheader,
        public readonly string $bodyHtml,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            to: [new Address($this->recipientEmail)],
            replyTo: $this->replyToAddress === null ? [] : [new Address($this->replyToAddress)],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.campaign',
            text: 'mail.campaign-text',
            with: ['preheader' => $this->preheader, 'bodyHtml' => $this->bodyHtml, 'unsubscribeUrl' => $this->unsubscribeUrl],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            text: [
                'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                'Precedence' => 'bulk',
            ],
        );
    }
}
