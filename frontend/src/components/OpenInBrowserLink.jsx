import { openInBrowserHref } from '../utils/openInBrowser';

/**
 * Shown alongside a network-error message on pages that are commonly
 * reached from inside a MikroTik hotspot's captive-portal redirect —
 * where the request may have failed only because Android's restricted
 * sign-in WebView killed it, not because anything is actually down. See
 * utils/openInBrowser.js.
 */
export default function OpenInBrowserLink({ className = '' }) {
  return (
    <a
      href={openInBrowserHref()}
      className={`inline-block text-sm text-indigo-600 hover:underline ${className}`}
    >
      Trouble connecting? Tap to open in your browser
    </a>
  );
}
