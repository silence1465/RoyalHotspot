<?php

namespace App\Http\Controllers;

use App\Models\Router;
use Illuminate\Http\Response;

/**
 * This is the file RouterOS's /tool fetch downloads and saves as its own
 * hotspot/login.html (see MikrotikService::fetchLoginPage() and
 * Admin\RouterController::setupGuestPortal()). Once saved, RouterOS
 * serves this file LOCALLY on every future hotspot login — this
 * controller is only hit once, at setup time (or whenever an admin
 * re-runs "Set Up Guest Portal" after a change), never per-guest.
 *
 * $(link-login-only), $(mac), $(ip) are RouterOS's own template syntax
 * — deliberately left as literal text here. THIS controller does not
 * fill them in; RouterOS itself substitutes them when it serves the
 * saved file to an actual connecting device. Filling them in here would
 * bake a single guest's MAC into every future login page.
 */
class MikrotikLoginPageController extends Controller
{
    public function show(Router $router): Response
    {
        $frontendUrl = rtrim(config('services.frontend.url'), '/');

        $redirectUrl = "{$frontendUrl}/portal"
            . "?router={$router->id}"
            . "&login-url=\$(link-login-only)"
            . "&mac=\$(mac)"
            . "&ip=\$(ip)";

        // Android's captive-portal sign-in WebView is a restricted
        // browser that breaks in-flight fetch/XHR calls the SPA depends
        // on (guest package loads, login, register all failed silently
        // in testing — see the frontend's OpenInBrowserLink.jsx for the
        // reactive version of this same fix). Rather than let a guest
        // hit that failure at all, detect Android here and hand off to
        // the device's REAL browser immediately via the intent: scheme,
        // before the SPA ever loads inside the restricted view.
        //
        // iOS's captive-portal browser is a full WebKit view without
        // this problem, AND doesn't understand intent: URIs at all — so
        // this is deliberately Android-only. Forcing the same redirect
        // there would break navigation for iPhone users who currently
        // work fine.
        $androidIntentUrl = $this->toAndroidIntentUrl($redirectUrl);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="refresh" content="0;url={$redirectUrl}">
<title>Redirecting…</title>
<script>
  if (/Android/i.test(navigator.userAgent)) {
    window.location.replace("{$androidIntentUrl}");
  } else {
    window.location.replace("{$redirectUrl}");
  }
</script>
</head>
<body>
<p>Redirecting to the WiFi login page…</p>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html');
    }

    /**
     * Same intent: URI trick as frontend/src/utils/openInBrowser.js,
     * built server-side since this page is generated (and cached by
     * RouterOS) before any JS bundle loads.
     */
    protected function toAndroidIntentUrl(string $url): string
    {
        if (! preg_match('#^(https?)://(.+)$#', $url, $m)) {
            return $url;
        }

        [, $scheme, $rest] = $m;

        return "intent://{$rest}#Intent;scheme={$scheme};action=android.intent.action.VIEW;end";
    }
}
