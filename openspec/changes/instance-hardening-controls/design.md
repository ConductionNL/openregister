# Design: instance-hardening-controls

## D-1. The acceptance is per version, or it proves nothing

Recording that somebody accepted "the privacy statement" is worthless the
day the statement changes. The record names the version, and publishing a
new version asks again. That is what turns a checkbox into evidence of the
informatieplicht.

## D-2. Elevation is a second session, never a second account

Two accounts for one person is an access review problem and a shared
password waiting to happen. The identity stays one; the administration
session is separate, freshly authenticated and expiring. This is the
single answer for C-access-and-privacy-38 and for cluster 66's
C-configuration-72, so that the fleet has one elevated session and not
two.

## D-3. The platform enforces the factor, we declare the scope

Nextcloud already enforces a second factor and can do it per group. What
it cannot do is say that this register needs one and that one does not.
Declaring the scope here and letting the platform enforce it keeps one
implementation of the hard part.

## D-4. An unverified address gets a pointer, not the content

The failure is a dossier quoted to a guessed address. Sending a link and
nothing else means a wrong address leaks the existence of a message and no
personal data. Verification then upgrades the same recipient without a
second notification model.

## D-5. A large grant pauses rather than being refused

Refusing outright breaks the legitimate case: a reorganisation really does
move a whole afdeling. Holding it for a second administrator keeps the act
possible and makes it deliberate, which is what the safeguard is for.

## D-6. A bar keeps the writing and stops the writer

Deleting what a vexatious requester wrote destroys the record of the
request, which the Woo needs. Barring stops further interaction and leaves
every earlier act attributed and readable.

## D-7. An unallowlisted variable is absent, not an error

Throwing on an unallowlisted variable tells the author which variables
exist. Resolving it as absent tells them nothing and logs the attempt,
which is what a value resolver should do.

## D-8. kind

Code, in OpenRegister.
