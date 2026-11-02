import { NextRequest, NextResponse } from 'next/server';
import { sameOrigin, wordpress, proofCookie, sessionCookie, cookieOptions, appOrigin } from '../../../../../lib/auth';
export async function POST(request: NextRequest) {
  if (!sameOrigin(request)) return new NextResponse('This request could not be verified.',{status:403});
  const form=await request.formData();
  const code=form.get('code');
  const verifier=request.cookies.get(proofCookie)?.value;
  const failure=()=>NextResponse.redirect(new URL('/login?error=invalid-link',appOrigin()),303);
  if (typeof code!=='string'||!/^[a-f0-9]{64}$/.test(code)||!verifier) return failure();
  try {
    const upstream=await wordpress('/auth/email/consume',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code,verifier})});
    if (!upstream.ok) {
      const error=await upstream.json().catch(()=>({}));
      if(error.code==='riffit_admin_wordpress_required')return NextResponse.redirect(new URL('/login?error=admin-wordpress',appOrigin()),303);
      return failure();
    }
    const session=await upstream.json();
    if (typeof session.token!=='string'||!/^[a-f0-9]{64}$/.test(session.token)||!Number.isFinite(session.expires_at)) return failure();
    const response=NextResponse.redirect(new URL('/',appOrigin()),303);
    response.cookies.set(sessionCookie,session.token,{...cookieOptions,maxAge:Math.max(0,Math.min(1209600,session.expires_at-Math.floor(Date.now()/1000)))});
    response.cookies.delete(proofCookie);
    response.headers.set('Cache-Control','no-store');
    response.headers.set('Referrer-Policy','strict-origin');
    return response;
  } catch { return failure(); }
}
