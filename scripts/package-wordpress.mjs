import {mkdtemp,readFile,readdir,lstat,mkdir,rename,rm} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import os from 'node:os';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const plugin=path.join(root,'plugins/riffit-core');
const header=await readFile(path.join(plugin,'riffit-core.php'),'utf8');
const version=header.match(/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$/m)?.[1];
if(!version)throw new Error('Riffit Core has no valid package version.');
const files=[];
async function collect(relative=''){
  for(const name of (await readdir(path.join(plugin,relative))).sort()){
    const child=path.join(relative,name),info=await lstat(path.join(plugin,child));
    if(info.isSymbolicLink())throw new Error(`Refusing plugin symlink: ${child}`);
    if(info.isDirectory())await collect(child);
    else if(info.isFile()&&(name.endsWith('.php')||child==='readme.txt'||child==='LICENSE'))files.push('riffit-core/'+child.split(path.sep).join('/'));
    else throw new Error(`Unexpected file in plugin package: ${child}`);
  }
}
await collect();
const staging=await mkdtemp(path.join(os.tmpdir(),'riffit-plugin-'));
try{
  const temporary=path.join(staging,'riffit-core.zip');
  const result=spawnSync('zip',['-X','-q',temporary,...files],{cwd:path.dirname(plugin),encoding:'utf8'});
  if(result.error)throw new Error('Packaging requires the zip command: '+result.error.message);
  if(result.status!==0)throw new Error(result.stderr||'The plugin archive could not be created.');
  const verify=spawnSync('unzip',['-tq',temporary],{encoding:'utf8'});
  if(verify.error||verify.status!==0)throw new Error('The plugin archive integrity check failed. Install unzip and retry.');
  const bytes=await readFile(temporary);
  const output=path.join(root,'artifacts',`riffit-core-${version}.zip`);
  await mkdir(path.dirname(output),{recursive:true});
  await rename(temporary,output);
  console.log(JSON.stringify({path:output,version,files:files.length,bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')},null,2));
}finally{await rm(staging,{recursive:true,force:true});}
