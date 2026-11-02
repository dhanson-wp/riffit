import {cookies} from 'next/headers';
import {redirect} from 'next/navigation';
import Link from 'next/link';
export const metadata={title:'Confirm WordPress.com sign-in · Riffit',referrer:'strict-origin' as const};
export const dynamic='force-dynamic';
export default async function WordPressCallback({searchParams}:{searchParams:Promise<{code?:string;state?:string;error?:string}>}){
 const {code,state,error}=await searchParams,proof=(await cookies()).get('riffit-wp-proof')?.value;
 if(typeof state==='string'&&/^admin-[a-f0-9]{64}$/.test(state)){
  const backend=process.env.WORDPRESS_URL;
  if(backend){const target=new URL('/wp-admin/admin-post.php',backend);if(target.protocol==='https:'||['localhost','127.0.0.1'].includes(target.hostname)){target.searchParams.set('action','riffit_wordpress_complete');target.searchParams.set('state',state);if(!error&&typeof code==='string'&&code.length>0&&code.length<=2000)target.searchParams.set('code',code);redirect(target.href);}}
 }
 const valid=!error&&typeof code==='string'&&code.length>0&&code.length<=2000&&typeof state==='string'&&/^[a-f0-9]{64}$/.test(state)&&!!proof;
 return <main className="auth"><header><Link className="wordmark" href="/">riffit</Link></header><h1>{valid?'Continue with WordPress.com?':'WordPress.com sign-in didn’t finish.'}</h1>{valid?<><p>Confirm to finish signing in or connecting your WordPress.com identity to Riffit.</p><form action="/api/auth/wordpress/confirm" method="post"><input type="hidden" name="code" value={code}/><input type="hidden" name="state" value={state}/><button>Continue to Riffit</button></form></>:<p>You can try again or sign in with an email link.</p>}<p><Link href="/login">Back to sign-in</Link></p></main>;
}
