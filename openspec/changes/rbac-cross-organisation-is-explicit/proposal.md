## Why

OpenRegister owns multitenancy. Apps must not re-implement tenancy checks (ADR-022). Today the organisation filter is lifted by rules that never say they cross organisations:

- a plain group rule the caller holds (`"hr"`) reads the rows of every organisation;
- a plain `public` rule lifts the filter for every signed-in caller who inherits public rights;
- a conditional rule that binds a row field to the caller's organisation (`{"provider": "$organisation"}`) or membership (`$memberOf`) lifts it as well.

So whether a rule reads other organisations' rows depends on the shape of the rule, not on what its author meant. learniq's `client-organisation` schema shows the cost. Its read rule is three plain groups, so an `hr` member of one school could read and invite the companies of another school. learniq#2012 and portaliq#1926 then had to add tenancy checks in the apps.

## What changes

- **Every read rule keeps the caller's organisation filter unless it says `"crossOrganisation": true`.** This holds for plain group rules, `authenticated`, user rules, conditional rules and `public` rules. The marker from #5426 (matrix rows) now applies to every rule.
- **A marked rule with a match widens the filter by its own rows.** The caller reads the rows that rule admits in every organisation, and the rows of their other rules only in their own. A marked rule with no match reads every row, so the filter is lifted. This is the branch #5433 built for conditional `public` rules, now used for every marked rule.
- **A plain string rule cannot carry the marker.** An author writes `{"group": "hr", "crossOrganisation": true}` or `{"group": "public", "crossOrganisation": true}` instead. Schema and register lookups read `{"group": "public"}` with no match as public, the same as `"public"`.
- **Department matrix rows** marked `crossOrganisation: true` compile into a marked rule, and the marker decision reads the resolved block, so a matrix row really crosses.
- **The validator** refuses a `crossOrganisation` value that is not a boolean.
- **A repair step** marks the stored read rules that crossed by binding a field to the caller's organisation or membership. Those rules have no meaning inside one organisation. It does not mark plain group or `public` rules: they narrow, and the step logs each schema that has them.
- **Unchanged:** admins, an explicit `_multi`, per-object grants (the tenant edge stands), anonymous callers, shared master data, and the rows with no organisation that the register cascade admits.

## Impact

- **Behaviour change for signed-in, non-admin callers.** About 2,446 schema-level read rules in about 900 schemas of 14 fleet apps read other organisations' rows today (scan in the PR). After this change they read only the caller's organisation until the app marks the rules that are meant to cross. Each intended crossing gets a marker in a small app PR, stacked on this one.
- **Order of release:** the app marker PRs need this change, because older versions read `{"group": "public"}` differently from `"public"` in the schema and register lookups. An app that re-imports its register JSON after the repair step and before its marker PR is released loses the repair step's marks until that PR lands.
- Supersedes the implicit crossing rules in the rbac-scopes multi-tenancy requirement (#5426, #5433).
