# Architecture

## Ownership

WordPress is authoritative for users, content, groups, memberships, messages, and votes.
BuddyPress groups link to one bbPress forum using private group metadata. Topics and
replies are created through bbPress functions so its hooks and counters remain in play.
The initial product API only exposes Riffit-linked public communities.

## Identity and permissions

Cookie-authenticated WordPress REST calls need the core wp_rest nonce. Application
passwords authenticate server-to-server requests as their actual owner, never a user ID
from a request body. Riffit endpoints perform object and member permission checks.
Participation requires a server-set riffit_email_verified user meta flag, or an admin.
Email confirmation and verified provider identity flows own that flag; it is not writable by the public product API.
OAuth identity linking must use verified provider subject IDs, not unverified email strings.
No administrator credentials or shared write token may authenticate ordinary members.

The browser talks to same-origin Next.js handlers. Email authentication now uses browser-bound, single-use 15-minute challenges stored as
hashes. An explicit POST confirmation exchanges a link for a revocable 14-day opaque
session. The frontend stores it in an HTTP-only, secure, SameSite=Lax cookie and enforces
an exact configured Origin on mutations. The backend accepts this token only for the
riffit/v1 namespace; it does not authenticate the rest of WordPress.

WordPress stores the session hash, not its raw token. Email verification provisions a
subscriber with a random public pseudonym; it does not give administrative permissions.
Existing accounts are found by the inbox address whose ownership the link proves.
Google sign-in is deferred. WordPress.com Connect and explicit identity linking are implemented and covered by mocked-provider tests, but no live application is configured. Its client secret stays in backend admin options; provider tokens never reach client props. See WORDPRESS_CONNECT.md before enabling it.
Email transport acceptance is not proof of inbox delivery. Email links must be opened
in the same browser that requested them. Settings > Riffit configures the frontend origin.
Email-request limits are per address and backend source IP; a shared frontend egress
uses the shared IP budget. This needs production-scale tuning and bot controls before launch.

## Voting

One indexed row per member/object. Values: 1 (Riff), -1 (Skip), 0 (clear).
Upsert replaces the previous vote, so retries do not increment a counter. Aggregate
scores are derived from rows, avoiding stale read-modify-write totals. Large-scale ranking
needs measured queries and an indexed aggregate strategy before claiming scalability.

## Frontend/runtime

Next.js 15.5.28 is pinned within Spacefast's documented 15.5.24+ SSR lane.
The initial 16.3.8 candidate was rejected after npm audit reported critical advisories.
PostCSS and qs are overridden to patched compatible releases.
Do not upgrade to latest blindly; Spacefast's current recipe lists 16.4 as export-only.
Use Spacefast's adapter through its supported publish/build path. A normal local next
build confirms compilation, not platform deployment. Bind each installation to its own Space through local .spacefast/space.json state; keep that state out of source control.

## Installation

Activating Riffit Core creates indexed product tables for votes, saved posts, blocks, polls, reports, sign-in challenges, sessions, and provider identities. It enables BuddyPress groups, messages,
profiles, activity, and notifications. It creates no seed communities or member accounts.
Uninstall preserves content. It must not silently destroy a community database.

## Community read and messaging boundary

The frontend server-renders the initial feed, discussion, replies, or profile and reuses that snapshot on navigation. Query parameters are shareable reading state. Mutations use an allowlisted same-origin proxy with the HTTP-only member cookie; no member token enters client props. Public reads may retry without an expired token; private reads never do. API responses are private/no-store.

The ranked feed validates the linked BuddyPress group and bbPress forum before count, sorting, and pagination. Vote and saved-state hydration uses batch queries. Profile reputation aggregates public topic and reply votes without loading a member’s full history. Production-scale ranking still needs measured database plans and load testing.

One-to-one conversations use BuddyPress messages and recipients. Every thread read, write, and mark-read checks actual membership and exactly two participants. Sender IDs are always the current authenticated member. Blocking works in both directions, has an explicit unblock path, and prevents bypass via new threads. Message sends are limited to 60 per member per hour. The inbox polls while the document is visible and also offers manual refresh.

## Expanded post types

Riffit Core 0.4 adds typed metadata to real bbPress topics. Text requires a body; link URLs accept public HTTP/HTTPS addresses and are never fetched by the server. Crossposts keep a reference and re-check source visibility when read. Poll options and closing time are immutable at creation; an indexed `(object_id,user_id)` key keeps each member’s choice unique and supports changing it before expiry. Poll feed reads are batched.

Verified community members can upload JPEG/PNG/WebP images through the scoped product endpoint, with a 5 MB, 20-megapixel, and 8000-pixel-side limit. Both actual MIME and filename extension are checked. The endpoint never grants the WordPress upload_files capability. Attachments are owned by the current member, tagged to the community, and can only be attached once by that owner in that community. Uploads are limited to 20 per member per day. Image posts use WordPress-generated image sizes and alt text.

Lucide React 1.66.0 provides tree-shaken outline icons. Decorative icons are hidden from assistive technology and remain paired with labels. Public license notices are shipped at `/icon-licenses.txt`.

## Notifications and moderation

BuddyPress stores reply/mention notifications. Visible counts and pages join through the current linked public group/forum/topic and block relationships, excluding hidden/removed content before pagination. Notifications contain object references rather than copied private content. Only the owning member can mark a notification read. Focused comment reads compute the correct page using deterministic date/ID ordering.

Community moderators handle private reports and recoverable removals; community admins additionally manage settings and member roles. All authority checks use the actual current user and group, including the explicit acting-admin argument required by BuddyPress REST helpers. A creator’s community role does not grant any WordPress site role. Author editing cannot change another author’s content, post type, media, or poll options. Trash and restore run through WordPress’s real transitions, which invoke bbPress’s counters and reply hooks; a scoped filter restores only the previous allowed published/closed status. Backends with recoverable trash disabled refuse removal. Report and removal lists are separately paginated.


## Local 0.9.0 revision (unreleased)

The post composer now lazy-loads a curated Gutenberg Light Composer using BlockEditorProvider, BlockTools, WritingFlow, BlockList, native formatting controls, and useStateWithHistory. It persists serialized blocks in bbPress topic content and renders an explicitly sanitized static subset. It never runs member dynamic blocks or shortcodes. Image posts retain their separate validated upload path; inline image blocks are disabled. Browser drafts are member-scoped local storage, not WordPress draft posts.

Profile avatars use bundled, fixed IDs in user meta. Public serializers return avatar_id/author_avatar_id, while private account email/session information uses an authenticated account route. Device-local appearance supports light, dark, and system settings.

Sessions record whether issuance followed the email flow or verified WordPress.com provider exchange. Site administrators cannot use email-issued sessions. Password/email changes and blocking revoke sessions. Provider OAuth alone does not prove two-step authentication; administrator configuration and hosted verification remain launch gates. Community administration is a separate role and assurance policy. See STATUS.md for the evidence boundary and remaining public-launch work.
