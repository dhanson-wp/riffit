# WordPress.com sign-in setup

Use a dedicated application owned by the person operating the remix. Never reuse a site-management application or another project’s credentials.

1. Open https://developer.wordpress.com/apps/ and create a WordPress.com application named Riffit (or your remix’s name).
2. Set its redirect URI to the exact frontend origin plus `/auth/wordpress`. For example: `https://YOUR_SPACE.space.fast/auth/wordpress`.
3. After the plugin upgrade, open Settings → Riffit in the backend. Enter the application’s client ID and secret, check Enable WordPress.com sign-in, and save. Paste secrets only into that signed-in admin form, never chat, source files, or client-side variables. Leaving the secret field blank preserves a configured secret. Unchecking Enable hides and disables the provider.
4. Test the visible WordPress.com button from the deployed branded login. The flow requests only the `auth` scope, confirms the callback in the initiating browser, and verifies the provider’s `verified` flag.
5. For a WordPress site administrator, stay signed in to the backend and use Settings → Riffit → Your administrator sign-in → Connect my WordPress.com account. This nonce-protected native WordPress flow binds the verified provider identity to the already-authenticated administrator. It does not require or create an email-link session. After connecting, return to the branded frontend and use Continue with WordPress.com. Enable two-step authentication on the WordPress.com account; the OAuth response itself is not an MFA attestation.
6. For an ordinary member whose provider email already belongs to a local account, sign in with an email link first. Open the Riffit profile and choose Connect WordPress.com. This explicitly binds the immutable provider ID to that existing account.

Native administrator linking uses the same registered frontend redirect URI. The callback recognizes an administrator-only state prefix and relays to a fixed backend admin-post handler. Completion requires the original authenticated WordPress administrator and its short-lived HttpOnly proof cookie. Public REST consumption rejects that state type, and linking never passes a frontend session token through a URL. Neither callback mode silently links an account by matching email.

Each remix needs its own redirect URI, application, email transport, and backend. The code is implemented and tested against mocked provider responses; live credentials and authorization remain an operator setup step. Google is deferred. Gravatar is future work.

Sources: https://developer.wordpress.com/docs/api/wpcc/ and https://developer.wordpress.com/docs/api/oauth2/.
