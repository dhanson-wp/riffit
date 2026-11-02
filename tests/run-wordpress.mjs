import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const artifacts = path.join(root, 'artifacts');
await mkdir(artifacts, { recursive: true });
const download = async (slug, version) => {
  const zip = path.join(artifacts, `${slug}-${version}.zip`);
  try { await readFile(zip); } catch {
    const response = await fetch(`https://downloads.wordpress.org/plugin/${slug}.${version}.zip`);
    if (!response.ok) throw new Error(`Could not download ${slug}: ${response.status}`);
    await writeFile(zip, Buffer.from(await response.arrayBuffer()));
  }
  const result = spawnSync('unzip', ['-oq', zip, '-d', path.join(artifacts, 'plugins')], { encoding: 'utf8' });
  if (result.status) throw new Error(result.stderr);
};
await download('bbpress', '2.6.21');
await download('buddypress', '14.5.4');
const blueprint = {
  preferredVersions: { php: '8.3', wp: 'latest' },
  steps: [
    { step: 'activatePlugin', pluginPath: 'bbpress/bbpress.php' },
    { step: 'activatePlugin', pluginPath: 'buddypress/bp-loader.php' },
    { step: 'activatePlugin', pluginPath: 'riffit-core/riffit-core.php' },
    { step: 'runPHP', code: "<?php require '/wordpress/wp-load.php'; require '/riffit-tests/integration.php';" },
  ],
};
const blueprintPath = path.join(artifacts, 'integration-blueprint.json');
await writeFile(blueprintPath, JSON.stringify(blueprint, null, 2));
const resultPath = path.join(artifacts, 'wordpress-results.json');
await writeFile(resultPath, JSON.stringify({ passed: false, error: 'The integration run did not finish.' }));
const binary = path.join(root, 'node_modules', '.bin', 'wp-playground-cli');
const args = ['run-blueprint', `--blueprint=${blueprintPath}`, '--verbosity=normal'];
for (const [host, virtual] of [
  [path.join(artifacts, 'plugins', 'bbpress'), '/wordpress/wp-content/plugins/bbpress'],
  [path.join(artifacts, 'plugins', 'buddypress'), '/wordpress/wp-content/plugins/buddypress'],
  [path.join(root, 'plugins', 'riffit-core'), '/wordpress/wp-content/plugins/riffit-core'],
  [path.join(root, 'tests'), '/riffit-tests'],
  [artifacts, '/riffit-results'],
]) args.push(`--mount=${host}:${virtual}`);
const run = spawnSync(binary, args, { cwd: root, stdio: 'inherit', env: process.env });
const report = JSON.parse(await readFile(resultPath, 'utf8'));
if (run.status || !report.passed || report.count < 30) {
  console.error(JSON.stringify(report, null, 2));
  process.exit(1);
}
console.log(`Riffit WordPress integration: ${report.count} checks passed.`);
