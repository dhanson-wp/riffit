# Development status

This release contains Riffit Core 0.10.0 and the matching top-toolbar editor frontend. Its latest local validation passed 281 disposable WordPress integration checks, 12 production HTTP route checks, and a production build/type-check. Browser checks covered draft restoration, post editing, avatar save/reload and keyboard selection, and desktop/mobile light and dark account layouts. These checks do not establish hosted production readiness.

## Limits before launch

- Configure and test real email delivery and WordPress.com administrator sign-in on the intended hosted installation. Provider OAuth alone does not attest two-step authentication.
- Verify separate-member posting, uploads, voting, messages, profile updates, moderation, and access boundaries on the hosted installation.
- Review dependencies and performance before launch. The editor release applies Next.js 15.5.29, clearing the Next.js advisories reported on 2026-11-22. Development-only Playground/isomorphic-git and fast-xml-parser advisories require a separate tooling review.
- Configure abuse protection, recovery, moderation/report handling, and release policies before opening public participation.
- Browser drafts are device-local and do not retain selected files or sync to WordPress. Inline images support uploads and public HTTPS URLs with descriptions. Video accepts direct media URLs only; uploaded videos and provider embeds remain outside this revision.
- Google sign-in is deferred; Gravatar sign-in is future work.

The repository contains no production member data, service credentials, deployment identity, or automatic GitHub Actions workflow. Publishing source and granting public website access are separate decisions.

## Editor verification (local only)

The top toolbar revision passed the production build and type check, 281 disposable WordPress checks, and 12 production HTTP route checks. The latter exposed and verified a fix for missing-post comment requests racing the post request. Browser checks covered native bold/italic/link selection, quote transforms, undo/redo, tables, inline image upload and descriptions, direct video URLs, draft restoration, post creation/edit/reopen, and spoiler reveal by keyboard. Responsive review covers 320- and 390-pixel widths and light/dark appearance. Hosted deployment verification is recorded separately from these local checks.
