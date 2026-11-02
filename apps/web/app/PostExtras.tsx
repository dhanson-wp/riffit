"use client";
import Link from 'next/link';
export type Poll = { options: string[]; counts: number[]; total: number; choice: number | null; closed: boolean; closes_at: string };
export type ContentPost = { id: number; kind: string; url?: string; image?: {url: string | false; alt: string}; source?: {id:number;title:string;community_id:number}|null; poll?: Poll };
export default function PostExtras({ post, compact = false, busy = false, vote }: {post: ContentPost; compact?: boolean; busy?: boolean; vote?: (choice:number)=>void}) {
  if (post.kind === 'link' && post.url) return <a className="external-link" href={post.url} target="_blank" rel="noopener noreferrer nofollow ugc">{compact ? new URL(post.url).hostname : post.url}<span aria-hidden="true"> ↗</span></a>;
  if (post.kind === 'image' && post.image?.url) return compact ? <span className="post-kind-label">Image</span> : <figure className="post-image"><img src={post.image.url} alt={post.image.alt} loading="lazy" decoding="async" /></figure>;
  if (post.kind === 'crosspost') return post.source ? <Link className="crosspost-source" href={`/posts/${post.source.id}`}>Crossposted from: {post.source.title}</Link> : <p className="muted">The original post is no longer available.</p>;
  if (post.kind !== 'poll' || !post.poll) return null;
  const poll = post.poll;
  if (compact) return <span className="post-kind-label">Poll · {poll.total} {poll.total === 1 ? 'vote' : 'votes'} · {poll.closed ? 'Closed' : 'Open'}</span>;
  return <section className="poll" aria-label="Post poll"><p className="muted">{poll.closed ? 'Poll closed' : `Closes ${new Date(poll.closes_at).toLocaleString('en-US', {timeZone:'UTC'})} UTC`} · {poll.total} {poll.total === 1 ? 'vote' : 'votes'}</p>{poll.options.map((option,i) => <button key={i} className="poll-option" aria-pressed={poll.choice === i} disabled={busy || poll.closed} onClick={() => vote?.(i)}><span className="poll-fill" style={{width:`${poll.total ? poll.counts[i]/poll.total*100 : 0}%`}}/><span>{option}{poll.choice === i ? ' (your choice)' : ''}</span><strong>{poll.counts[i]} · {poll.total ? Math.round(poll.counts[i]/poll.total*100) : 0}%</strong></button>)}{!poll.closed && <p className="muted">One vote per member. You can change your choice while the poll is open.</p>}</section>;
}
