export const metadata={title:'Confirm sign-in · Riffit',referrer:'strict-origin' as const,robots:{index:false,follow:false}};
export default async function Verify({searchParams}:{searchParams:Promise<{code?:string}>}){
  const {code}=await searchParams;
  const valid=typeof code==='string'&&/^[a-f0-9]{64}$/.test(code);
  return <main className="auth"><header><a href="/" className="wordmark">riffit</a></header><h1>{valid?'Ready to riff?':'This link is incomplete.'}</h1>
    {valid?<><p>Confirm your sign-in to continue. This link only works in the browser where you requested it.</p><form action="/api/auth/email/confirm" method="post"><input type="hidden" name="code" value={code}/><button>Sign in to Riffit</button></form></>:<a href="/login">Request a new sign-in link</a>}
  </main>;
}
