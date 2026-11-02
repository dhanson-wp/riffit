"use client";
import { useState } from 'react';
export default function EmailForm(){
  const [state,setState]=useState<'idle'|'sending'|'sent'>('idle');
  const [error,setError]=useState('');
  async function submit(event:React.FormEvent<HTMLFormElement>){
    event.preventDefault();setError('');setState('sending');
    const data=new FormData(event.currentTarget);
    try{const response=await fetch('/api/auth/email/start',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:data.get('email'),website:data.get('website')})});
      const result=await response.json();if(!response.ok)throw new Error(result.error||'Please try again.');setState('sent');
    }catch(error){setError(error instanceof Error?error.message:'Please try again.');setState('idle');}
  }
  if(state==='sent')return <div role="status"><h2>Check your inbox</h2><p>Your sign-in link is on its way. Open it in this browser within 15 minutes.</p><p>You can close this tab after signing in.</p></div>;
  return <form onSubmit={submit}><label htmlFor="email">Email address</label><input id="email" name="email" type="email" autoComplete="email" maxLength={100} required aria-describedby={error?'email-error':undefined}/>
    <div className="honeypot" aria-hidden="true"><label htmlFor="website">Leave this empty</label><input id="website" name="website" tabIndex={-1} autoComplete="off"/></div>
    {error&&<p id="email-error" className="error" role="alert">{error}</p>}
    <button disabled={state==='sending'}>{state==='sending'?'Sending…':'Email me a sign-in link'}</button>
    <p className="muted">No password to remember. Your public profile can use a pseudonym.</p></form>;
}
