import { useEffect, useState } from 'react';
import { Download, RefreshCw, X } from 'lucide-react';

export default function PwaInstallPrompt() {
  const [installEvent, setInstallEvent] = useState(null);
  const [showIosHelp, setShowIosHelp] = useState(false);
  const [updateWorker, setUpdateWorker] = useState(null);
  const [dismissed, setDismissed] = useState(() => sessionStorage.getItem('pwa_prompt_dismissed') === '1');

  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const isIos = /iPad|iPhone|iPod/i.test(navigator.userAgent);
  const isPortal = window.location.pathname === '/portal';

  useEffect(() => {
    const onInstall = (event) => {
      event.preventDefault();
      setInstallEvent(event);
    };
    const onInstalled = () => setInstallEvent(null);
    const onUpdate = (event) => setUpdateWorker(event.detail);
    window.addEventListener('beforeinstallprompt', onInstall);
    window.addEventListener('appinstalled', onInstalled);
    window.addEventListener('pwa-update-ready', onUpdate);
    return () => {
      window.removeEventListener('beforeinstallprompt', onInstall);
      window.removeEventListener('appinstalled', onInstalled);
      window.removeEventListener('pwa-update-ready', onUpdate);
    };
  }, []);

  if (updateWorker) {
    return (
      <div className="fixed bottom-4 left-4 right-4 z-[100] mx-auto max-w-sm rounded-xl border border-indigo-200 bg-white p-4 shadow-xl">
        <p className="text-sm font-semibold text-slate-900">Royal WiFi update available</p>
        <button
          onClick={() => updateWorker.postMessage('SKIP_WAITING')}
          className="mt-3 flex w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white"
        >
          <RefreshCw className="h-4 w-4" /> Update now
        </button>
      </div>
    );
  }

  if (dismissed || isStandalone || isPortal || (!installEvent && !isIos)) return null;

  const dismiss = () => {
    sessionStorage.setItem('pwa_prompt_dismissed', '1');
    setDismissed(true);
  };

  const install = async () => {
    if (installEvent) {
      await installEvent.prompt();
      await installEvent.userChoice;
      setInstallEvent(null);
    } else {
      setShowIosHelp(true);
    }
  };

  return (
    <div className="fixed bottom-4 left-4 right-4 z-[100] mx-auto max-w-sm rounded-xl border border-slate-200 bg-white p-4 shadow-xl">
      <button onClick={dismiss} className="absolute right-3 top-3 text-slate-400" aria-label="Dismiss install prompt">
        <X className="h-4 w-4" />
      </button>
      <p className="pr-6 text-sm font-semibold text-slate-900">Install Royal WiFi</p>
      <p className="mt-1 text-xs text-slate-500">Return to your account and purchases more easily.</p>
      {showIosHelp ? (
        <p className="mt-3 rounded-md bg-indigo-50 p-3 text-xs text-indigo-800">
          In Safari, tap the Share button, then choose Add to Home Screen and tap Add.
        </p>
      ) : (
        <button onClick={install} className="mt-3 flex w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">
          <Download className="h-4 w-4" /> Install app
        </button>
      )}
    </div>
  );
}
