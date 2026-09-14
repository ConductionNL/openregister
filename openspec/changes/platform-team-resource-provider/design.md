# Design: platform-team-resource-provider

## D-1. The owning team is a property, not a new share

A second membership model is a second thing that can disagree with the
permission layer. The object names its owning team in a property the
authorization layer already reads, and the provider queries on it.

## D-2. The listing is paged, because registers are large

A team page that asks for every object a team owns will ask a register
with a hundred thousand rows. The provider answers a page with a cursor,
and the team page renders what it gets.

## D-3. A schema opts in

Not every register belongs on a team page, and offering all of them makes
the page useless. Opting in is a declaration, and the default is out.

## D-4. kind

Code, in OpenRegister.
