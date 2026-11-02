"use client";
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Bell, CheckCheck, AtSign, MessageCircle, RefreshCw } from 'lucide-react';
type Notice = {id:number;action:string;actor:string;post_id:number;comment_id:number;title:string;community_id:number;read:boolean;created_at:string};
async function request(path:string, method='GET') {
  const response = await fetch('/api/community/notifications' + path, {method});
  const data = await response.json(); if (!response.ok) throw new Error(data.message || 'Notifications are unavailable. Try again.'); return data;
}
export function NotificationsNav({selected}:{selected:boolean}) {
  const [count,setCount] = useState<number|null>(null);
  useEffect(() => {
    let live = true;
    async function load() { try { const data = await request('/count'); if (live) setCount(data.unread); } catch { if (live) setCount(null); } }
    load(); const timer = setInterval(() => { if (document.visibilityState === 'visible') load(); }, 30000);
    window.addEventListener('riffit-notifications-read',load);
    return () => { live=false; clearInterval(timer); window.removeEventListener('riffit-notifications-read',load); };
  }, []);
  return <Link className={`nav-item ${selected?'selected':''}`} href="/?view=notifications"><Bell aria-hidden="true"/>Notifications{count !== null && count > 0 && <span className="notification-count" aria-label={`${count} unread`}>{count > 99 ? '99+' : count}</span>}</Link>;
}
export default function Notifications({signedIn}:{signedIn:boolean}) {
  const [items,setItems] = useState<Notice[]>([]),[page,setPage] = useState(1),[pages,setPages] = useState(0),[loading,setLoading] = useState(true),[busy,setBusy] = useState(false),[error,setError] = useState(''),[refresh,setRefresh] = useState(0);
  useEffect(() => {
    if (!signedIn) {setLoading(false);return;}
    let live=true;setLoading(true);setError('');
    request(`?page=${page}`).then(data=>{if(live){setItems(data.items);setPages(data.pages);}}).catch(e=>{if(live)setError(e.message);}).finally(()=>{if(live)setLoading(false);});
    return ()=>{live=false;};
  },[signedIn,page,refresh]);
  async function mark(id?:number) {setBusy(true);setError('');try{await request(id?`/${id}/read`:'/read','PUT');setItems(old=>old.map(n=>!id||n.id===id?{...n,read:true}:n));window.dispatchEvent(new Event('riffit-notifications-read'));}catch(e){setError((e as Error).message);}finally{setBusy(false);}}
  if(!signedIn)return <p className="notice"><Link href="/login">Sign in</Link> to see your notifications.</p>;
  return <section className="notification-inbox"><div className="notification-toolbar"><button className="quiet" disabled={busy||!items.some(n=>!n.read)} onClick={()=>mark()}><CheckCheck aria-hidden="true"/>Mark all read</button><button className="quiet" onClick={()=>setRefresh(v=>v+1)}><RefreshCw aria-hidden="true"/>Refresh</button></div>{error&&<p role="alert" className="error">{error}</p>}{loading?<p role="status">Loading notifications…</p>:items.length?items.map(n=><article key={n.id} className={`notification-row ${n.read?'':'unread'}`}>{n.action==='mention'?<AtSign aria-hidden="true"/>:<MessageCircle aria-hidden="true"/>}<div><p><Link href={`/?view=profile&handle=${n.actor}`}>@{n.actor}</Link> {n.action==='mention'?'mentioned you':'replied to your conversation'}</p><Link className="notification-title" href={`/?post=${n.post_id}&community=${n.community_id}${n.comment_id?`&comment=${n.comment_id}#comment-${n.comment_id}`:''}`}>{n.title}</Link><p className="muted">{new Date(n.created_at).toLocaleString('en-US',{timeZone:'UTC'})} UTC</p>{!n.read&&<button className="quiet" disabled={busy} onClick={()=>mark(n.id)}>Mark read</button>}</div></article>):!error&&<div className="empty"><Bell aria-hidden="true"/><h2>You’re all caught up.</h2><p>Replies and mentions will show up here.</p></div>}{pages>1&&<nav className="pagination" aria-label="Notification pages"><button className="quiet" disabled={page<=1} onClick={()=>setPage(p=>p-1)}>Previous</button><span>Page {page} of {pages}</span><button className="quiet" disabled={page>=pages} onClick={()=>setPage(p=>p+1)}>Next</button></nav>}</section>;
}
