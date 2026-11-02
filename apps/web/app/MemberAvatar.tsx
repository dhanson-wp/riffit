import { RIFFIT_AVATARS } from '../lib/avatars';
import './profile-settings.css';

/** Decorative by default when the member's name is already next to the image. */
export default function MemberAvatar({avatarId, name = '', size = 36, className = '', labelled = false}: {avatarId?: string; name?: string; size?: number; className?: string; labelled?: boolean}) {
  const avatar = RIFFIT_AVATARS.find(item => item.id === avatarId) || RIFFIT_AVATARS[0];
  return <span className={`member-avatar riffit-member-avatar ${className}`} style={{width:size,height:size}}><img src={avatar.src} width={size} height={size} alt={labelled ? `${name || 'Member'}’s avatar` : ''} loading="lazy" decoding="async" /></span>;
}
