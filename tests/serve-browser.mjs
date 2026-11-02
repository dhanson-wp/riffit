import { readFile, writeFile } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const envPath = path.join(root, 'apps/web/.env.local');
const previous = await readFile(envPath, 'utf8');
const blueprint = { steps: [
  { step: 'activatePlugin', pluginPath: 'bbpress/bbpress.php' },
  { step: 'activatePlugin', pluginPath: 'buddypress/bp-loader.php' },
  { step: 'activatePlugin', pluginPath: 'riffit-core/riffit-core.php' },
  { step: 'runPHP', code: "<?php require '/wordpress/wp-load.php'; require '/riffit-tests/browser-fixture.php';" },
] };
const blueprintPath = path.join(root, 'artifacts/browser-fixture-blueprint.json');
await writeFile(blueprintPath, JSON.stringify(blueprint, null, 2));
await writeFile(envPath, 'WORDPRESS_URL=http://127.0.0.1:9400\nAPP_URL=http://localhost:3100\n');
const args = ['server', '--port=9400', `--blueprint=${blueprintPath}`];
for (const [host, virtual] of [
  ['artifacts/plugins/bbpress', '/wordpress/wp-content/plugins/bbpress'],
  ['artifacts/plugins/buddypress', '/wordpress/wp-content/plugins/buddypress'],
  ['plugins/riffit-core', '/wordpress/wp-content/plugins/riffit-core'],
  ['tests', '/riffit-tests'], ['artifacts', '/riffit-results'],
]) args.push(`--mount=${path.join(root, host)}:${virtual}`);
const child = spawn(path.join(root, 'node_modules/.bin/wp-playground-cli'), args, { cwd: root, stdio: 'inherit' });
let restored = false;
async function restore() { if (!restored) { restored = true; await writeFile(envPath, previous); } }
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => child.kill(signal));
child.on('exit', async code => { await restore(); process.exitCode = code || 0; });
child.on('error', async error => { console.error(error.message); await restore(); process.exitCode = 1; });
