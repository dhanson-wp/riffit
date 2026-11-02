# Deployment

Spacefast hosts the frontend. Your plugin-capable WordPress installation hosts bbPress, BuddyPress, and Riffit Core. GitHub does not connect to or deploy the WordPress site.

## Frontend

Configure your Space with its own WORDPRESS_URL and APP_URL. Build from the repository root with dependencies installed using `npm ci`; use app root `apps/web`, build command `npm run build:spacefast`, and output `.open-next`. See the current https://spacefast.com/docs/recipes/next recipe before changing adapters or runtime versions.

For agent-run publishing, run `npm run stage:spacefast` and publish only the returned staged directory to the intended existing Space. Configure Space and team identifiers through the operator’s authenticated deployment tooling, never bundled source credentials. Verify the build receipt, intended version, live state, sign-in, and authenticated member journeys after deployment. Local compilation is not hosted verification.

Source pushes do not trigger deployments in this starter. No Actions workflow or deployment credential is included. Keep deployment promotion under the operator or authorized agent’s control until a separate automation policy is approved.

## WordPress plugin

Run `npm run package:wordpress` to produce an installable Riffit Core ZIP. Install or update that artifact through your backend’s authenticated plugin tooling. The frontend deployment does not update the plugin. Read SETUP.md and WORDPRESS_CONNECT.md before enabling administrator frontend sign-in.

## Public access and remixes

Each remix needs its own WordPress backend, database, frontend origin, email delivery, and provider application. Public source does not grant access to an existing site or its member data. Spacefast website access and source/remix sharing have separate controls; verify them explicitly.

Keep generated ZIPs as release assets and local test artifacts out of Git. Every release needs the corresponding GPL source, license text, third-party notices, and the separate CC0 artwork terms.
