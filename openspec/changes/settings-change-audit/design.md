# Design: settings-change-audit

## D-1: one entry per key, not one per request

An administrator who changes six keys in one save produces six rows. A
reader filtering on one key finds it; a reader who wants the save finds six
rows with the same request id. Diffing per key is what makes the row
answerable.

## D-2: masked, but recorded

A rotated secret is the change a security officer most wants to see and
least wants to read. The row says the key changed, by whom, when, and shows
`***` for both values. Declared once on the register configuration.

## D-3: on the chain

A settings row is an audit-trail row with a `settings` subject instead of
an object, hashed into the same chain, so tampering with it is as evident
as tampering with an object change. No new table.

## D-4: kind

Code, in OpenRegister. Consuming apps get it by riding the plane.
