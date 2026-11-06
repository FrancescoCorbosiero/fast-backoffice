<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Pipeline\Integrations\PortalSignInLink;
use App\Modules\Pipeline\Models\PortalSignIn;
use App\Modules\Pipeline\Support\PipelineConfig;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * A client asks to sign in: when the address belongs to a live client
 * contact, a one-time link goes out through the app's mailer, queued, so
 * the answer looks the same whether the address is known or not. Outside
 * production the mailer is "log" (.env.example): no mail reaches anyone.
 */
final readonly class RequestPortalSignIn
{
    public function __construct(
        private FindContact $contacts,
        private MailFactory $mail,
        private UrlGenerator $url,
        private PipelineConfig $config,
    ) {}

    public function request(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $contact = $this->contacts->byEmail($email);
        if ($contact === null || $contact->email === null || ! $contact->hasRole(ContactRole::Client)) {
            return;
        }

        $token = Str::random(48);
        $minutes = $this->config->portalLinkMinutes();
        PortalSignIn::query()->create([
            'contact_id' => $contact->id,
            'email' => $email,
            'token_hash' => PortalSignIn::hash($token),
            'expires_at' => Date::now()->addMinutes($minutes),
            'created_at' => Date::now(),
        ]);

        $link = $this->url->route('portal.sign-in.confirm', ['token' => $token]);
        $this->mail->mailer($this->config->portalMailer())->to($email)->queue(new PortalSignInLink($contact->displayName, $link, $minutes));
    }
}
