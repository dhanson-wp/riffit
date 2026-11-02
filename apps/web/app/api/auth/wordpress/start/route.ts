import { NextRequest,NextResponse } from 'next/server';
import { cookies } from 'next/headers';
import { sameOrigin,wordpress,sessionCookie,cookieOptions,appOrigin } from '../../../../../lib/auth';
export async function POST(request:NextRequest){
 if(!sameOrigin(request))return new NextResponse('This request could not be verified.',{status:403});
 const failure=()=>NextResponse.redirect(new URL('/login?error=wordpress',appOrigin()),303);
 try{
  const form=await request.formData(),mode=form.get('mode')==='link'?'link':'signin';
  const token=(await cookies()).get(sessionCookie)?.value;
  const bytes=crypto.getRandomValues(new Uint8Array(32));
  const verifier=Buffer.from(bytes).toString('base64url');
  const proof=Buffer.from(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(verifier))).toString('hex');
  const upstream=await wordpress('/auth/wordpress/start',{method:'POST',headers:{'Content-Type':'application/json',...(mode==='link'&&token?{Authorization:`Bearer ${token}`}:{})},body:JSON.stringify({proof,mode})});
  if(!upstream.ok)return failure();
  const data=await upstream.json(),url=new URL(data.url);
  if(url.protocol!=='https:'||url.hostname!=='public-api.wordpress.com'||url.pathname!=='/oauth2/authenticate'||url.searchParams.get('redirect_uri')!==appOrigin()+'/auth/wordpress')return failure();
  const response=NextResponse.redirect(url,303);
  response.cookies.set('riffit-wp-proof',verifier,{...cookieOptions,maxAge:600});
  response.cookies.set('riffit-wp-mode',mode,{...cookieOptions,maxAge:600});
  response.headers.set('Cache-Control','no-store');response.headers.set('Referrer-Policy','strict-origin');return response;
 }catch{return failure();}
}
