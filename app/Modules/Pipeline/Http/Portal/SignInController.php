<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Portal;

use App\Modules\Pipeline\Actions\ConsumePortalSignIn;
use App\Modules\Pipeline\Actions\RequestPortalSignIn;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sign-in by e-mailed link: the form, the "sent" page (the same whether
 * or not the address is known), the link's page with its button, and
 * sign-out.
 */
final class SignInController
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function show(): View|RedirectResponse
    {
        if ($this->auth->guard('portal')->check()) {
            return redirect()->route('portal.requests');
        }

        return view('area-clienti.sign-in', ['sent' => false]);
    }

    public function send(Request $request, RequestPortalSignIn $signIn): View
    {
        $email = $request->input('email');
        $signIn->request(is_string($email) ? $email : '');

        return view('area-clienti.sign-in', ['sent' => true]);
    }

    public function confirm(string $token, ConsumePortalSignIn $consume): View
    {
        return view('area-clienti.sign-in-confirm', ['valid' => $consume->isValid($token), 'action' => route('portal.sign-in.consume', ['token' => $token])]);
    }

    public function consume(Request $request, string $token, ConsumePortalSignIn $consume): View|RedirectResponse
    {
        $user = $consume->consume($token);
        if ($user === null) {
            return view('area-clienti.sign-in-confirm', ['valid' => false, 'action' => route('portal.sign-in.consume', ['token' => $token])]);
        }

        $this->auth->guard('portal')->login($user);
        $request->session()->regenerate();

        return redirect()->route('portal.requests');
    }

    public function signOut(Request $request): RedirectResponse
    {
        $this->auth->guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.sign-in');
    }
}
