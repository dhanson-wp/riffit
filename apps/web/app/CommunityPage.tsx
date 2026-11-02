import { cookies } from 'next/headers';
import { notFound } from 'next/navigation';
import { currentMember, sessionCookie, wordpress } from '../lib/auth';
import CommunityApp, { InitialData } from './CommunityApp';

class MissingCommunityPage extends Error {}
type Search = Record<string, string | string[] | undefined>;

export default async function Home({ searchParams, communitySlug }: {
  searchParams: Promise<Search>; communitySlug?: string;
}) {
  const raw = await searchParams, query = new URLSearchParams();
  for (const [key, value] of Object.entries(raw)) if (typeof value === 'string') query.set(key, value);
  query.delete('layout');
  const token = (await cookies()).get(sessionCookie)?.value;

  async function read(path: string) {
    let response = await wordpress(path, { headers: token ? { Authorization: `Bearer ${token}` } : {} });
    if (response.status === 401 && !path.startsWith('/saved') && !path.startsWith('/messages')) response = await wordpress(path);
    const data = await response.json();
    if (response.status === 404 && /^\/(posts\/\d+|communities\/(\d+|by-slug\/[^/?]+)|profiles\/[^/?]+)(?:\?|$)/.test(path)) throw new MissingCommunityPage();
    if (!response.ok) throw new Error(data.message || 'The community service is unavailable.');
    return data;
  }
  function feedPath() {
    const filters = new URLSearchParams(query);
    for (const key of ['view', 'post', 'handle', 'replyPage', 'comment']) filters.delete(key);
    return (query.get('view') === 'saved' ? '/saved' : '/posts') + '?' + filters;
  }

  let member = null;
  const initial: InitialData = {
    wordpressConnect: false, communities: [], community: null,
    feed: { items: [], total: 0, page: 1, pages: 0 }, active: null,
    replies: { items: [], total: 0, page: 1, pages: 0 }, profile: null,
    error: '', route: query.toString(),
  };
  try {
    for (const key of ['post', 'community']) {
      const value = query.get(key);
      if (value !== null && (!/^[1-9]\d*$/.test(value) || !Number.isSafeInteger(Number(value)))) throw new MissingCommunityPage();
    }
    const view = query.get('view') || 'feed', post = query.get('post');
    const hasFeed = !['communities', 'messages', 'notifications', 'moderation', 'profile', 'account'].includes(view);
    const replyQuery = new URLSearchParams({ page: query.get('replyPage') || '1', focus: query.get('comment') || '0' });

    // These reads have no dependency on navigation or authentication-option results.
    // Keep them in the same batch rather than adding a backend round trip per route.
    const [identity, communities, options, community, content] = await Promise.all([
      currentMember(),
      read('/communities'),
      read('/auth/options').catch(() => ({ wordpress: false })),
      communitySlug ? read('/communities/by-slug/' + encodeURIComponent(communitySlug)) : Promise.resolve(null),
      post ? Promise.all([read('/posts/' + post), read(`/posts/${post}/replies?${replyQuery}`)])
        : view === 'profile' ? read('/profiles/' + encodeURIComponent(query.get('handle') || '') + '?page=' + (query.get('page') || '1'))
        : hasFeed && !communitySlug ? read(feedPath()) : Promise.resolve(null),
    ]);
    member = identity;
    if(view === 'account' && member) initial.profile = await read('/profiles/' + encodeURIComponent(member.handle));
    initial.communities = communities.items;
    initial.wordpressConnect = !!options.wordpress;
    if (community) {
      initial.community = community;
      query.set('community', String(community.id));
      initial.route = query.toString();
    }
    if (post) {
      const [active, replies] = content;
      initial.active = active;
      initial.replies = replies;
      initial.community = initial.communities.find(c => c.id === active.community_id) || await read('/communities/' + active.community_id);
    } else {
      const id = Number(query.get('community'));
      if (id && !initial.community) initial.community = initial.communities.find(c => c.id === id) || await read('/communities/' + id);
      if (view === 'profile') { initial.profile = content; initial.feed = content.posts; }
      else if (hasFeed) initial.feed = communitySlug ? await read(feedPath()) : content;
    }
  } catch (error) {
    if (error instanceof MissingCommunityPage) notFound();
    initial.error = error instanceof Error && /^(Sign in|Please sign in|Community not found|Post not found|Member not found|Comment not found)/.test(error.message)
      ? error.message : 'The community service is unavailable. Please try again shortly.';
  }
  return <CommunityApp member={member} initial={initial} />;
}
