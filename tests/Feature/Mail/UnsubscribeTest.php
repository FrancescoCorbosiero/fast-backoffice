<?php

declare(strict_types=1);

use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Mail\Models\CampaignRecipient;

function recipientWithToken(string $token = 'tok-abc'): CampaignRecipient
{
    $contact = Contact::factory()->create(['email' => 'giulia@verdi.example']);
    $campaign = Campaign::factory()->create(['status' => 'sent']);

    return CampaignRecipient::query()->create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'email' => 'giulia@verdi.example', 'status' => RecipientStatus::Delivered, 'message_id' => 'm@studio.test', 'unsubscribe_token' => $token, 'sent_at' => now()]);
}

it('shows a confirmation page on GET and changes nothing', function (): void {
    $recipient = recipientWithToken();

    $this->get('/unsubscribe/tok-abc')->assertOk()->assertSee('giulia@verdi.example')->assertSee('non inviatemi più');

    expect(Contact::query()->findOrFail($recipient->contact_id)->toData()->isMailable())->toBeTrue()
        ->and($recipient->refresh()->unsubscribed_at)->toBeNull();
    $this->get('/unsubscribe/unknown')->assertNotFound();
});

it('unsubscribes on POST, one-click or from the page, idempotently, and never on GET', function (): void {
    $recipient = recipientWithToken();

    $this->post('/unsubscribe/tok-abc', ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Fatto');

    $contact = Contact::query()->findOrFail($recipient->contact_id)->toData();
    expect($contact->emailConsent)->toBeFalse()
        ->and($contact->emailSuppressionReason)->toBe('unsubscribe')
        ->and($contact->isMailable())->toBeFalse()
        ->and($recipient->refresh()->unsubscribed_at)->not->toBeNull()
        ->and(array_map(fn ($e) => $e->action, app(AuditTrail::class)->forSubject($recipient->campaign?->toData()->reference() ?? throw new RuntimeException)))->toContain('mail.unsubscribed');

    $this->post('/unsubscribe/tok-abc')->assertOk();
    $this->get('/unsubscribe/tok-abc')->assertOk()->assertSee('Fatto');
    $this->post('/unsubscribe/unknown')->assertNotFound();
});
