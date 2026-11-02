import Link from 'next/link';
import Image from 'next/image';
import ThemeToggle from '../ThemeToggle';
import EmailForm from './EmailForm';
import {wordpress} from '../../lib/auth';
export const metadata={title:'Sign in · Riffit',referrer:'strict-origin' as const};
export default async function Login({searchParams}:{searchParams:Promise<{error?:string}>}){
  const {error}=await searchParams;
  let connect=false;try{const response=await wordpress('/auth/options');if(response.ok)connect=!!(await response.json()).wordpress;}catch{}
  return <main className="auth"><header><Link href="/" className="wordmark">riffit</Link><ThemeToggle/></header><Image src="/riffit-gecko.webp" unoptimized width={104} height={104} alt="Riffit’s curious gecko" priority/><h1>Find your people.</h1><p>Sign in to join communities, add your riff, and keep the conversation going.</p>
  {error&&<p className="error" role="alert">{error==='admin-wordpress'?'Site administrators must use WordPress.com sign-in. That option needs to be configured by the site owner before administrators can sign in here.':error==='wordpress-link'?'Sign in with your email first, then connect WordPress.com from your profile.':error==='wordpress'?'WordPress.com sign-in did not finish. Try again or use an email link.':'That link has expired, was already used, or was opened in another browser. Request a new one below.'}</p>}
  {connect&&<form className="provider-login" action="/api/auth/wordpress/start" method="post"><input type="hidden" name="mode" value="signin"/><button>Continue with WordPress.com</button><p className="muted">Only your identity is requested, never access to your sites or posts.</p></form>}<EmailForm/></main>;
}
