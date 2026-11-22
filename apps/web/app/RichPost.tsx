"use client";
import type { KeyboardEvent, MouseEvent } from 'react';
import './rich-post.css';
import MentionText from './MentionText';
export default function RichPost({content,rendered}:{content:string;rendered?:string}) {
 function reveal(event:MouseEvent<HTMLDivElement>|KeyboardEvent<HTMLDivElement>){
  if('key' in event && !['Enter',' '].includes(event.key))return;
  const target=event.target instanceof Element?event.target.closest<HTMLElement>('.riffit-spoiler'):null;
  if(!target)return;if('key' in event)event.preventDefault();
  const open=target.classList.toggle('is-revealed');target.setAttribute('aria-expanded',String(open));target.setAttribute('aria-label',open?'Hide spoiler':'Reveal spoiler');
 }
 if(rendered!==undefined)return <div className="post-body rich-post" onClick={reveal} onKeyDown={reveal} dangerouslySetInnerHTML={{__html:rendered}}/>;
 return <div className="post-body"><MentionText value={content.replace(/<[^>]*>/g,'')}/></div>;
}
