<?php

namespace NBCSIT\Sso\Listeners;

use Illuminate\Support\Facades\Auth;
use NBCSIT\Saml2\Events\SignedOut;

/**
 * Single logout: the IdP has ended the session, so end the local one too.
 */
class HandleSamlSignOut
{
    public function handle(SignedOut $event): void
    {
        Auth::logout();

        session()->forget([HandleSamlSignIn::SESSION_OUTCOME, HandleSamlSignIn::SESSION_NAME_ID]);
        session()->invalidate();
        session()->regenerateToken();
    }
}
