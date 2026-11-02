# Development status

This snapshot contains Riffit Core 0.9.0 and the matching frontend. Its latest local validation passed 263 disposable WordPress integration checks, 12 production HTTP route checks, and a production build/type-check. Browser checks covered draft restoration, post editing, avatar save/reload and keyboard selection, and desktop/mobile light and dark account layouts. These checks do not establish hosted production readiness.

## Limits before launch

- Configure and test real email delivery and WordPress.com administrator sign-in on the intended hosted installation. Provider OAuth alone does not attest two-step authentication.
- Verify separate-member posting, uploads, voting, messages, profile updates, moderation, and access boundaries on the hosted installation.
- Review dependencies and performance. The most recent audit reported low-severity deepmerge advisories through Gutenberg dependencies; resolve or explicitly assess them against the installed versions before launch.
- Configure abuse protection, recovery, moderation/report handling, and release policies before opening public participation.
- Browser drafts are device-local and do not retain selected files or sync to WordPress. Inline image blocks are not enabled; images are a separate post type.
- Google sign-in is deferred; Gravatar sign-in is future work.

The repository contains no production member data, service credentials, deployment identity, or automatic GitHub Actions workflow. Publishing source and granting public website access are separate decisions.
