# Design: send-at-on-the-messaging-leaf

## D-1: the leaf stores the composed body, not a template

Scheduling stores exactly what would have been sent now: source, path,
body, headers. Nothing is re-rendered at send time, so what the handler
previewed is what leaves. A message that must reflect later changes is a
flow with a wait node, not a scheduled message.

## D-2: bound to an object when there is one

`object` is optional because the leaf is generic, but every fleet caller has
one. With an object the message is listed on it, audited on it and
cancellable by its editors. Without one only the author may cancel.

## D-3: the sweep is bounded and idempotent

Due messages are claimed with a compare-and-set on `state` from `scheduled`
to `sending` before dispatch, so two overlapping passes cannot send twice.
Batches are capped; a pass that does not finish leaves the rest for the
next.

## D-4: e-mail is a channel, not a mailbox

The leaf sends through an SMTP source the way it sends through an SMS
source. Receiving, threading and the inbox stay with integriq (ADR-091);
the RFC `Message-ID` the leaf mints is the handle `reply-threading-by-headers`
uses to bring a reply back to the object.

## D-5: kind

Code, in OpenRegister. Consuming apps add a date picker to a dialog.
