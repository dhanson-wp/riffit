import { notFound } from 'next/navigation';
import Home from '../../CommunityPage';
export const dynamic = 'force-dynamic';
export default async function PostPage({params,searchParams}:{params:Promise<{id:string}>;searchParams:Promise<Record<string,string|string[]|undefined>>}) { const {id}=await params; if (!/^[1-9]\d*$/.test(id) || !Number.isSafeInteger(Number(id))) notFound(); return Home({searchParams:Promise.resolve({...await searchParams,post:id})}); }
