# Design: public-pages-open-without-a-session

## 1. Why a second route and not a public catch-all

`dashboard#catchAll` matches `/{path}` for every adopting app. Giving it
`#[PublicPage]` would make every page in every leaf app reachable without a
session, in one merge, for twenty-one apps at once. The pages themselves would
still fail: they call `/api/objects`, `/api/settings` and the rest, and those
answer 401 to nobody.

So the public surface is a route of its own, `/public/{path}`, placed after the
app's own `$extra` routes and before the catch-all. An app that already serves
something at a `/public/…` address keeps it, because `$extra` is merged first.

## 2. Why the app declares it, and where

The engine cannot know which of an app's pages survive without a session. The
app knows, and it already writes its pages down: `src/manifest.json`, the same
file `ManifestController` reads to serve the app manifest.

The flag is not new either. The manifest schema (nextcloud-vue,
`app-manifest-v2.schema.json`) already defines `config.mode: "public"` as
"marks the route as unauthenticated (token-scoped reader pages)". This change
makes that description true on the server.

Two conditions, not one:

1. `config.mode === "public"`, and
2. the page's route starts with `/public/`.

The second is the containment. A `mode: "public"` typo on a detail page whose
route is `/cases/:id` opens nothing, because the route that serves public pages
only matches `/public/…`.

## 3. Fail closed at every step

- No resolver wired: 404.
- Manifest missing, unreadable or invalid JSON: nothing is declared, so every
  path falls through to the login.
- Path not declared, no session: redirect to the login, carrying the address so
  a colleague who follows an internal link still lands where they meant to.
- Path not declared, signed in: the ordinary shell, exactly as before.

## 4. What the shell may carry

`PublicTemplateResponse`, which is the layout Nextcloud serves a public share
with, plus one initial-state key: `public_page: true`. Nothing else. The record
arrives over `GET /api/public/links/{anchor}`, which decides for itself what an
anonymous caller may read.

The leaf app reads that key at boot and mounts the page alone, without the app
navigation and without the stores that fetch authenticated data. That is the
app's job, not the engine's, but the engine has to say so or the app cannot
know.

## 5. openregister#3818, in this change and not a later one

`ObjectShareLinkController::show()` answers anonymously, and it answered with
`$object->jsonSerialize()`: `@self.authorization`, `@self.owner`,
`@self.organisation`, `@self.folder`, and every property regardless of
`writeOnly` or property-level authorization.

The reader built for #3817 already has the projection. Making it public on
`AccessLinkReader` and calling it from the share-link controller gives the two
anonymous surfaces one allow-list instead of two, which is the only way they
stay the same as `@self` grows.

The timeline gets the same treatment while the allow-list is being written. A
public timeline entry's MESSAGE is public, on purpose. `actorId`, `editedBy`,
`editedByDisplayName` and `isCurrentUser` are not: they name accounts inside
the organisation to somebody with no account at all.

## 6. Alternatives rejected

**A route per public page, declared by the app.** Explicit, and it moves the
decision into two files that must agree: a routes entry and a manifest page. A
page that has the route and not the flag, or the flag and not the route, is a
silent half-opening.

**A `publicPages` list in the manifest root.** A second list to keep in step
with `pages`, for no gain over a flag on the page itself.

**Inferring from the route prefix alone.** `/public/…` would then be a magic
prefix that opens any page an app happens to put there, including one added
later by somebody who did not know. The flag makes it a decision.
