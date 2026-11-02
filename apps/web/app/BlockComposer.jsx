"use client";
import { useEffect, useMemo } from 'react';
import { BlockEditorProvider, BlockList, BlockTools, WritingFlow, HeadingLevelDropdown, store as blockEditorStore } from '@wordpress/block-editor';
import { init as initParagraph } from '@wordpress/block-library/build-module/paragraph/index.mjs';
import { init as initHeading } from '@wordpress/block-library/build-module/heading/index.mjs';
import { init as initList } from '@wordpress/block-library/build-module/list/index.mjs';
import { init as initListItem } from '@wordpress/block-library/build-module/list-item/index.mjs';
import { init as initQuote } from '@wordpress/block-library/build-module/quote/index.mjs';
import { init as initCode } from '@wordpress/block-library/build-module/code/index.mjs';
import { init as initSeparator } from '@wordpress/block-library/build-module/separator/index.mjs';
import { createBlock, getBlockType, parse, rawHandler, serialize, setDefaultBlockName, unregisterBlockVariation } from '@wordpress/blocks';
import { Button, Popover, SlotFillProvider } from '@wordpress/components';
import { useStateWithHistory } from '@wordpress/compose';
import { useSelect, useDispatch } from '@wordpress/data';
import { Undo2, Redo2 } from 'lucide-react';
import '@wordpress/format-library';
import '@wordpress/components/build-style/style.css';
import '@wordpress/block-editor/build-style/style.css';
import '@wordpress/block-library/build-style/style.css';
import '@wordpress/block-library/build-style/editor.css';
import './editor.css';

const allowed = ['core/paragraph','core/heading','core/list','core/list-item','core/quote','core/code','core/separator'];
if (!getBlockType('core/paragraph')) {
  [initParagraph,initHeading,initList,initListItem,initQuote,initCode,initSeparator].forEach(init=>init());
  setDefaultBlockName('core/paragraph');
  [1,2,3,4,5,6].forEach(level => unregisterBlockVariation('core/heading',`h${level}`));
}
function hydrate(content) {
  if (!content.trim()) return [createBlock('core/paragraph',{placeholder:'What’s on your mind? Start writing…'})];
  if (content.includes('<!-- wp:')) return parse(content);
  const html = /<[a-z][\s\S]*>/i.test(content) ? content : content.split(/\n\n+/).map(p=>`<p>${p.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')}</p>`).join('');
  return rawHandler({HTML:html});
}
function HeadingControls() {
  const block=useSelect(select=>select(blockEditorStore).getSelectedBlock(),[]);
  const {updateBlockAttributes}=useDispatch(blockEditorStore);
  return block?.name==='core/heading'?<HeadingLevelDropdown options={[2,3,4,5,6]} value={block.attributes.level} onChange={level=>updateBlockAttributes(block.clientId,{level})}/>:null;
}
function Surface() {
  const blocks = useSelect(select=>select(blockEditorStore).getBlocks(),[]);
  const { selectBlock, insertBlocks } = useDispatch(blockEditorStore);
  return <div className="riffit-writing-canvas editor-styles-wrapper" onClick={e=>{
    if(e.target!==e.currentTarget)return;
    const last=blocks.at(-1);
    if(last?.name==='core/paragraph' && !last.attributes.content)selectBlock(last.clientId);
    else {const block=createBlock('core/paragraph');insertBlocks(block);selectBlock(block.clientId);}
  }}><BlockTools><WritingFlow><BlockList renderAppender={false}/></WritingFlow></BlockTools></div>;
}
export default function BlockComposer({ initialContent='', onChange=(_content)=>{} }) {
  const initialBlocks=useMemo(()=>hydrate(initialContent),[]);
  const history=useStateWithHistory(initialBlocks);
  const settings=useMemo(()=>({allowedBlockTypes:allowed,hasFixedToolbar:false,canLockBlocks:false,codeEditingEnabled:false,enableOpenverseMediaCategory:false,disableOpenverse:true,__experimentalCanUserUseUnfilteredHTML:false,richEditingEnabled:true,focusMode:false,colors:[],fontSizes:[],__experimentalFeatures:{typography:{customFontSize:false},color:{custom:false,customGradient:false},spacing:{padding:false,margin:false}}}),[]);
  useEffect(()=>{onChange(serialize(history.value));},[history.value,onChange]);
  return <div className="riffit-block-editor" onKeyDown={e=>{
    if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='z'){e.preventDefault();e.stopPropagation();if(e.shiftKey)history.redo();else history.undo();}
  }}><SlotFillProvider><BlockEditorProvider value={history.value} settings={settings} onInput={value=>history.setValue(value,true)} onChange={value=>history.setValue(value,false)}><div className="writing-actions"><Button type="button" label="Undo" icon={<Undo2/>} disabled={!history.hasUndo} onClick={history.undo}/><Button type="button" label="Redo" icon={<Redo2/>} disabled={!history.hasRedo} onClick={history.redo}/><HeadingControls/><span>Type / to add a block</span></div><Surface/><Popover.Slot/></BlockEditorProvider></SlotFillProvider></div>;
}
