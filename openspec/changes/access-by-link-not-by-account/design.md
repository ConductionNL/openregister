# Design: access-by-link-not-by-account

## D-1. The anchor is random, not derived

A link built from the object's identifier is a link anybody can construct
from a case number they were given in a letter. Plane's random anchor is
the right shape: the link is the secret, and knowing the record tells you
nothing about the link.

## D-2. The link is the principal

Resolving a link to a user borrows that user's rights and leaks them the
moment the link is forwarded. The link resolves to a principal of its own,
carrying exactly the capabilities it declares, which is also what makes the
audit entry readable: the act was the link's, not a person's.

## D-3. Expiry is required, password is optional

Every driven passer has an expiry and only some have a password. A link
without an end date becomes permanent by inattention, so the end date is
required. The password is the answer to forwarding, and it is offered
rather than imposed.

## D-4. A dead link answers 404

Answering 403 tells the holder that the record exists, which is the one
fact a revoked link should stop telling. Reducing the page is worse: a
partial answer looks like the whole answer.

## D-5. Capabilities are declared per link, and default to read

Plane declares four switches per board. Ours are read, comment and upload,
declared per link, defaulting to read. Anything undeclared is refused, so
a capability added later does not retroactively widen every existing link.

## D-6. The link cannot see past the object's own rules

A publication link is a reader, not an exemption. Hidden properties stay
hidden, internal timeline entries stay internal, and a field-level rule
applies exactly as it does to a person. Otherwise publishing becomes a way
to bypass the model.

## D-7. kind

Code, in OpenRegister.
