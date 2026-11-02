import type { Metadata } from 'next';
import './styles.css';
import './community.css';
import './theme.css';
export const metadata: Metadata = { title: 'Riffit · Find your people', robots: { index: false, follow: false } };
export default function Layout({ children }: { children: React.ReactNode }) {
  return <html lang="en" suppressHydrationWarning><head><script id="riffit-theme-init" dangerouslySetInnerHTML={{ __html: "try{var p=localStorage.getItem('riffit.theme');document.documentElement.dataset.theme=(p==='light'||p==='dark')?p:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light')}catch(e){document.documentElement.dataset.theme=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}" }} /></head><body>{children}</body></html>;
}
