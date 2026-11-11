# Design: roles-block-that-resolves-to-nothing-grants-nothing

## D-1. Closed, not absent

The expansion's empty result meant two different things: "the author wrote no
rules" and "the author wrote rules nobody can resolve". Only the first is the
default-open case. The second now comes back as the four canonical verbs with
nobody in them, which every evaluator already reads as a refusal: the single
object path (`hasGroupPermission()`, `empty($authorization[$action])`) and the
list path (`MagicRbacHandler::applyRbacFilters()`, the `1 = 0` deny).

## D-2. Why the four canonical verbs and not a marker key

A marker key would be a key nobody else knows, and an unknown key is the kind
of thing a later strip or merge drops in silence. Four empty lists are the
grammar's own "grant to nobody", already used by `stripMcpScope()`.

## D-3. Object level follows

An object's own `_authorization` holding unresolvable roles also comes back
closed, and replaces the schema's verbs for that object. That narrows rather
than widens, which is the direction an ambiguous rule should fail in.
