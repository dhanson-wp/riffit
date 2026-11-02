import {NextRequest,NextResponse} from 'next/server';
import {sameOrigin,wordpress,sessionCookie,cookieOptions,appOrigin} from '../../../../../lib/auth';
export async function POST(request:NextRequest){
 if(!sameOrigin(request))return new NextResponse('This request could not be verified.',{status:403});
 const failure=(link=false)=>{const response=NextResponse.redirect(new URL('/login?error='+ (link?'wordpress-link':'wordpress'),appOrigin()),303);response.cookies.delete('riffit-wp-proof');response.cookies.delete('riffit-wp-mode');response.headers.set('Cache-Control','no-store');return response;};
 const form=await request.formData(),code=form.get('code'),state=form.get('state'),verifier=request.cookies.get('riffit-wp-proof')?.value,token=request.cookies.get(sessionCookie)?.value;
 if(typeof code!=='string'||!code||code.length>2000||typeof state!=='string'||!/^[a-f0-9]{64}$/.test(state)||!verifier)return failure();
 try{
  const upstream=await wordpress('/auth/wordpress/consume',{method:'POST',headers:{'Content-Type':'application/json',...(request.cookies.get('riffit-wp-mode')?.value==='link'&&token?{Authorization:`Bearer ${token}`}:{})},body:JSON.stringify({code,state,verifier})});
  const session=await upstream.json();if(!upstream.ok)return failure(session.code==='riffit_oauth_link_required');
  if(typeof session.token!=='string'||!/^[a-f0-9]{64}$/.test(session.token)||!Number.isFinite(session.expires_at))return failure();
  const destination=session.linked?'/?view=profile&handle='+encodeURIComponent(session.user.handle)+'&connected=wordpress':'/';
  const response=NextResponse.redirect(new URL(destination,appOrigin()),303);
  response.cookies.set(sessionCookie,session.token,{...cookieOptions,maxAge:Math.max(0,Math.min(1209600,session.expires_at-Math.floor(Date.now()/1000)))});
  response.cookies.delete('riffit-wp-proof');response.cookies.delete('riffit-wp-mode');response.headers.set('Cache-Control','no-store');response.headers.set('Referrer-Policy','strict-origin');return response;
 }catch{return failure();}
}
