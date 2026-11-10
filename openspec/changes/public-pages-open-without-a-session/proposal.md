---
kind: code
---

# Proposal: public-pages-open-without-a-session

## Summary

An access link opens a record for somebody with no account (#3817). What it
hands them today is JSON. The page that would render that record is an app
page, and every app page is served by `dashboard#catchAll`, which carries
`#[NoAdminRequired]` and no `#[PublicPage]`. So a citizen holding a live link
reaches a login screen, not their case.

This change adds one route beside the catch-all, `dashboard#publicPage` on
`/public/{path}`, and serves the app shell there for a page the app declared
public in its own manifest. It also closes openregister#3818: the object share
token was answering with the whole object, `@self.authorization` included.

## Motivation

Two halves of the same promise. #3817 built the reader, and the reader is
careful: an allow-list for `@self`, the property rules applied as an anonymous
reader, the timeline cut to its public half. None of that reaches a person who
cannot open a page.

Making the catch-all public would work and would be wrong. It would open every
page in every adopting app to anybody, and each of those pages calls endpoints
that need a session, so an anonymous visitor would get a shell that fails at
every request. The set of pages that survive without a session is small, known
to the app, and already describable: the manifest schema defines
`config.mode: "public"` as "marks the route as unauthenticated".

## What changes

- `PublicPageResolver` reads the leaf app's bundled `src/manifest.json` and
  answers one question: did this app declare this path public? A page counts
  when `config.mode` is `public` and its route sits under `/public/`.
- `GenericDashboardController::publicPage()` serves the app's `index` template
  in the public layout for a declared page, redirects an anonymous visitor to
  the login for anything else, and serves the ordinary shell to a signed-in
  one.
- `Routes::standard($extra, publicPages: true)` adds the route. Opt-in: an app
  with its own dashboard controller must implement `publicPage()` first.
- The SPA is told it is public through initial state, so it can boot a page
  rather than the whole app.
- `ObjectShareLinkController::show()` projects the object through the access
  link reader instead of serialising it whole (#3818), and the reader now
  projects timeline entries too, because a public entry's text is public and
  the account that wrote it is not.

## What does not change

- No endpoint answers more than it did. The shell carries no record data.
- The catch-all stays authenticated, in every app.
- An app that does not ask for the route does not get it.

## Risks

A page flagged public in a manifest is a page anybody may open. The flag alone
is not enough: the route must sit under `/public/`, so a detail page flagged by
accident still cannot be opened without a session. The data stays behind the
endpoint's own check either way.
