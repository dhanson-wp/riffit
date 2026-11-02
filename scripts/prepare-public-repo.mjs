import {mkdtemp,readdir,lstat,readFile,writeFile,mkdir} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {copyPortableSource} from './source-files.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
await mkdir(path.join(root,'artifacts'),{recursive:true});
const target=await mkdtemp(path.join(root,'artifacts/public-repo-'));
await copyPortableSource(root,target);
const files=[],findings=[];
const patterns=[
 ['private-key',/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/],
 ['github-token',/\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{50,})\b/],
 ['api-token',/\b(?:sk-[A-Za-z0-9_-]{24,}|AKIA[A-Z0-9]{16})\b/],
 ['credential-url',/https?:\/\/[^\s/@:]+:[^\s/@]+@/],
 ['owner-deployment',/\b(?:spc_|rpc_|ver_)[a-f0-9]{20,}|https?:\/\/[^\s/]+\.wpcomstaging\.com|\/Users\/[^\s/]+/],
];
async function inspect(relative=''){
 for(const name of (await readdir(path.join(target,relative))).sort()){
  const rel=path.join(relative,name),info=await lstat(path.join(target,rel));
  if(info.isDirectory())await inspect(rel);
  else{
   const bytes=await readFile(path.join(target,rel));
   files.push({path:rel.split(path.sep).join('/'),bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')});
   if(!bytes.includes(0)&&!name.endsWith('.webp'))for(const [rule,pattern] of patterns)if(pattern.test(bytes.toString('utf8')))findings.push({path:rel,rule});
  }
 }
}
await inspect();
// Never emit matched values. Review findings locally before staging anything.
if(findings.length){console.error(JSON.stringify({target,findings},null,2));process.exit(1);}
function git(args){const x=spawnSync('git',args,{cwd:target,encoding:'utf8'});if(x.status!==0)throw new Error(x.stderr);return x.stdout;}
git(['init','--initial-branch=codex/public-repo-preparation']);
git(['add','--all']);
const tracked=git(['ls-files','-z']).split('\0').filter(Boolean).sort();
if(JSON.stringify(tracked)!==JSON.stringify(files.map(f=>f.path).sort()))throw new Error('Tracked files differ from the reviewed allowlist.');
const excluded=['apps/web/.env.local','.spacefast/state.json','artifacts/browser-test-email.txt','node_modules/example/index.js','apps/web/.next/server.js','docs/LAUNCH_REVIEW.md','docs/SOURCE_RECOVERY_BLOCKER.md'];
for(const candidate of excluded){const result=spawnSync('git',['check-ignore','--no-index',candidate],{cwd:target});if(result.status!==0)throw new Error('Missing ignore rule: '+candidate);}
const report={format:1,candidate:target,branch:'codex/public-repo-preparation',remoteCreated:false,committed:false,files,checks:{patternScan:'passed; heuristic, not a guarantee',trackedAllowlist:true,ignoreProbes:excluded,avatars:files.filter(f=>/^apps\/web\/public\/avatars\/.*\.webp$/.test(f.path)).length}};
await writeFile(path.join(root,'artifacts/public-repo-review.json'),JSON.stringify(report,null,2)+'\n');
await writeFile(path.join(root,'artifacts/PUBLIC_REPO_FILES.md'),'# Public repository candidate\n\nPrepared locally. No remote, commit, publication, or deployment was performed.\n\nCandidate: '+target+'\n\n'+files.length+' files; 20 outfit avatars plus the classic mascot. SHA-256 hashes are in public-repo-review.json.\n\n'+files.map(f=>'- `'+f.path+'` ('+f.bytes+' bytes)').join('\n')+'\n');
console.log(JSON.stringify({candidate:target,files:files.length,avatars:report.checks.avatars,report:'artifacts/public-repo-review.json',fileList:'artifacts/PUBLIC_REPO_FILES.md',remoteCreated:false,committed:false},null,2));
