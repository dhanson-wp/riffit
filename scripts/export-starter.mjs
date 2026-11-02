import {mkdtemp,readdir,lstat,readFile,writeFile,mkdir,copyFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import path from 'node:path';
import os from 'node:os';
import {fileURLToPath} from 'node:url';
import {copyPortableSource} from './source-files.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const target=await mkdtemp(path.join(os.tmpdir(),'riffit-starter-'));
await copyPortableSource(root,target);
const files=[];
async function inspect(relative=''){
 for(const name of (await readdir(path.join(target,relative))).sort()){
  const child=path.join(relative,name),info=await lstat(path.join(target,child));
  if(info.isDirectory())await inspect(child);
  else{const bytes=await readFile(path.join(target,child));files.push({path:child.split(path.sep).join('/'),bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')});}
 }
}
await inspect();
await writeFile(path.join(target,'SOURCE_MANIFEST.json'),JSON.stringify({format:1,files},null,2)+'\n');
const archive=path.join(os.tmpdir(),path.basename(target)+'.zip');
const result=spawnSync('zip',['-X','-q','-r',archive,'.'],{cwd:target,encoding:'utf8'});
if(result.error||result.status!==0)throw new Error(result.stderr||'Source packaging requires zip.');
const output=path.join(root,'artifacts/riffit-starter.zip');
await mkdir(path.dirname(output),{recursive:true});await copyFile(archive,output);
const bytes=await readFile(output);
console.log(JSON.stringify({directory:target,archive:output,files:files.length+1,bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')},null,2));
