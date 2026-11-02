import {copyFile,mkdir,readdir,lstat} from 'node:fs/promises';
import path from 'node:path';
// Only portable source belongs in a public snapshot or deployment build context.
export const sourcePaths = [
  'package.json','package-lock.json','.gitignore','README.md','LICENSE','LICENSES',
  'CONTRIBUTING.md','SECURITY.md','THIRD_PARTY_NOTICES.md','plugins/riffit-core','tests','scripts',
  ...['SETUP.md','ARCHITECTURE.md','WORDPRESS_CONNECT.md','DEPLOYMENT.md','STATUS.md','avatar-art-manifest.json'].map(n=>'docs/'+n),
  ...['package.json','tsconfig.json','next-env.d.ts','next.config.ts','open-next.config.ts','opennext-worker.jsonc','middleware.ts','.env.example','app','lib','public'].map(n=>'apps/web/'+n),
];
export async function copyPortableSource(root,target) {
  async function copy(relative) {
    const source=path.join(root,relative), info=await lstat(source);
    if(info.isSymbolicLink())throw new Error('Refusing symlink: '+relative);
    if(info.isDirectory()){
      await mkdir(path.join(target,relative),{recursive:true});
      for(const name of (await readdir(source)).sort()) await copy(path.join(relative,name));
    } else if(info.isFile()){
      if(relative.split(path.sep).some(p=>['node_modules','artifacts','.git','.spacefast','.next','.next-dev','.open-next'].includes(p)) || (path.basename(relative).startsWith('.env')&&path.basename(relative)!=='.env.example'))throw new Error('Forbidden source path: '+relative);
      await mkdir(path.dirname(path.join(target,relative)),{recursive:true});
      await copyFile(source,path.join(target,relative));
    } else throw new Error('Unsupported source: '+relative);
  }
  for(const relative of sourcePaths)await copy(relative);
}
