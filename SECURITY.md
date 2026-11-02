# Security

This is a development release. No hosted production support or security-response SLA is promised.

Do not post credentials, sign-in links, member data, or exploit details in a public issue. Once this repository is published, use GitHub’s private vulnerability reporting if the repository shows a “Report a vulnerability” option. Private reporting must be enabled by the repository owner before publication; it is not enabled by this source file. If no private channel is available, open a minimal issue asking for a private reporting channel without disclosure details.

Never put WordPress administrator credentials or member session tokens into frontend variables. Each installation needs its own backend, HTTPS frontend origin, mail delivery, and provider configuration. Site administrators must configure WordPress.com sign-in before relying on frontend administrator access. Enable two-step authentication on the provider account and verify the hosted flow; OAuth does not itself attest MFA.

Before launch, verify permissions with separate identities, session revocation, posting/media ownership, private messages, moderation boundaries, abuse limits, backup/recovery, and dependency advisories. See docs/STATUS.md.
