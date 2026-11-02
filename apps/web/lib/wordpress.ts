import 'server-only';

export type BackendHealth = {
  version: string;
  dependencies: { bbpress: boolean; buddypress: boolean; groups: boolean };
  authentication: string;
};

export async function backendHealth(): Promise<BackendHealth | null> {
  const base = process.env.WORDPRESS_URL;
  if (!base) return null;
  const url = new URL('/wp-json/riffit/v1/health', base);
  if (url.protocol !== 'https:' && !['localhost', '127.0.0.1'].includes(url.hostname)) {
    throw new Error('The WordPress backend requires HTTPS.');
  }
  const response = await fetch(url, { cache: 'no-store', signal: AbortSignal.timeout(8000) });
  if (!response.ok) throw new Error(`Backend health returned HTTP ${response.status}.`);
  return response.json();
}
