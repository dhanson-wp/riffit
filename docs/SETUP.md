# Set up your own Riffit

Riffit’s frontend and WordPress backend are separate applications. Every remix needs its own WordPress database and frontend origin. No members, posts, or credentials are included in the source.

## Prepare the backend

1. Use a plugin-capable WordPress site with PHP 8.2 or newer. A free WordPress.com site cannot install the required plugins.
2. Install and activate bbPress and BuddyPress, then run `npm ci` and `npm run package:wordpress` in this source project.
3. Upload the resulting `artifacts/riffit-core-VERSION.zip` through Plugins → Add new plugin → Upload plugin, and activate it. The ZIP contains only Riffit Core, with the correct top-level plugin folder.
4. Open Settings → Riffit in WP Admin. Set Frontend URL to the exact HTTPS origin of your Spacefast app, with no path or query string. Local development may use `http://localhost:3100` on a disposable backend.
5. Confirm that the backend can deliver transactional email to a real inbox. Do not assume a successful mail API response proves delivery.

Activation enables the BuddyPress groups, messages, profiles, activity, and notifications components used by the product. Community creation provisions its linked bbPress forum. The custom plugin owns votes, saved posts, poll votes, reports, blocks, and scoped sign-in sessions. Do not enable unrestricted WordPress administration access for frontend users.

## Run the frontend

1. Use Node 22 or newer and run `npm ci` at the workspace root.
2. Copy `apps/web/.env.example` to `apps/web/.env.local`.
3. Set `WORDPRESS_URL` to your backend origin and `APP_URL` to the frontend origin. These values are public configuration, not credentials. Keep local environment files out of source uploads.
4. Run `npm run dev` and visit `http://localhost:3100`. For browser-bound email sign-in, request and open the link in the same browser, then use the explicit confirmation button.

Use a disposable backend for local development. Changing the configured frontend URL on a live backend changes where its sign-in emails point.

## Verify a clean installation

Run `npm run build` and `npm run test:wordpress`. The integration command downloads the pinned bbPress/BuddyPress archives and starts an isolated WordPress Playground instance. It does not connect to the hosted backend. Its report is written to `artifacts/wordpress-results.json`.

For manual browser checks, first run `npm run test:wordpress` to prepare the pinned plugin archives, then run `npm run test:browser:backend` alongside `npm run dev`. This fixture temporarily replaces `.env.local`, intercepts all outbound email, and seeds disposable content. Stop the fixture normally to restore the previous configuration. Never deploy the fixture or captured email.

## Deploy and remix

Follow [DEPLOYMENT.md](DEPLOYMENT.md) for the supported Spacefast build settings. Upload only the source staging directory returned by `npm run stage:spacefast`, which excludes environment files, dependencies, caches, and test artifacts.

A successful source-archive build deploys an app but does not by itself create a remixable source repository. Confirm hosted source recovery and source availability separately before describing a Space as remixable. Source sharing and public website access are separate controls.

WordPress.com sign-in is implemented but requires configuration and hosted verification. Google remains deferred. Provider buttons stay hidden while unconfigured. Each WordPress.com setup uses the remix owner’s own application and redirect URL; see [WORDPRESS_CONNECT.md](WORDPRESS_CONNECT.md). Gravatar login remains future work.

## Local route verification

With the frontend and disposable WordPress fixture running, run `npm run test:http`. It checks valid server-rendered pages, malformed identifiers, missing communities/posts, HTTP 404 responses, and primary headings. It is restricted to localhost and requires seeded fixture posts; it does not authenticate or write production content.


### Local 0.9.0 authentication policy

Email links remain available to ordinary members. WordPress site administrators must use WordPress.com provider sign-in; configure it before upgrading a backend on which administrators need to participate in the Riffit frontend. Enable two-step authentication on the administrator's WordPress.com account and verify the actual hosted login. The plugin does not receive an MFA attestation from the provider and does not claim to enforce MFA for community moderators. Normal WordPress admin access is unchanged. Google and Gravatar login remain deferred.
