# Design: reply-threading-by-headers

## D-1: the RFC id is the thread key

Mail app ids are per account and per instance; the `Message-ID` header is
global and survives every hop. Storing it on the link row makes a thread
resolvable from a raw message, which is what an intake job holds.

## D-2: outbound is a link too

A sent e-mail has no Mail app message until it lands in a sent folder, and
the dispatch leaf sends through SMTP, not the Mail app. So the leaf writes
the link row itself, `direction: outbound`, with the id it minted. The
sidebar lists both directions.

## D-3: In-Reply-To beats References beats subject

`In-Reply-To` names the one message replied to and is matched first.
`References` lists the whole ancestry and is matched newest first, so a
reply to a forwarded thread lands on the most recent object. The subject
tag is the caller's fallback, never the leaf's.

## D-4: resolve is RBAC-scoped unless the caller is granted

A user calling resolve sees objects they may read. The intake runs as a
granted identity (ADR-099) and needs the answer regardless of who may read
the case, because it is the one deciding where to file; the response says
`scope: system` so a log reader knows.

## D-5: kind

Code, in OpenRegister. integriq calls one endpoint; dossiq reorders one
matcher.
