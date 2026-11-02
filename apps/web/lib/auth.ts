import 'server-only';
import { cookies } from 'next/headers';
import type { NextRequest } from 'next/server';

export const sessionCookie = 'riffit-session';
export const proofCookie = 'riffit-proof';
export function appOrigin() { return new URL(process.env.APP_URL || 'http://localhost:3100').origin; }
export function sameOrigin(request: NextRequest) { return request.headers.get('origin') === appOrigin(); }
export const cookieOptions = { httpOnly: true, secure: process.env.NODE_ENV === 'production', sameSite: 'lax' as const, path: '/' };
export async function wordpress(path: string, init: RequestInit = {}) {
  const base = process.env.WORDPRESS_URL;
  if (!base) throw new Error('Backend is not configured.');
  const url = new URL('/wp-json/riffit/v1' + path, base);
  if (url.protocol !== 'https:' && !['127.0.0.1', 'localhost'].includes(url.hostname)) throw new Error('HTTPS is required.');
  const send = async () => {
    const response = await fetch(url, { ...init, cache: 'no-store' as const, redirect: 'manual' as const, signal: AbortSignal.timeout(20000) });
    if (response.status >= 300 && response.status < 400) throw new Error('The community service returned an unexpected redirect. Please try again later.');
    return response;
  };
  try { return await send(); } catch (error) {
    // A recycled upstream connection can reset between reads. Never retry writes.
    if ((!init.method || init.method === 'GET') && error instanceof TypeError) return send();
    throw error;
  }
}
export async function currentMember(): Promise<{id:number;handle:string;name:string;avatar_id?:string}|null> {
  const token = (await cookies()).get(sessionCookie)?.value;
  if (!token || !/^[a-f0-9]{64}$/.test(token)) return null;
  const response = await wordpress('/me', { headers: { Authorization: `Bearer ${token}` } });
  if (response.status === 401 || response.status === 403) return null;
  if (!response.ok) throw new Error('Member service is unavailable.');
  return response.json();
}
