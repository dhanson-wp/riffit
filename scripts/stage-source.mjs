import {mkdtemp} from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import {fileURLToPath} from 'node:url';
import {copyPortableSource} from './source-files.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const stage=await mkdtemp(path.join(os.tmpdir(),'riffit-source-'));
await copyPortableSource(root,stage);
console.log(stage);
