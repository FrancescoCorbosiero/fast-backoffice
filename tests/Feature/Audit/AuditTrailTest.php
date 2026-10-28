<?php

declare(strict_types=1);

use App\Kernel\Reference;
use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Audit\Filament\Resources\AuditEntries\Pages\ListAuditEntries;
use App\Modules\Audit\Queries\AuditTrail;
use Livewire\Livewire;

it('records who did what to which record', function (): void {
    $owner = signInAsOwner();

    $entry = app(RecordAuditEntry::class)->record('invoicing.invoice.issued', Reference::to('invoice', 7), ['number' => '7/2026']);

    expect($entry->actorId)->toBe($owner->id)
        ->and($entry->action)->toBe('invoicing.invoice.issued')
        ->and($entry->subject)->toBe('invoice:7')
        ->and($entry->details)->toBe(['number' => '7/2026']);
});

it('records system actions without an actor', function (): void {
    $entry = app(RecordAuditEntry::class)->record('scheduling.job.ran');

    expect($entry->actorId)->toBeNull()
        ->and($entry->subject)->toBeNull();
});

it('lists the trail newest first and per subject oldest first', function (): void {
    $audit = app(RecordAuditEntry::class);
    $audit->record('a.first', Reference::to('invoice', 1));
    $audit->record('b.second', Reference::to('invoice', 2));
    $audit->record('c.third', Reference::to('invoice', 1));

    $trail = app(AuditTrail::class);

    expect(array_map(fn ($entry) => $entry->action, $trail->latest()))->toBe(['c.third', 'b.second', 'a.first'])
        ->and(array_map(fn ($entry) => $entry->action, $trail->forSubject(Reference::to('invoice', 1))))->toBe(['a.first', 'c.third'])
        ->and($trail->latest(1))->toHaveCount(1);
});

it('shows the trail in the panel, read-only', function (): void {
    signInAsOwner();
    app(RecordAuditEntry::class)->record('settings.business_profile.saved', details: ['name' => 'Studio']);

    $this->get('/admin/audit-entries')->assertOk()->assertSee('settings.business_profile.saved');

    Livewire::test(ListAuditEntries::class)
        ->assertCanSeeTableRecords(App\Modules\Audit\Models\AuditEntry::all())
        ->assertSee('Studio');

    $this->get('/admin/audit-entries/create')->assertNotFound();
});
