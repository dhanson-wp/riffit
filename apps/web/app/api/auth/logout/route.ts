import { NextRequest, NextResponse } from 'next/server';
import { sameOrigin,wordpress,sessionCookie,appOrigin } from '../../../../lib/auth';
export async function POST(request:NextRequest){
  if(!sameOrigin(request))return new NextResponse('This request could not be verified.',{status:403});
  const token=request.cookies.get(sessionCookie)?.value;
  if(token){
    try{const upstream=await wordpress('/auth/session',{method:'DELETE',headers:{Authorization:`Bearer ${token}`}});
      if(!upstream.ok&&upstream.status!==401)return new NextResponse('Could not revoke your session. Please try again.',{status:503});
    }catch{return new NextResponse('Could not revoke your session. Please try again.',{status:503});}
  }
  const response=NextResponse.redirect(new URL('/login',appOrigin()),303);
  response.cookies.delete(sessionCookie);return response;
}
