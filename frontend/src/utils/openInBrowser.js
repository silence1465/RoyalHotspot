/**
 * Android's captive-portal sign-in WebView ("Sign in to <network>") is a
 * restricted browser the OS uses just for hotspot logins — it's known to
 * break in-flight fetch/XHR calls from a full SPA, even though the exact
 * same URL works fine in a real Chrome tab. `intent://` is the standard
 * way to hand a URL off to the device's actual browser from inside a
 * WebView. iOS's captive-portal browser is a full WebKit view without
 * this problem, and browsers that don't understand the intent: scheme
 * just treat this as a normal (harmless) link.
 */
export function openInBrowserHref(url = window.location.href) {
  const match = url.match(/^(https?):\/\/(.+)$/);
  if (!match) return url;

  const [, scheme, rest] = match;
  return `intent://${rest}#Intent;scheme=${scheme};action=android.intent.action.VIEW;end`;
}
