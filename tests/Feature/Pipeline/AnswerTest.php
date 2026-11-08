<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Pipeline\Auth\ClientUser;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Jobs\RunStaticSiteRequest;
use App\Modules\Pipeline\Models\ChangeRequest;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pipeline\Site;
use Tests\Support\ImageFixtures;

beforeEach(function (): void {
    Queue::fake();
    $this->site = Site::make();
    $this->request = ChangeRequest::factory()->status(ChangeRequestStatus::NeedsInput)->create([
        'contact_id' => $this->site['contact']->id,
        'website_id' => $this->site['website']->id,
        'request_text' => 'Aggiungi nelle foto polaroid queste foto',
        'conversation' => [[
            'by' => 'agent',
            'text' => 'Non ho modificato niente: ci sono più gruppi di polaroid.',
            'at' => '2026-11-08T23:10:00+01:00',
            'questions' => [
                ['question' => 'Dove vuoi le foto?', 'options' => ['La bacheca della home', 'Il ventaglio di Chi sono']],
                ['question' => 'Le aggiungo o ne sostituisco?', 'options' => ['Aggiungile', 'Sostituisci le ultime due']],
            ],
        ]],
    ]);
    Site::attach($this->request, ImageFixtures::jpeg());
    $this->actingAs(new ClientUser($this->site['contact']->id, $this->site['contact']->display_name, $this->site['contact']->email), 'portal');
});

it('shows the agent\'s questions as choices, with a box for anything else', function (): void {
    $this->get("/area-clienti/richieste/{$this->request->id}")->assertOk()
        ->assertSee('Serve una risposta')->assertSee('Ci serve una tua risposta')
        ->assertSee('Non ho modificato niente: ci sono più gruppi di polaroid.')
        ->assertSee('Dove vuoi le foto?')->assertSee('name="choices[0]" value="La bacheca della home"', false)
        ->assertSee('name="choices[1]" value="Sostituisci le ultime due"', false)
        ->assertSee('Altro da aggiungere')->assertSee('non serve allegarle di nuovo');
});

it('queues the same request again with the answer, keeping its photos', function (): void {
    $this->post("/area-clienti/richieste/{$this->request->id}/rispondi", [
        'choices' => [0 => 'La bacheca della home', 1 => 'una risposta che non era tra le opzioni'],
        'note' => 'Scritte: "nel prato" e "io e te".',
    ])->assertRedirect("/area-clienti/richieste/{$this->request->id}");

    $request = $this->request->refresh();
    $last = $request->toData()->conversation[1];
    expect($request->status)->toBe(ChangeRequestStatus::Queued)
        ->and($request->attachments)->toHaveCount(1)
        ->and($last->isAgent())->toBeFalse()
        ->and($last->text)->toBe("Dove vuoi le foto? → La bacheca della home\nScritte: \"nel prato\" e \"io e te\".");
    Queue::assertPushed(RunStaticSiteRequest::class, fn (RunStaticSiteRequest $job): bool => $job->changeRequestId === $request->id);

    $this->get("/area-clienti/richieste/{$request->id}")->assertSee('Hai risposto')->assertDontSee('Ci serve una tua risposta');
});

it('takes an open answer in the client\'s own words', function (): void {
    $this->post("/area-clienti/richieste/{$this->request->id}/rispondi", ['note' => 'Nella bacheca, aggiungile.']);

    expect($this->request->refresh()->status)->toBe(ChangeRequestStatus::Queued);
});

it('refuses an empty answer and keeps waiting', function (): void {
    $this->from("/area-clienti/richieste/{$this->request->id}")
        ->post("/area-clienti/richieste/{$this->request->id}/rispondi", ['choices' => [0 => 'non tra le opzioni'], 'note' => '  '])
        ->assertRedirect("/area-clienti/richieste/{$this->request->id}");

    $this->get("/area-clienti/richieste/{$this->request->id}")->assertSee('Scegli una risposta o scrivi qualcosa.');
    expect($this->request->refresh()->status)->toBe(ChangeRequestStatus::NeedsInput);
    Queue::assertNothingPushed();
});

it('answers only the client\'s own request, and only while it is waiting', function (): void {
    $other = Contact::factory()->create();
    $this->actingAs(new ClientUser($other->id, $other->display_name, $other->email), 'portal');
    $this->post("/area-clienti/richieste/{$this->request->id}/rispondi", ['note' => 'Ciao'])->assertNotFound();

    $this->actingAs(new ClientUser($this->site['contact']->id, $this->site['contact']->display_name, $this->site['contact']->email), 'portal');
    $this->request->forceFill(['status' => ChangeRequestStatus::Running])->save();
    $this->post("/area-clienti/richieste/{$this->request->id}/rispondi", ['note' => 'Ciao'])->assertRedirect();
    $this->get("/area-clienti/richieste/{$this->request->id}")->assertSee('Questa richiesta non aspetta più una risposta.');
    expect($this->request->refresh()->status)->toBe(ChangeRequestStatus::Running);
    Queue::assertNothingPushed();
});

it('shows the owner the exchange on the request\'s page', function (): void {
    auth()->shouldUse('web');
    signInAsOwner();

    $this->get("/admin/change-requests/{$this->request->id}")->assertOk()
        ->assertSee('Scambio con il cliente (aspetta la sua risposta)')->assertSee('Domanda dell&#039;agente', false)
        ->assertSee('Dove vuoi le foto?')->assertSee('La bacheca della home');
});
