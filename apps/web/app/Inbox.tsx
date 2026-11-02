"use client";
import { useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { PenLine, RefreshCw, ShieldBan, Send } from 'lucide-react';
type Person = { id: number; handle: string; name: string };
type Thread = { id: number; people: Person[]; unread: number; preview: string; updated_at: string };
type Message = { id: number; sender_id: number; handle: string; content: string; created_at: string };
type ThreadData = Thread & { messages: Message[]; blocked_by_me: boolean; page: number; pages: number };
async function request(path: string, method = 'GET', body?: unknown) {
  const response = await fetch('/api/community/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  const data = await response.json(); if (!response.ok) throw new Error(data.message || 'Messages are unavailable. Try again.'); return data;
}
export default function Inbox({ member }: { member: Person | null }) {
  const params = useSearchParams(), router = useRouter(), id = Number(params.get('thread') || 0), page = Math.max(1, Number(params.get('messagePage') || 1)), to = params.get('to') || '';
  const [threads, setThreads] = useState<Thread[]>([]), [thread, setThread] = useState<ThreadData | null>(null), [inboxPage, setInboxPage] = useState(1), [inboxPages, setInboxPages] = useState(0);
  const [error, setError] = useState(''), [busy, setBusy] = useState(false), [loading, setLoading] = useState(true), [refresh, setRefresh] = useState(0), [blockNotice, setBlockNotice] = useState('');
  useEffect(() => {
    if (!member) { setLoading(false); return; }
    let live = true; setLoading(true); setError(''); setThread(null);
    async function load() {
      const [inbox, conversation] = await Promise.all([request(`messages?page=${inboxPage}`), id ? request(`messages/${id}?page=${page}`) : Promise.resolve(null)]);
      if (live) { setThreads(inbox.items); setInboxPages(inbox.pages); setThread(conversation); setLoading(false); }
      if (id && live) { await request(`messages/${id}/read`, 'PUT'); if (live) setThreads(old => old.map(t => t.id === id ? { ...t, unread: 0 } : t)); }
    }
    load().catch(e => { if (live) { setError(e.message); setLoading(false); } });
    const timer = setInterval(() => { if (document.visibilityState === 'visible') load().catch(e => { if (live) setError(e.message); }); }, 15000);
    return () => { live = false; clearInterval(timer); };
  }, [id, page, inboxPage, refresh, member]);
  useEffect(() => { setBlockNotice(''); }, [id]);
  const cannotSend = busy || loading || (id > 0 && (!thread || thread.blocked_by_me));
  const other = thread?.people.find(p => p.id !== member?.id);
  function navigate(threadId: number, messagePage = 1) { router.push(`/?view=messages&thread=${threadId}&messagePage=${messagePage}`, { scroll: false }); }
  if (!member) return <p className="notice"><Link href="/login">Sign in</Link> to see your private messages.</p>;
  return <section className="inbox"><div className="inbox-controls"><Link href="/?view=messages&compose=1" className="button"><PenLine aria-hidden="true" />New message</Link><button className="quiet" onClick={() => setRefresh(v => v + 1)}><RefreshCw aria-hidden="true" />Refresh</button><span className="muted">Only conversation participants can read messages.</span></div>{error && <p className="error" role="alert">{error}</p>}{blockNotice && <p role="status">{blockNotice}</p>}
    <div className="inbox-layout"><nav className="thread-list" aria-label="Private conversations">{threads.map(t => <Link className={id === t.id ? 'thread-link selected' : 'thread-link'} key={t.id} href={`/?view=messages&thread=${t.id}`}><strong>{t.people.filter(p => p.id !== member.id).map(p => '@' + p.handle).join(', ')}</strong>{t.unread > 0 && <span className="unread">{t.unread} unread</span>}<span>{t.preview.slice(0, 100)}</span></Link>)}{!threads.length && !loading && <p className="muted">No conversations yet.</p>}{inboxPages > 1 && <div className="pagination"><button disabled={inboxPage === 1} onClick={() => setInboxPage(p => p - 1)}>Previous</button><span>{inboxPage}/{inboxPages}</span><button disabled={inboxPage >= inboxPages} onClick={() => setInboxPage(p => p + 1)}>Next</button></div>}</nav>
      <div className="message-pane">{loading ? <p role="status">Loading messages…</p> : thread ? <><div className="message-heading"><h2>{other ? '@' + other.handle : 'Conversation'}</h2>{other && <button className="quiet" disabled={busy} onClick={async () => { setBusy(true); setError(''); try { await request(`members/${other.id}/block`, 'PUT', { blocked: !thread.blocked_by_me }); setBlockNotice(thread.blocked_by_me ? 'Member unblocked.' : 'Member blocked. Neither of you can send messages to the other.'); setRefresh(v => v + 1); } catch (e) { setError((e as Error).message); } finally { setBusy(false); } }}><ShieldBan aria-hidden="true" />{thread.blocked_by_me ? 'Unblock member' : 'Block member'}</button>}</div>{thread.pages > 1 && <div className="pagination"><button className="quiet" disabled={page >= thread.pages} onClick={() => navigate(id, page + 1)}>Older messages</button><button className="quiet" disabled={page <= 1} onClick={() => navigate(id, page - 1)}>Newer messages</button></div>}{thread.messages.map(m => <article key={m.id} className={`message ${m.sender_id === member.id ? 'own' : ''}`}><p className="post-meta">@{m.handle} · {new Date(m.created_at).toLocaleString('en-US', { timeZone: 'UTC' })} UTC</p><p>{m.content}</p></article>)}</> : <><h2>{to || params.has('compose') ? 'Start a conversation' : 'A quieter corner of Riffit.'}</h2><p className="muted">Send a note to one member using their public handle.</p></>}
        {thread?.blocked_by_me && <p className="notice">Unblock this member to send a message.</p>}<form key={`${id}:${to}`} onSubmit={async e => { e.preventDefault(); if (cannotSend) return; const form = e.currentTarget, data = new FormData(form); setBusy(true); setError(''); try { const result = await request(id ? `messages/${id}` : 'messages', 'POST', { content: data.get('content'), recipient: data.get('recipient') }); form.reset(); setBlockNotice(''); if (id) { if (page !== 1) navigate(id); setRefresh(v => v + 1); } else navigate(result.id); } catch (e) { setError((e as Error).message); } finally { setBusy(false); } }}>{!id && <><label htmlFor="recipient">Recipient handle (without @)</label><input id="recipient" name="recipient" required pattern="[a-zA-Z0-9_-]+" defaultValue={to} /></>}<label htmlFor="message-content">{id ? 'Your reply' : 'Your message'}</label><textarea id="message-content" name="content" required maxLength={10000} disabled={cannotSend} /><button disabled={cannotSend}><Send aria-hidden="true" />{busy ? 'Sending…' : 'Send message'}</button></form>
      </div></div></section>;
}
