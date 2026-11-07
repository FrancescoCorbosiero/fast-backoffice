<?php

declare(strict_types=1);

namespace App\Modules\Mail\Actions;

use App\Modules\Mail\Integrations\CampaignMessage;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Settings\Queries\GetMailSettings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The campaign as it will land in an inbox, sent once to an address the
 * owner picks, through the campaign mailer. No recipient row, no count:
 * a proof, not a send. The unsubscribe link in the footer leads nowhere
 * on purpose, since there is no recipient to unsubscribe.
 */
final readonly class SendTestEmail
{
    public function __construct(
        private GetMailSettings $settings,
        private MailFactory $mail,
        private Repository $config,
    ) {}

    /**
     * @throws ValidationException when the sender is not configured or the address is not one
     */
    public function send(int $campaignId, string $to): void
    {
        $settings = $this->settings->get();
        if (! $settings->isConfigured()) {
            throw ValidationException::withMessages(['from_address' => 'Imposta il mittente in Impostazioni › Campagne email.']);
        }

        $to = mb_strtolower(trim($to));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['to' => 'Indica un indirizzo email valido per la prova.']);
        }

        $campaign = Campaign::query()->findOrFail($campaignId);
        $appUrl = $this->config->get('app.url');
        $domain = parse_url(is_string($appUrl) ? $appUrl : '', PHP_URL_HOST);
        $mailer = $this->config->get('mail-campaigns.mailer');

        $message = new CampaignMessage(
            messageId: Str::lower(Str::ulid()->toBase32()).'.test-'.$campaign->id.'@'.(is_string($domain) && $domain !== '' ? $domain : 'fast-backoffice.local'),
            fromAddress: (string) $settings->fromAddress,
            fromName: $settings->fromName,
            replyToAddress: $settings->replyTo,
            recipientEmail: $to,
            subjectLine: '[PROVA] '.$campaign->subject,
            preheader: $campaign->preheader,
            bodyHtml: $campaign->body_html,
            unsubscribeUrl: route('mail.unsubscribe.show', ['token' => 'prova']),
        );

        $this->mail->mailer(is_string($mailer) && $mailer !== '' ? $mailer : null)->send($message);
    }
}
