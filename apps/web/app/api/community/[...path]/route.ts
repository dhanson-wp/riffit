import { NextRequest, NextResponse } from 'next/server';
import { cookies } from 'next/headers';
import { sameOrigin, sessionCookie, wordpress } from '../../../../lib/auth';
const allowed = /^(communities(?:\/by-slug\/[a-z0-9-]+|\/\d+(?:\/(?:membership|posts|media|branding|moderation|trash|settings|members(?:\/\d+)?))?)?|posts(?:\/\d+(?:\/(?:replies|saved|poll))?)?|objects\/\d+(?:\/(?:vote|report|moderation))?|saved|profiles\/[a-zA-Z0-9_-]+|profile|account(?:\/sessions)?|reports\/\d+|messages(?:\/\d+(?:\/read)?)?|members\/\d+\/block|notifications(?:\/(?:count|read|\d+\/read))?)$/;
async function handle(request: NextRequest, context: {params: Promise<{path:string[]}>}) {
  const path=(await context.params).path.join('/');
  if(!allowed.test(path)) return NextResponse.json({message:'Not found.'},{status:404});
  if(request.method!=='GET'&&!sameOrigin(request)) return NextResponse.json({message:'Reload Riffit and try again.'},{status:403});
  const token=(await cookies()).get(sessionCookie)?.value;
  if(request.method!=='GET'&&!token) return NextResponse.json({message:'Sign in to participate.'},{status:401});
  try {
    const body=request.method==='GET'?undefined:await request.text();
    const limit = /^communities\/\d+\/(?:media|branding)$/.test(path) ? 7500000 : 50000;
    if(body&&body.length>limit) return NextResponse.json({message:'This post is too long.'},{status:413});
    let response=await wordpress('/'+path+request.nextUrl.search,{method:request.method,body,headers:{'Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`}:{})}});
    if(request.method==='GET' && response.status===401 && /^(communities(?:\/by-slug\/[a-z0-9-]+|\/\d+(?:\/posts)?)?|posts(?:\/\d+(?:\/replies)?)?|profiles\/[a-zA-Z0-9_-]+)$/.test(path)) response=await wordpress('/'+path+request.nextUrl.search);
    return new NextResponse(await response.text(),{status:response.status,headers:{'Content-Type':'application/json','Cache-Control':'private, no-store'}});
  } catch { return NextResponse.json({message:'The community service is unavailable. Try again shortly.'},{status:503}); }
}
export {handle as GET,handle as POST,handle as PUT,handle as DELETE};
