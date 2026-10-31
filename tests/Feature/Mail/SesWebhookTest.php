<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Events\RecipientBounced;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Mail\Models\CampaignRecipient;
use App\Modules\Mail\Models\MailEvent;
use App\Modules\Settings\Actions\SaveMailSettings;
use App\Modules\Settings\Data\MailSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\SnsFixtures;

/**
 * @param  array<string, mixed>  $message
 */
function postSns(array $message): TestResponse
{
    return test()->call('POST', '/webhooks/ses', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_X_AMZ_SNS_MESSAGE_TYPE' => (string) $message['Type']], json_encode($message, JSON_THROW_ON_ERROR));
}

function sentRecipient(string $email, string $messageId): CampaignRecipient
{
    $contact = Contact::factory()->create(['email' => $email]);
    $campaign = Campaign::factory()->create(['status' => 'sending', 'started_at' => '2026-03-15 10:00:00']);

    return CampaignRecipient::query()->create([
        'campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'email' => $email, 'status' => RecipientStatus::Sent,
        'attempts' => 1, 'message_id' => $messageId, 'ses_message_id' => 'ses-'.$messageId, 'unsubscribe_token' => bin2hex(random_bytes(20)), 'sent_at' => '2026-03-15 10:00:00',
    ]);
}

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:10:00');
    config()->set('mail-campaigns.sns_topic_arn', SnsFixtures::TOPIC);
    app(SaveMailSettings::class)->save(new MailSettings('studio@example.test', transientBouncesBeforeSuppression: 2));
    Http::fake([SnsFixtures::CERT_URL => Http::response(SnsFixtures::certificate(), 200), 'sns.eu-west-1.amazonaws.com/*' => Http::response('ok', 200)]);
});

it('confirms the subscription by visiting the signed SubscribeURL', function (): void {
    $message = SnsFixtures::sign(['Type' => 'SubscriptionConfirmation', 'MessageId' => 'sub-1', 'Token' => 'tok', 'TopicArn' => SnsFixtures::TOPIC, 'Message' => 'You have chosen to subscribe', 'SubscribeURL' => 'https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription&Token=tok', 'Timestamp' => '2026-03-15T10:05:00.000Z']);

    postSns($message)->assertOk()->assertJsonPath('outcome', 'confirmed');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'Action=ConfirmSubscription'));
    expect(MailEvent::query()->where('sns_message_id', 'sub-1')->exists())->toBeTrue();
});

it('refuses a bad signature, a certificate from elsewhere, and another topic', function (): void {
    $recipient = sentRecipient('a@example.test', 'msg-1@studio.test');
    $good = SnsFixtures::notification(SnsFixtures::sesEvent('Delivery', 'msg-1@studio.test', ['a@example.test']));

    postSns([...$good, 'Signature' => base64_encode('nope')])->assertStatus(401);
    postSns([...$good, 'SigningCertURL' => 'https://evil.example/cert.pem'])->assertStatus(401);
    postSns([...$good, 'TopicArn' => 'arn:aws:sns:eu-west-1:999:other'])->assertStatus(401);
    postSns([...$good, 'Message' => '{"tampered":true}'])->assertStatus(401);
    $this->call('POST', '/webhooks/ses', [], [], [], [], 'not json')->assertStatus(400);

    expect($recipient->refresh()->status)->toBe(RecipientStatus::Sent)->and(MailEvent::query()->count())->toBe(0);

    postSns($good)->assertOk()->assertJsonPath('outcome', 'processed');
    expect($recipient->refresh()->status)->toBe(RecipientStatus::Delivered);
});

it('handles each SNS message once, even when delivered twice', function (): void {
    $recipient = sentRecipient('a@example.test', 'msg-1@studio.test');
    $message = SnsFixtures::notification(SnsFixtures::sesEvent('Bounce', 'msg-1@studio.test', ['a@example.test'], 'Permanent'), 'dup-1');
    Event::fake([RecipientBounced::class]);

    postSns($message)->assertOk()->assertJsonPath('outcome', 'processed');
    postSns($message)->assertOk()->assertJsonPath('outcome', 'duplicate');

    Event::assertDispatchedTimes(RecipientBounced::class, 1);
    expect(MailEvent::query()->count())->toBe(1)->and($recipient->refresh()->status)->toBe(RecipientStatus::Bounced);
});

it('applies the bounce policy: permanent and complaint suppress at once, temporary only after repeated failures', function (): void {
    $hard = sentRecipient('hard@example.test', 'hard@studio.test');
    $spam = sentRecipient('spam@example.test', 'spam@studio.test');
    $soft = sentRecipient('soft@example.test', 'soft-1@studio.test');

    postSns(SnsFixtures::notification(SnsFixtures::sesEvent('Bounce', 'hard@studio.test', ['hard@example.test'], 'Permanent')))->assertOk();
    postSns(SnsFixtures::notification(SnsFixtures::sesEvent('Complaint', 'spam@studio.test', ['spam@example.test'])))->assertOk();
    postSns(SnsFixtures::notification(SnsFixtures::sesEvent('Bounce', 'soft-1@studio.test', ['soft@example.test'], 'Transient')))->assertOk();

    expect($hard->refresh()->status)->toBe(RecipientStatus::Bounced)
        ->and(Contact::query()->findOrFail($hard->contact_id)->toData()->emailSuppressionReason)->toBe('bounce')
        ->and($spam->refresh()->status)->toBe(RecipientStatus::Complained)
        ->and(Contact::query()->findOrFail($spam->contact_id)->toData()->emailSuppressionReason)->toBe('complaint')
        ->and($soft->refresh()->status)->toBe(RecipientStatus::SoftBounced)
        ->and(Contact::query()->findOrFail($soft->contact_id)->toData()->isEmailSuppressed())->toBeFalse();

    // A second campaign, a second temporary bounce for the same contact: the threshold (2) is reached.
    $campaign = Campaign::factory()->create(['status' => 'sending']);
    CampaignRecipient::query()->create(['campaign_id' => $campaign->id, 'contact_id' => $soft->contact_id, 'email' => 'soft@example.test', 'status' => RecipientStatus::Sent, 'message_id' => 'soft-2@studio.test', 'unsubscribe_token' => bin2hex(random_bytes(20)), 'sent_at' => now()]);
    postSns(SnsFixtures::notification(SnsFixtures::sesEvent('Bounce', 'soft-2@studio.test', ['soft@example.test'], 'Transient')))->assertOk();
    expect(Contact::query()->findOrFail($soft->contact_id)->toData()->emailSuppressionReason)->toBe('bounce');

    // A delivery after a temporary bounce: delivered after all. Unknown message: kept, ignored.
    postSns(SnsFixtures::notification(SnsFixtures::sesEvent('Delivery', 'soft-1@studio.test', ['soft@example.test'])))->assertOk();
    expect($soft->refresh()->status)->toBe(RecipientStatus::Delivered);
    postSns(SnsFixtures::notification(SnsFixtures::sesEvent('Delivery', 'unknown@studio.test', ['x@example.test'])))->assertOk()->assertJsonPath('outcome', 'ignored');
    postSns(SnsFixtures::notification(['hello' => 'world']))->assertOk()->assertJsonPath('outcome', 'ignored');

    // Matched by SES's own message id when our header is missing (version 1 signature too).
    $byId = sentRecipient('byid@example.test', 'byid@studio.test');
    $event = SnsFixtures::sesEvent('Delivery', 'other@studio.test', ['byid@example.test'], sesMessageId: 'ses-byid@studio.test');
    unset($event['mail']['commonHeaders']);
    $message = SnsFixtures::sign(['Type' => 'Notification', 'MessageId' => 'v1-1', 'TopicArn' => SnsFixtures::TOPIC, 'Message' => json_encode($event, JSON_THROW_ON_ERROR), 'Timestamp' => '2026-03-15T10:05:00.000Z'], 1);
    postSns($message)->assertOk()->assertJsonPath('outcome', 'processed');
    expect($byId->refresh()->status)->toBe(RecipientStatus::Delivered);
});
