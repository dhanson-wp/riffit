import CommunityPage from './CommunityPage';
export const dynamic = 'force-dynamic';
export default function Home({searchParams}:{searchParams:Promise<Record<string,string|string[]|undefined>>}) { return CommunityPage({searchParams}); }
