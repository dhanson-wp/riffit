"use client";
import {useState} from 'react';
import Link from 'next/link';
import {Check, ChevronDown, RotateCcw} from 'lucide-react';
import {RIFFIT_AVATARS} from '../lib/avatars';
import MemberAvatar from './MemberAvatar';
import './profile-settings.css';

type Profile = {name:string;handle:string;bio:string;avatar_id?:string};
type Values = {name:string;bio:string;avatar_id:string};
function valuesFor(profile: Profile | null): Values {return {name:profile?.name || '',bio:profile?.bio || '',avatar_id:RIFFIT_AVATARS.some(item=>item.id===profile?.avatar_id) ? profile!.avatar_id! : 'original'};}

export default function ProfileSettings({profile,changed}:{profile:Profile|null;changed:(values:Values)=>void}) {
  const [saved,setSaved]=useState(()=>valuesFor(profile));
  const [values,setValues]=useState(()=>valuesFor(profile));
  const [busy,setBusy]=useState(false),[error,setError]=useState(''),[notice,setNotice]=useState('');
  const dirty=values.name!==saved.name || values.bio!==saved.bio || values.avatar_id!==saved.avatar_id;
  const avatar=RIFFIT_AVATARS.find(item=>item.id===values.avatar_id) || RIFFIT_AVATARS[0];
  function edit(next: Partial<Values>) {setValues(old=>({...old,...next}));setNotice('');setError('');}
  if(!profile) return <p role="status">Loading profile…</p>;
  return <section className="profile-settings" aria-labelledby="public-profile-heading">
    <div className="profile-settings-heading"><MemberAvatar avatarId={values.avatar_id} size={64}/><div><h3 id="public-profile-heading">Public profile</h3><p>@{profile.handle}</p></div><Link href={`/?view=profile&handle=${encodeURIComponent(profile.handle)}`}>View profile</Link></div>
    <p className="profile-settings-intro">Choose how you show up in the conversation.</p>
    <form onSubmit={async event=>{
      event.preventDefault();if(busy || !dirty)return;
      setBusy(true);setError('');setNotice('');
      try {
        const response=await fetch('/api/community/profile',{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(values)});
        const data=await response.json();
        if(!response.ok)throw new Error(data.message||'Could not save your profile.');
        const next={name:data.name,bio:data.bio,avatar_id:data.avatar_id} as Values;
        setValues(next);setSaved(next);setNotice('Profile saved.');changed(next);
      } catch(error) {setError((error as Error).message || 'Could not save your profile. Your changes are still here.');}
      finally {setBusy(false);}
    }}>
      <fieldset disabled={busy} className="profile-fields">
        <details className="profile-avatar-picker"><summary><span><strong>Your gecko</strong><span>{avatar.name} · 20 outfits</span></span><ChevronDown aria-hidden="true"/></summary>
          <fieldset className="avatar-choices"><legend className="sr-only">Choose a gecko outfit</legend>{RIFFIT_AVATARS.map(item=><label className={`avatar-choice ${values.avatar_id===item.id?'is-selected':''}`} key={item.id}><input type="radio" name="avatar_id" value={item.id} checked={values.avatar_id===item.id} onChange={()=>edit({avatar_id:item.id})}/><span className="avatar-choice-art"><img src={item.src} alt="" width="80" height="80" loading="lazy" decoding="async"/>{values.avatar_id===item.id&&<Check aria-hidden="true"/>}</span><span>{item.name}</span></label>)}</fieldset>
        </details>
        <label htmlFor="profile-name">Display name</label><input id="profile-name" name="name" required maxLength={60} value={values.name} onChange={event=>edit({name:event.target.value})} autoComplete="nickname" aria-describedby="profile-name-note"/><p id="profile-name-note" className="profile-field-help">Your public name. Your handle stays @{profile.handle}.</p>
        <label htmlFor="profile-bio">About you</label><textarea id="profile-bio" name="bio" rows={4} maxLength={500} value={values.bio} onChange={event=>edit({bio:event.target.value})} placeholder="What are you into?" aria-describedby="profile-bio-note"/><div id="profile-bio-note" className="profile-field-note"><span>Your avatar, name, and bio are public.</span><span>{values.bio.length}/500</span></div>
      </fieldset>
      <div className="profile-save-row"><button type="submit" disabled={busy||!dirty||!values.name.trim()}>{busy?'Saving…':'Save profile'}</button><button type="button" className="quiet profile-reset" disabled={busy||!dirty} onClick={()=>{setValues({...saved});setNotice('Changes discarded.');setError('');}}><RotateCcw aria-hidden="true"/>Discard changes</button></div>
      {error&&<p role="alert" className="error">{error}</p>}<p role="status" aria-live="polite" className="profile-save-status">{notice || (dirty?'You have unsaved changes.':'')}</p>
    </form>
  </section>;
}
