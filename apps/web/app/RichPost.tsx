import MentionText from './MentionText';
export default function RichPost({content,rendered}:{content:string;rendered?:string}) {
 if(rendered!==undefined)return <div className="post-body rich-post" dangerouslySetInnerHTML={{__html:rendered}}/>;
 return <div className="post-body"><MentionText value={content.replace(/<[^>]*>/g,'')}/></div>;
}
