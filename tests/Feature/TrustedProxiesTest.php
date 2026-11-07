<?php

declare(strict_types=1);

it('builds https URLs when the proxy says the request was https', function (): void {
    $this->get('/admin/login', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'backoffice.example.test'])
        ->assertOk()
        ->assertSee('https://backoffice.example.test/', false)
        ->assertDontSee('http://backoffice.example.test/', false);
});
