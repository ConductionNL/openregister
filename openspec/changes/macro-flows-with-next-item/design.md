# Design: macro-flows-with-next-item

## D-1: the action authorises, the flow executes

A declared action already answers who may do it. Binding a flow to it keeps
that answer and adds no second permission model. The run executes as the
person, so every object write inside the flow is checked against that
person's rights too; a macro cannot do what its user cannot.

## D-2: sync by default, because a macro is a click

A handler who clicks "close and notify" expects the case closed when the
page refreshes. `executionMode: sync` with the engine's existing budget;
a flow that needs longer declares `async` and the response says `queued`.

## D-3: the hint is a word, not a route

`next` is `stay`, `next` or `list`. The list host owns its own notion of
"the next item" (its sort, its filter). The engine never knows a URL.

## D-4: bulk rides the bulk path

A selection of two hundred cases is two hundred runs queued through the
bulk object write path, which already bounds concurrency and reports per
item. The response summarises and carries one `next`.

## D-5: kind

Code, in OpenRegister. The navigation is nextcloud-vue's; the manifest
marks the action.
