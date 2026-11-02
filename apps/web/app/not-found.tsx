import Link from 'next/link';
import { ArrowLeft, Compass } from 'lucide-react';
export default function NotFound() {
  return <main className="community-app missing-page"><Link className="brand" href="/" aria-label="Riffit home"><img src="/riffit-gecko.webp" width="44" height="44" alt=""/><strong>riffit<span>.</span></strong></Link><section><img src="/riffit-gecko.webp" width="120" height="120" alt=""/><p className="muted">404</p><h1>This page isn’t available.</h1><p>The link may have changed, or this conversation may no longer be available.</p><div><Link className="button" href="/"><ArrowLeft aria-hidden="true"/>Back to home</Link><Link className="quiet" href="/?view=communities"><Compass aria-hidden="true"/>Explore communities</Link></div></section></main>;
}
