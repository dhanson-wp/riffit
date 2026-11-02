"use client";
import { useEffect, useState } from 'react';
import { Moon, Sun, Monitor } from 'lucide-react';
type Preference = 'light' | 'dark' | 'system';
const key = 'riffit.theme';
export default function ThemeToggle() {
  const [preference, setPreference] = useState<Preference | null>(null);
  useEffect(() => {
    const read = () => { try { const saved = localStorage.getItem(key); setPreference(saved === 'light' || saved === 'dark' ? saved : 'system'); } catch { setPreference('system'); } };
    read(); window.addEventListener('storage', read);
    return () => window.removeEventListener('storage', read);
  }, []);
  useEffect(() => {
    if (preference === null) return;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    const apply = () => { document.documentElement.dataset.theme = preference === 'system' ? (system.matches ? 'dark' : 'light') : preference; };
    apply(); system.addEventListener('change', apply);
    return () => system.removeEventListener('change', apply);
  }, [preference]);
  function change(value: Preference) {
    setPreference(value);
    try { if (value === 'system') localStorage.removeItem(key); else localStorage.setItem(key, value); } catch { /* The preference still applies for this visit. */ }
  }
  return <details className="theme-picker"><summary aria-label="Appearance" title="Appearance"><Sun className="theme-light-icon" aria-hidden="true"/><Moon className="theme-dark-icon" aria-hidden="true"/><span className="sr-only">Appearance</span></summary><div className="theme-menu"><span>Appearance</span><div role="group" aria-label="Color theme">{([['light', Sun, 'Light'], ['dark', Moon, 'Dark'], ['system', Monitor, 'System']] as const).map(([value, Icon, label]) => <button type="button" key={value} aria-pressed={(preference ?? 'system') === value} onClick={() => change(value)}><Icon aria-hidden="true"/>{label}<span aria-hidden="true">{(preference ?? 'system') === value ? '✓' : ''}</span></button>)}</div></div></details>;
}
