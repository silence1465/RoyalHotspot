import { useState } from 'react';
import { Copy, Check } from 'lucide-react';
import { copyText } from '../utils/copyText';

export default function CopyButton({ text, label = 'Copy Code' }) {
  const [copied, setCopied] = useState(false);

  const handleCopy = async () => {
    try {
      await copyText(text);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      // Clipboard API can fail (e.g. no HTTPS, older browser) — the code
      // is still visible on screen either way, so this isn't fatal.
    }
  };

  return (
    <button
      onClick={handleCopy}
      className={`flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-md border transition ${
        copied
          ? 'bg-emerald-50 border-emerald-200 text-emerald-700'
          : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'
      }`}
    >
      {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
      {copied ? 'Copied!' : label}
    </button>
  );
}
