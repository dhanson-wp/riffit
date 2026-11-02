# Contributing

Riffit is a WordPress-backed community starter under active development. Keep WordPress authoritative for member data and use the existing same-origin server boundary for mutations.

Run `npm ci`, `npm run typecheck`, `npm run build`, and `npm run test:wordpress` before submitting a code change. Add focused permission and persistence tests for changes to authentication or member-owned data. Test UI changes with keyboard navigation, narrow screens, and both themes.

Do not commit environment files, credentials, database exports, captured email links, or member content. Use synthetic fixture identities at example.test. Keep dependency lockfiles and existing third-party license notices.

Code and documentation contributions are provided under GPL-2.0-or-later. Contributions to the original mascot artwork collection are provided under CC0-1.0. Identify any third-party material and its license explicitly; don’t submit material you cannot redistribute.

Deployment is a separate operator action. A source push does not approve a frontend deployment, plugin installation, or public-access change.
