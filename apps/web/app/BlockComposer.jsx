"use client";
import { useCallback, useEffect, useMemo, useState } from 'react';
import { BlockControls, BlockEditorProvider, BlockList, BlockSettingsMenu, BlockTools, RichTextToolbarButton, WritingFlow, store as blockEditorStore } from '@wordpress/block-editor';
import { init as initParagraph } from '@wordpress/block-library/build-module/paragraph/index.mjs';
import { init as initHeading } from '@wordpress/block-library/build-module/heading/index.mjs';
import { init as initList } from '@wordpress/block-library/build-module/list/index.mjs';
import { init as initListItem } from '@wordpress/block-library/build-module/list-item/index.mjs';
import { init as initQuote } from '@wordpress/block-library/build-module/quote/index.mjs';
import { init as initCode } from '@wordpress/block-library/build-module/code/index.mjs';
import { init as initSeparator } from '@wordpress/block-library/build-module/separator/index.mjs';
import { init as initImage } from '@wordpress/block-library/build-module/image/index.mjs';
import { init as initTable } from '@wordpress/block-library/build-module/table/index.mjs';
import { init as initVideo } from '@wordpress/block-library/build-module/video/index.mjs';
import { createBlock, getBlockType, parse, rawHandler, serialize, setDefaultBlockName, switchToBlockType, unregisterBlockVariation } from '@wordpress/blocks';
import { Dropdown, MenuGroup, MenuItem, Popover, SlotFillProvider, Toolbar, ToolbarButton, ToolbarGroup, TextControl } from '@wordpress/components';
import { useStateWithHistory } from '@wordpress/compose';
import { useSelect, useDispatch, select as selectStore, dispatch as dispatchStore } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as richTextStore, registerFormatType, unregisterFormatType, toggleFormat } from '@wordpress/rich-text';
import { Bold, ChevronDown, Code2, EyeOff, Heading2, Heading3, ImagePlus, Italic, Link2, List, ListOrdered, Minus, Pilcrow, Quote, Redo2, Table2, Undo2, Video } from 'lucide-react';
import '@wordpress/format-library';
import '@wordpress/components/build-style/style.css';
import '@wordpress/block-editor/build-style/style.css';
import '@wordpress/block-library/build-style/style.css';
import '@wordpress/block-library/build-style/editor.css';
import './editor.css';

const allowed = ['core/paragraph','core/heading','core/list','core/list-item','core/quote','core/code','core/separator','core/image','core/table','core/video'];
for (const [name,init] of [['paragraph',initParagraph],['heading',initHeading],['list',initList],['list-item',initListItem],['quote',initQuote],['code',initCode],['separator',initSeparator],['image',initImage],['table',initTable],['video',initVideo]]) if (!getBlockType(`core/${name}`)) init();
setDefaultBlockName('core/paragraph');
for(const name of ['core/keyboard','core/language','core/math']) if(selectStore(richTextStore).getFormatType(name)) unregisterFormatType(name);
[1,2,3,4,5,6].forEach(level=>unregisterBlockVariation('core/heading',`h${level}`));
if (!selectStore(richTextStore).getFormatType('riffit/spoiler')) registerFormatType('riffit/spoiler',{
  title:'Spoiler',tagName:'span',className:'riffit-spoiler',
  edit:({isActive,value,onChange,onFocus})=><RichTextToolbarButton icon={<EyeOff/>} title="Spoiler" isActive={isActive} onClick={()=>{onChange(toggleFormat(value,{type:'riffit/spoiler'}));onFocus();}} role="menuitemcheckbox"/>,
});
// This headless composer owns a curated media snapshot, not a wp-admin REST client.
const mediaStore=dispatchStore(coreStore);
mediaStore.addEntities([{kind:'postType',name:'attachment',baseURL:'/wp/v2/media',baseURLParams:{context:'view'},key:'id'}]);
mediaStore.finishResolution('getEntitiesConfig',['postType']);
function rememberImage({id,url,alt='',width,height}) {
  if(!id||!url)return;
  mediaStore.receiveEntityRecords('postType','attachment',{id,source_url:url,alt_text:alt,media_type:'image',media_details:{width,height,sizes:{}},title:{raw:'',rendered:''}}, {context:'view'});
  mediaStore.finishResolution('getEntityRecord',['postType','attachment',id,{context:'view'}]);
}
function rememberBlockMedia(blocks) {
  for(const block of blocks){if(block.name==='core/image')rememberImage(block.attributes);rememberBlockMedia(block.innerBlocks||[]);}
  return blocks;
}
function hydrate(content) {
  if (!content.trim()) return [createBlock('core/paragraph')];
  if (content.includes('<!-- wp:')) return parse(content);
  const html=/<[a-z][\s\S]*>/i.test(content)?content:content.split(/\n\n+/).map(p=>`<p>${p.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')}</p>`).join('');
  return rawHandler({HTML:html});
}
const styles=[
  {name:'core/paragraph',label:'Text',Icon:Pilcrow},
  ...[2,3,4,5,6].map(level=>({name:'core/heading',label:`Heading ${level}`,Icon:level===2?Heading2:Heading3,attributes:{level}})),
  {name:'core/list',label:'Bulleted list',Icon:List,attributes:{ordered:false}},
  {name:'core/list',label:'Numbered list',Icon:ListOrdered,attributes:{ordered:true}},
  {name:'core/quote',label:'Quote',Icon:Quote},
  {name:'core/code',label:'Code block',Icon:Code2},
];
function EditorToolbar({history}) {
  const {selected,root,ids,index}=useSelect(select=>{
    const store=select(blockEditorStore),selected=store.getSelectedBlock();
    const rootId=selected?store.getBlockHierarchyRootClientId(selected.clientId):null;
    return {selected,root:rootId?store.getBlock(rootId):null,ids:store.getSelectedBlockClientIds(),index:rootId?store.getBlockIndex(rootId)+1:store.getBlockCount()};
  },[]);
  const {insertBlocks,replaceBlocks,selectBlock,updateBlockAttributes}=useDispatch(blockEditorStore);
  function add(name,attributes={}) {const block=createBlock(name,attributes);insertBlocks(block,index);selectBlock(block.clientId);}
  function applyStyle(style) {
    if(!root){const block=createBlock(style.name,style.attributes);insertBlocks(block,index);selectBlock(block.clientId);return;}
    if(root.name===style.name){updateBlockAttributes(root.clientId,style.attributes||{});selectBlock(selected.clientId);return;}
    const blocks=switchToBlockType(root,style.name);
    if(!blocks)return;
    blocks[0].attributes={...blocks[0].attributes,...style.attributes};replaceBlocks(root.clientId,blocks);selectBlock(blocks[0].clientId);
  }
  const active=styles.find(s=>s.name===root?.name&&(s.name!=='core/heading'||s.attributes.level===root.attributes.level)&&(s.name!=='core/list'||s.attributes.ordered===!!root.attributes.ordered));
  return <div className="riffit-editor-tools"><Toolbar label="Writing tools" className="riffit-writing-toolbar">
    <ToolbarGroup><Dropdown className="riffit-text-style" popoverProps={{placement:'bottom-start',className:'riffit-editor-menu'}} renderToggle={({isOpen,onToggle})=><ToolbarButton label="Text style" aria-expanded={isOpen} onClick={onToggle}><Pilcrow aria-hidden="true"/><span>{active?.label||'Text'}</span><ChevronDown className="style-chevron" aria-hidden="true"/></ToolbarButton>} renderContent={({onClose})=><MenuGroup label="Text style">{styles.map(style=><MenuItem key={style.label} icon={<style.Icon/>} isSelected={active===style} disabled={!!root&&root.name!==style.name&&!switchToBlockType(root,style.name)} onClick={()=>{applyStyle(style);onClose();}}>{style.label}</MenuItem>)}</MenuGroup>}/></ToolbarGroup>
    <BlockControls.Slot group="inline"/>
    {!selected&&<ToolbarGroup>{[[Bold,'Bold'],[Italic,'Italic'],[Link2,'Link']].map(([Icon,label])=><ToolbarButton key={label} label={label} icon={<Icon/>} disabled/>)}</ToolbarGroup>}
    <ToolbarGroup className="riffit-insert-tools"><ToolbarButton label="Insert image" icon={<ImagePlus/>} onClick={()=>add('core/image')}/><ToolbarButton label="Insert video from URL" icon={<Video/>} onClick={()=>add('core/video')}/><ToolbarButton label="Quote" icon={<Quote/>} onClick={()=>applyStyle(styles.find(s=>s.name==='core/quote'))}/><ToolbarButton label="Insert table" icon={<Table2/>} onClick={()=>add('core/table')}/><ToolbarButton label="Insert divider" icon={<Minus/>} onClick={()=>add('core/separator')}/></ToolbarGroup>
    <ToolbarGroup className="riffit-editor-history"><ToolbarButton label="Undo" icon={<Undo2/>} disabled={!history.hasUndo} onClick={history.undo}/><ToolbarButton label="Redo" icon={<Redo2/>} disabled={!history.hasRedo} onClick={history.redo}/></ToolbarGroup>
    <BlockSettingsMenu clientIds={ids}/>
  </Toolbar><div className="riffit-block-options"><BlockControls.Slot group="block"/><BlockControls.Slot/><BlockControls.Slot group="other"/>{root?.name==='core/image'&&<TextControl label="Image description" value={root.attributes.alt||''} onChange={alt=>updateBlockAttributes(root.clientId,{alt})} help="Describe the image for people who can’t see it."/>}{root?.name==='core/video'&&<small className="riffit-video-help">Use a direct MP4, WebM, or Ogg URL. Share YouTube or Vimeo pages as links.</small>}</div></div>;
}
function Surface() {
  const blocks=useSelect(select=>select(blockEditorStore).getBlocks(),[]);
  const {selectBlock,insertBlocks}=useDispatch(blockEditorStore);
  return <div className="riffit-writing-canvas editor-styles-wrapper" onClick={e=>{
    if(e.target!==e.currentTarget)return;
    const last=blocks.at(-1);
    if(last?.name==='core/paragraph'&&!last.attributes.content)selectBlock(last.clientId);
    else{const block=createBlock('core/paragraph');insertBlocks(block);selectBlock(block.clientId);}
  }}><BlockTools><WritingFlow><BlockList renderAppender={false}/></WritingFlow></BlockTools></div>;
}
export default function BlockComposer({initialContent='',onChange=(_content)=>{},communityId=0,formId}) {
  const initialBlocks=useMemo(()=>rememberBlockMedia(hydrate(initialContent)),[]),history=useStateWithHistory(initialBlocks);
  const [uploading,setUploading]=useState(0),[error,setError]=useState('');
  const mediaUpload=useCallback(async({filesList,onFileChange,onError})=>{
    setError('');setUploading(n=>n+1);
    try{
      if(!communityId)throw new Error('Choose a community before uploading an image.');
      const membership=await fetch(`/api/community/communities/${communityId}/membership`,{method:'PUT'});
      if(!membership.ok){const result=await membership.json();throw new Error(result.message||'Could not join this community.');}
      const uploaded=[];
      for(const file of Array.from(filesList)){
        if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>5*1024*1024)throw new Error('Choose a JPEG, PNG, or WebP image up to 5 MB. Videos use a URL.');
        const data=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]);reader.onerror=()=>reject(new Error('Could not read this image.'));reader.readAsDataURL(file);});
        const response=await fetch(`/api/community/communities/${communityId}/media`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:file.name,data,alt:''})});
        const image=await response.json();if(!response.ok)throw new Error(image.message||'Could not upload this image.');
        rememberImage(image);uploaded.push({...image,type:'image',mime:file.type});onFileChange([...uploaded]);
      }
    }catch(e){const message=e instanceof Error?e.message:'Could not upload this image.';setError(message);onError?.({message});}finally{setUploading(n=>n-1);}
  },[communityId]);
  const settings=useMemo(()=>({allowedBlockTypes:allowed,__experimentalFetchLinkSuggestions:async()=>[],hasFixedToolbar:true,canLockBlocks:false,codeEditingEnabled:false,enableOpenverseMediaCategory:false,disableOpenverse:true,__experimentalCanUserUseUnfilteredHTML:false,richEditingEnabled:true,focusMode:false,colors:[],fontSizes:[],mediaUpload,allowedMimeTypes:{jpg:'image/jpeg',jpeg:'image/jpeg',png:'image/png',webp:'image/webp'},imageEditing:false,__experimentalBlockPatterns:[],__experimentalReusableBlocks:[],__experimentalFeatures:{typography:{customFontSize:false},color:{custom:false,customGradient:false},spacing:{padding:false,margin:false}}}),[mediaUpload]);
  useEffect(()=>{onChange(serialize(history.value));},[history.value,onChange]);
  const text=serialize(history.value).replace(/<[^>]*>/g,' ').trim(),words=text?text.split(/\s+/).length:0;
  return <div className="riffit-block-editor" onKeyDown={e=>{
    if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='z'){e.preventDefault();e.stopPropagation();if(e.shiftKey)history.redo();else history.undo();}
  }}><input type="hidden" name="editorUploading" form={formId} value={uploading?'1':'0'}/><SlotFillProvider><BlockEditorProvider value={history.value} settings={settings} onInput={value=>history.setValue(value,true)} onChange={value=>history.setValue(value,false)}><EditorToolbar history={history}/><Surface/><div className="riffit-editor-footer"><span>{words} {words===1?'word':'words'}</span><span>Type / to add a block</span></div>{uploading>0&&<p className="riffit-editor-status" role="status">Uploading image…</p>}{error&&<p className="riffit-editor-status error" role="alert">{error}</p>}<Popover.Slot/></BlockEditorProvider></SlotFillProvider></div>;
}
