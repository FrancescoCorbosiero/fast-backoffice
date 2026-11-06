<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Pipeline\Auth\ClientUser;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\DecidedBy;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Integrations\PortalSignInLink;
use App\Modules\Pipeline\Jobs\CleanupRequest;
use App\Modules\Pipeline\Jobs\DeployStaticSiteRequest;
use App\Modules\Pipeline\Jobs\RunStaticSiteRequest;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\PortalSignIn;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pipeline\Site;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo('2026-03-15 10:00:00');
});

function clientUser(Contact $contact): ClientUser
{
    return new ClientUser($contact->id, $contact->display_name, $contact->email);
}

it('sends guests to the sign-in form and the owner\'s panel stays where it was', function (): void {
    $this->get('/area-clienti/')->assertRedirect('/area-clienti/accedi');
    $this->get('/area-clienti/richieste/nuova')->assertRedirect('/area-clienti/accedi');
    $this->get('/area-clienti/accedi')->assertOk()->assertSee('Inviami il link')->assertSee('/portal/manifest.webmanifest');
    $this->get('/admin')->assertRedirect('/admin/login');
    expect(file_exists(public_path('portal/manifest.webmanifest')))->toBeTrue()->and(file_exists(public_path('portal/icon.svg')))->toBeTrue();
});

it('signs a client in through an e-mailed link that is used once and expires', function (): void {
    Mail::fake();
    $site = Site::make(contact: ['email' => 'Maria@Bellavista.example']);

    $this->post('/area-clienti/accedi', ['email' => 'maria@bellavista.example'])->assertOk()->assertSee('Controlla la posta');

    $mailable = null;
    Mail::assertQueued(PortalSignInLink::class, function (PortalSignInLink $mail) use (&$mailable): bool {
        $mailable = $mail;

        return $mail->hasTo('maria@bellavista.example') && str_contains($mail->link, '/area-clienti/accedi/');
    });
    expect(PortalSignIn::query()->count())->toBe(1)
        ->and(PortalSignIn::query()->sole()->token_hash)->not->toContain(substr((string) $mailable?->link, -48));
    $mailable->assertSeeInHtml('Entra nell')->assertSeeInText($mailable->link);

    $link = (string) $mailable->link;
    $this->get($link)->assertOk()->assertSee('Entra')->assertDontSee('non vale più');
    expect(PortalSignIn::query()->sole()->used_at)->toBeNull();

    $this->post($link)->assertRedirect('/area-clienti');
    $this->assertAuthenticated('portal');
    $this->assertGuest('web');
    $this->get('/area-clienti/')->assertOk()->assertSee('Le tue richieste')->assertSee('Pasticceria Bellavista S.r.l.');

    $this->post('/area-clienti/esci')->assertRedirect('/area-clienti/accedi');
    $this->assertGuest('portal');
    $this->post($link)->assertOk()->assertSee('non vale più');
    $this->get($link)->assertOk()->assertSee('non vale più');

    $this->post('/area-clienti/accedi', ['email' => 'maria@bellavista.example']);
    $fresh = PortalSignIn::query()->latest('id')->first();
    $this->travel(16)->minutes();
    $token = null;
    Mail::assertQueued(PortalSignInLink::class, function (PortalSignInLink $mail) use (&$token, $fresh): bool {
        if (PortalSignIn::hash(substr($mail->link, -48)) === $fresh?->token_hash) {
            $token = substr($mail->link, -48);
        }

        return true;
    });
    $this->post('/area-clienti/accedi/'.$token)->assertOk()->assertSee('non vale più');
    $this->assertGuest('portal');
});

it('answers the same way for unknown, supplier or malformed addresses, and sends nothing', function (): void {
    Mail::fake();
    $supplier = Contact::factory()->supplier()->create(['email' => 'fornitore@example.test']);

    $this->post('/area-clienti/accedi', ['email' => 'nessuno@example.test'])->assertOk()->assertSee('Controlla la posta');
    $this->post('/area-clienti/accedi', ['email' => $supplier->email])->assertOk()->assertSee('Controlla la posta');
    $this->post('/area-clienti/accedi', ['email' => 'non-una-mail'])->assertOk()->assertSee('Controlla la posta');

    Mail::assertNothingQueued();
    expect(PortalSignIn::query()->count())->toBe(0);
    $this->get('/area-clienti/accedi/'.str_repeat('a', 48))->assertOk()->assertSee('non vale più');
    $this->get('/area-clienti/accedi/short')->assertNotFound();
});

it('throttles sign-in requests', function (): void {
    Mail::fake();
    for ($i = 0; $i < 5; $i++) {
        $this->post('/area-clienti/accedi', ['email' => "x{$i}@example.test"])->assertOk();
    }
    $this->post('/area-clienti/accedi', ['email' => 'x6@example.test'])->assertStatus(429);
});

it('lets a client submit a request for a ready website and follow it', function (): void {
    $site = Site::make();
    $notReady = Site::make(PipelineStatus::Onboarding, ['email' => 'other@example.test']);
    $this->actingAs(clientUser($site['contact']), 'portal');

    $this->get('/area-clienti/')->assertOk()->assertSee('Non hai ancora chiesto modifiche')->assertSee('Chiedi una modifica');
    $this->get('/area-clienti/richieste/nuova')->assertOk()->assertSee($site['website']->host)->assertSee('Invia la richiesta');

    $this->post('/area-clienti/richieste', ['website_id' => $site['website']->id, 'request_text' => '   '])
        ->assertRedirect('/area-clienti/richieste/nuova')->assertSessionHasErrors('request_text');
    $this->post('/area-clienti/richieste', ['website_id' => $notReady['website']->id, 'request_text' => 'Ciao'])
        ->assertRedirect('/area-clienti/richieste/nuova')->assertSessionHasErrors('website_id');
    Queue::assertNothingPushed();

    $response = $this->post('/area-clienti/richieste', ['website_id' => $site['website']->id, 'request_text' => 'Metti il nuovo logo in alto a sinistra.']);
    $request = ChangeRequest::query()->sole();
    $response->assertRedirect('/area-clienti/richieste/'.$request->id);
    expect($request->contact_id)->toBe($site['contact']->id)->and($request->status)->toBe(ChangeRequestStatus::Queued);
    Queue::assertPushed(RunStaticSiteRequest::class);

    $this->get('/area-clienti/richieste/'.$request->id)->assertOk()->assertSee('Metti il nuovo logo')->assertSee('In coda')->assertSee('Abbiamo ricevuto la richiesta');
    $this->get('/area-clienti/')->assertOk()->assertSee('Metti il nuovo logo')->assertSee('In coda');
});

it('shows the preview and takes the client\'s decision, only on their own requests', function (): void {
    $site = Site::make();
    $other = Site::make(contact: ['email' => 'other@example.test']);
    $mine = ChangeRequest::factory()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    $second = ChangeRequest::factory()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    $theirs = ChangeRequest::factory()->previewReady()->create(['contact_id' => $other['contact']->id, 'website_id' => $other['website']->id]);
    $this->actingAs(clientUser($site['contact']), 'portal');

    $this->get('/area-clienti/richieste/'.$mine->id)->assertOk()
        ->assertSee('Anteprima pronta')->assertSee($mine->preview_url)->assertSee('<iframe', false)->assertSee('Approva e pubblica')->assertSee('content/orari.md');
    $this->get('/area-clienti/richieste/'.$theirs->id)->assertNotFound();
    $this->post('/area-clienti/richieste/'.$theirs->id.'/approva')->assertNotFound();
    $this->get('/area-clienti/richieste/999999')->assertNotFound();

    $this->post('/area-clienti/richieste/'.$mine->id.'/approva')->assertRedirect('/area-clienti/richieste/'.$mine->id);
    expect($mine->refresh()->status)->toBe(ChangeRequestStatus::Approved)->and($mine->decided_by)->toBe(DecidedBy::Client);
    Queue::assertPushed(DeployStaticSiteRequest::class, fn (DeployStaticSiteRequest $job): bool => $job->changeRequestId === $mine->id);
    $this->get('/area-clienti/richieste/'.$mine->id)->assertOk()->assertSee('Pubblichiamo la modifica tra poco')->assertSee('Approvata da te')->assertDontSee('Approva e pubblica');

    $this->post('/area-clienti/richieste/'.$second->id.'/rifiuta', ['reason' => 'Il logo è troppo piccolo'])->assertRedirect('/area-clienti/richieste/'.$second->id);
    expect($second->refresh()->status)->toBe(ChangeRequestStatus::Rejected)->and($second->decision_reason)->toBe('Il logo è troppo piccolo');
    Queue::assertPushed(CleanupRequest::class);
    $this->get('/area-clienti/richieste/'.$second->id)->assertOk()->assertSee('Rifiutata da te')->assertSee('Il logo è troppo piccolo');

    $this->post('/area-clienti/richieste/'.$second->id.'/rifiuta')->assertRedirect('/area-clienti/richieste/'.$second->id);
    $this->get('/area-clienti/richieste/'.$second->id)->assertOk()->assertSee('non aspetta più una decisione');
    expect($theirs->refresh()->status)->toBe(ChangeRequestStatus::PreviewReady);
});

it('explains a failed request to the client in their own words, never with the raw error', function (): void {
    $site = Site::make();
    $failed = ChangeRequest::factory()->status(ChangeRequestStatus::Failed)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id, 'error' => 'git fetch fallito: ssh: connect to host git.internal port 22', 'client_error' => 'La richiesta è ambigua: quali orari?']);
    $crashed = ChangeRequest::factory()->status(ChangeRequestStatus::Failed)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id, 'error' => 'RuntimeException: /var/www/html/storage/app/pipeline/3 is busy', 'client_error' => null]);
    $this->actingAs(clientUser($site['contact']), 'portal');

    $this->get('/area-clienti/richieste/'.$failed->id)->assertOk()->assertSee('Fallita')->assertSee('quali orari?')->assertSee('Non siamo riusciti')->assertDontSee('git.internal');
    $this->get('/area-clienti/richieste/'.$crashed->id)->assertOk()->assertSee('Fallita')->assertSee('Non siamo riusciti')->assertDontSee('RuntimeException')->assertDontSee('/var/www');
});

it('lets a client see but never decide an onboarding of their own website', function (): void {
    Queue::fake();
    $site = Site::make(PipelineStatus::Onboarding);
    $onboarding = ChangeRequest::factory()->onboarding()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id, 'preview_url' => null]);
    $this->actingAs(clientUser($site['contact']), 'portal');

    $this->get('/area-clienti/richieste/'.$onboarding->id)->assertOk()->assertSee('Collegamento del sito')->assertDontSee('Approva e pubblica');
    $this->post('/area-clienti/richieste/'.$onboarding->id.'/approva')->assertNotFound();
    $this->post('/area-clienti/richieste/'.$onboarding->id.'/rifiuta')->assertNotFound();
    expect($onboarding->refresh()->status)->toBe(ChangeRequestStatus::PreviewReady);
    Queue::assertNothingPushed();
});

it('keeps a portal session away from the admin panel, and a sign-in of a contact who is no longer a client fails', function (): void {
    $site = Site::make();
    $this->actingAs(clientUser($site['contact']), 'portal');
    $this->get('/admin')->assertRedirect('/admin/login');

    $site['contact']->update(['roles' => ['supplier']]);
    expect(app(App\Modules\Pipeline\Auth\ClientUserProvider::class)->retrieveById('contact:'.$site['contact']->id))->toBeNull();
});
