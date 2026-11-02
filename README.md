# Riffit

Riffit is a community app with a Spacefast frontend and a WordPress backend. bbPress stores discussions, BuddyPress provides communities and private messages, and Riffit Core supplies the product APIs.

This is a development starter. The current revision is locally tested and still needs hosted release verification. See [status and limits](docs/STATUS.md).

## What’s included

- Communities with custom logos and banners; individual text, link, image, poll, and crosspost pages.
- Riff/Skip voting, threaded replies, flair, mentions, badges, saved posts, and moderation.
- A simplified Gutenberg composer, browser drafts, and a curated block set.
- Public profiles, 20 gecko outfit avatars plus the classic mascot, and light/dark/system appearance.
- Email sign-in for members, WordPress.com provider support for site administrators, and revocable sessions.

## Start locally

Use Node 22 or newer, PHP 8.2 or newer on your WordPress backend, and the zip/unzip tools for packaging and fixture tests.

```sh
npm ci
cp apps/web/.env.example apps/web/.env.local
# Set WORDPRESS_URL and APP_URL for your own installation.
npm run package:wordpress
npm run dev
```

Install bbPress, BuddyPress, and the generated Riffit Core ZIP on your plugin-capable WordPress site. Follow [setup](docs/SETUP.md) and [WordPress.com sign-in](docs/WORDPRESS_CONNECT.md). The frontend never needs an administrator application password to impersonate members.

## Checks and packaging

```sh
npm run typecheck
npm run build
npm run test:wordpress
npm run export:starter
```

The integration suite uses disposable WordPress Playground and downloads its WordPress dependencies. For local browser fixtures, run `npm run test:wordpress` first, configure the example environment, then run `npm run test:browser:backend` and `npm run dev` in separate terminals. `npm run test:http` requires that seeded local fixture. Fixture email links are credentials; keep the entire artifacts directory local.

## Project layout

- `apps/web/`: Next.js frontend and same-origin server boundary.
- `plugins/riffit-core/`: installable WordPress plugin source.
- `tests/`: disposable backend integration and local HTTP checks.
- `scripts/`: packaging and source preparation.
- `docs/`: portable setup, architecture, and deployment documentation.

Spacefast hosts the frontend. WordPress owns member data. Each remix uses its own backend, mail transport, identity provider configuration, and Spacefast destination. GitHub is source hosting only; nothing here connects it to WordPress or automatically deploys a push. See [deployment](docs/DEPLOYMENT.md).

## License and artwork

Original code and documentation are GPL-2.0-or-later; see [LICENSE](LICENSE). The original gecko WebP and 20 outfit WebPs are dedicated under CC0-1.0 to the extent rights are held; see [artwork terms](apps/web/public/ARTWORK_LICENSE.md). They were generated with AI, and no exclusive copyright or trademark clearance is claimed. Third-party notices remain applicable; see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

See [contributing](CONTRIBUTING.md) and [security](SECURITY.md) before opening an issue or submitting changes.
