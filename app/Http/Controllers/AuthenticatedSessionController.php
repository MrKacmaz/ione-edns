<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signs operators in to the dashboard with a session.
 *
 * Accounts are created by the seeder or by hand; there is no registration.
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Show the sign-in form.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }

    /**
     * Sign the user in and send them to the page they asked for, or the dashboard.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Sign the user out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
