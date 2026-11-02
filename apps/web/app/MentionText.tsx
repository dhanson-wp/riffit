import Link from 'next/link';
export default function MentionText({value}:{value:string}) {
  return <>{value.split(/((?<![a-zA-Z0-9_@])@[a-zA-Z0-9_-]{1,60}\b)/g).map((part,index)=>part.startsWith('@')&&/^@[a-zA-Z0-9_-]+$/.test(part)?<Link key={index} href={`/?view=profile&handle=${encodeURIComponent(part.slice(1))}`}>{part}</Link>:part)}</>;
}
