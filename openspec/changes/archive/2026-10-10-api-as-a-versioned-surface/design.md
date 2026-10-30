# Design: api-as-a-versioned-surface

## D-1. A link out is a declaration on the schema, not a menu entry

A menu entry is the same for every record. The question a caseworker asks
is "open this address in the BAG viewer", which needs the record's own
values in the URL. Declaring the template on the schema means one
mechanism serves every app, and the leaf app configures rather than codes.

## D-2. An unfillable placeholder hides the link

A URL with `{bagId}` still in it is a broken link that looks like a
working one. When the object has no value for a placeholder, the link is
not offered at all, and the condition on the declaration is how an author
says so deliberately.

## D-3. Deprecated means answering, with an end date in the answer

A version that stops answering the day it is deprecated is a breaking
change with extra steps. Deprecated answers normally and carries the end
date in a header, so a client learns about the deadline from the calls it
already makes. Withdrawn answers 410 and names the successor, because 404
reads as a bug.

## D-4. The call record carries the caller, never the payload

Knowing that a leverancier still calls a route is enough to hold the
conversation. Recording what they sent is a second copy of case data in a
log, which is the failure C-access-and-privacy-54 describes in Plane. The
record is principal, route, version, count and last seen.

## D-5. Capabilities are read without a session where they carry no secret

A client that must authenticate to learn the upload limit will not learn
the upload limit. The unauthenticated answer carries versions and limits;
anything that names a register, a schema or a feature flag with
operational meaning needs a session.

## D-6. One proxy setting, used by every outbound path

Two outbound clients means one of them bypasses the proxy, and the one
that bypasses it is the one that fails in production. The setting is read
in one place and the clients are built from it.

## D-7. kind

Code, in OpenRegister.
