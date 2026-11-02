import { notFound } from 'next/navigation';
import Home from '../../CommunityPage';
export const dynamic = 'force-dynamic';
export default async function CommunityPage({params,searchParams}:{params:Promise<{slug:string}>;searchParams:Promise<Record<string,string|string[]|undefined>>}) { const {slug}=await params; if (!/^[a-z0-9][a-z0-9-]{1,39}$/.test(slug)) notFound(); return Home({searchParams,communitySlug:slug}); }
