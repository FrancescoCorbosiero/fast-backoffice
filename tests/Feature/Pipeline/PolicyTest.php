<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Pipeline\Auth\ClientUser;
use App\Modules\Pipeline\Auth\ClientUserProvider;
use App\Modules\Pipeline\Models\ChangeRequest;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Pipeline\Site;

it('lets the owner see and decide every request, and a client only their own', function (): void {
    $mine = Site::make();
    $theirs = Site::make();
    $myRequest = ChangeRequest::factory()->previewReady()->create(['contact_id' => $mine['contact']->id, 'website_id' => $mine['website']->id]);
    $theirRequest = ChangeRequest::factory()->previewReady()->create(['contact_id' => $theirs['contact']->id, 'website_id' => $theirs['website']->id]);

    $owner = User::factory()->create();
    $client = new ClientUser($mine['contact']->id, 'Pasticceria Bellavista S.r.l.', $mine['contact']->email);

    expect(Gate::forUser($owner)->allows('view', $myRequest))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('decide', $theirRequest))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', ChangeRequest::class))->toBeTrue()
        ->and(Gate::forUser($client)->allows('view', $myRequest))->toBeTrue()
        ->and(Gate::forUser($client)->allows('decide', $myRequest))->toBeTrue()
        ->and(Gate::forUser($client)->allows('view', $theirRequest))->toBeFalse()
        ->and(Gate::forUser($client)->allows('decide', $theirRequest))->toBeFalse()
        ->and(Gate::forUser($client)->allows('update', $myRequest))->toBeFalse()
        ->and(Gate::forUser($client)->allows('delete', $myRequest))->toBeFalse();
});

it('resolves a portal session to a live client contact through a non-numeric identifier', function (): void {
    $site = Site::make();
    $provider = app(ClientUserProvider::class);

    $user = $provider->retrieveById('contact:'.$site['contact']->id);
    expect($user)->toBeInstanceOf(ClientUser::class)
        ->and($user?->getAuthIdentifier())->toBe('contact:'.$site['contact']->id)
        ->and(is_numeric($user?->getAuthIdentifier()))->toBeFalse()
        ->and($provider->retrieveById($site['contact']->id))->toBeNull()
        ->and($provider->retrieveById('contact:0'))->toBeNull()
        ->and($provider->retrieveById('contact:999999'))->toBeNull();

    $supplier = App\Modules\Contacts\Models\Contact::factory()->supplier()->create();
    expect($provider->retrieveById('contact:'.$supplier->id))->toBeNull()
        ->and($provider->retrieveByCredentials(['email' => 'x']))->toBeNull()
        ->and($provider->validateCredentials($user, ['password' => 'x']))->toBeFalse();
});
