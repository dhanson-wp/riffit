import { createHash, randomBytes } from 'node:crypto';
import { NextRequest, NextResponse } from 'next/server';
import { sameOrigin, wordpress, proofCookie, cookieOptions } from '../../../../../lib/auth';
export async function POST(request: NextRequest) {
  if (!sameOrigin(request)) return NextResponse.json({error:'This request could not be verified.'},{status:403});
  if (Number(request.headers.get('content-length') || 0) > 2048) return NextResponse.json({error:'Request is too large.'},{status:413});
  const body = await request.text();
  if (body.length > 2048) return NextResponse.json({error:'Request is too large.'},{status:413});
  let input: {email?:unknown;website?:unknown};
  try { input=JSON.parse(body); } catch { return NextResponse.json({error:'Check your email address.'},{status:400}); }
  if (input.website || typeof input.email !== 'string' || input.email.length > 100 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.email)) return NextResponse.json({error:'Check your email address.'},{status:400});
  const verifier=randomBytes(32).toString('base64url');
  try {
    const upstream=await wordpress('/auth/email/request',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:input.email,proof:createHash('sha256').update(verifier).digest('hex')})});
    if (!upstream.ok) return NextResponse.json({error:upstream.status===429?'Please wait before requesting another link.':'We could not send a link. Please try again later.'},{status:upstream.status===429?429:503});
    const response=NextResponse.json({accepted:true},{status:202,headers:{'Cache-Control':'no-store'}});
    response.cookies.set(proofCookie,verifier,{...cookieOptions,maxAge:900});
    return response;
  } catch (error) { console.error('Riffit sign-in transport:',error instanceof Error ? error.message : 'Unknown error'); return NextResponse.json({error:'Sign-in is temporarily unavailable. Please try again.'},{status:503}); }
}
