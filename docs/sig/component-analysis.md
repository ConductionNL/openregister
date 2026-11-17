---
title: "Component analysis of the backend"
description: "How the code in lib/ depends on itself, seen as directories and as functional areas, with the script and the deptrac rules that produce every number."
sidebar_position: 4
---

# Component analysis of the backend

This page shows how the backend in `lib/` depends on itself. It reads the code in two ways: as the directories under `lib/`, and as functional areas. Every number comes from a script in this repository. You can run it on any commit and get the same numbers.

The measurements below are for commit `70e5570455` (main on 9 November 2026), unless a table says otherwise. A second tool, deptrac, checks the same rules independently.

## Summary

- The directories under `lib/` are roles, not components. Nextcloud decides where a class goes by what kind of class it is.
- Seen as directories, 98.4% of the references between layers go downward or stay on one level. 249 references go upward, 1.6%.
- Seen as functional areas, 64.5% of the code is hidden from other areas. Most area pairs reference each other in both directions.
- The coupling is concentrated. The largest cycle of files holds 6.1% of `lib/`, built around `lib/Db/MagicMapper.php`.

## Why the directories are roles

OpenRegister is a Nextcloud app. Nextcloud's app framework fixes where most classes live:

- A route in `appinfo/routes.php` names `setup#status`. Nextcloud resolves it to `OCA\OpenRegister\Controller\SetupController`, so every controller sits in `lib/Controller/`.
- `appinfo/info.xml` registers 56 background jobs, 41 console commands and the repair steps by class name.
- Database migrations live in `lib/Migration/` and are named `Version…`.
- Composer maps the namespace `OCA\OpenRegister\` to `lib/`, so the namespace follows the directory.

So `lib/Controller/`, `lib/Service/` and `lib/Db/` hold a kind of class, not a capability. The flow capability, for example, has its controller in `lib/Controller/`, its services in `lib/Service/Flow/` and its mappers in `lib/Db/`.

When every directory under `lib/` counts as a component, each controller calling its own service crosses a component boundary. So does each service calling its own mapper. That is layering, and in a layered design it is intended.

## The directory view

| Measure | Value |
|---|---|
| Components (directories under `lib/`) | 38 |
| Largest component | `lib/Service`, 54.4% of the code |
| Hidden code | 88.7% |
| Communication lines | 194 |
| Violation degree | 0.0646 |

The hidden share looks high for one reason: `lib/Service` holds more than half of the code in a single component. Most service-to-service calls therefore stay inside it.

### Layering

The script gives each file a role from the first directory below `lib/` that has a layer name:

| Role | Directories |
|---|---|
| Entry point | `Controller`, `Command`, `Listener`, `BackgroundJob`, `Repair`, `Middleware`, `Settings`, `Sections`, `Dashboard`, `Capabilities` |
| Service | `Service` |
| Persistence | `Db` |
| Shared type | `Event`, `Exception`, `Kernel`, `Contract`, `Dto`, `Support`, `Formats` |

`lib/Files/Controller/X.php` is therefore an entry point, and `lib/Search/Db/View.php` is persistence. `lib/AppInfo` is the bootstrap and has no layer.

A reference goes downward when an entry point uses a service, persistence or a shared type, a service uses persistence or a shared type, or persistence uses a shared type. On the snapshot:

| Direction | References | Share |
|---|---|---|
| Downward | 6,401 | 39.9% |
| Same level | 9,389 | 58.5% |
| Upward | 249 | 1.6% |

The 249 upward references, by kind:

| Upward reference | Count | What it is |
|---|---|---|
| Persistence to service | 153 | Mostly `MagicMapper` and its handlers calling access rules, object policy and query guards in `lib/Service/` |
| Shared type to persistence | 54 | Events and contracts that carry an entity, such as `ObjectEntity` or `Schema` |
| Service to entry point | 23 | Services that queue a background job by class |
| Shared type to service | 17 | Helper bundles in `Support/` that name a service interface |
| Persistence to entry point | 1 | `MagicMapper` naming `JobHandles` |
| Shared type to entry point | 1 | `ObjectEventSubscription` naming a listener interface |

Shared types referencing the three core entities is allowed by design. ADR-013 records that rule.

## The functional view

The functional view groups files by what they do. A controller, its services, its mapper, its entity, its events and its jobs belong to one area. `sigrid.components.yaml` defines the ten areas as file patterns over the unchanged layout. `scripts/component-patterns.py` generates that file from `scripts/component-map.csv`, and `tests/Unit/Architecture/ComponentDefinitionTest.php` checks that every PHP file under `lib/` matches exactly one area.

| Area | What it holds |
|---|---|
| Kernel | Contracts, value objects, the core entities `ObjectEntity`, `Schema` and `Register`, shared events and exceptions |
| Core | Objects, schemas, registers, relations, history, access rules, organisations, sharing, `MagicMapper` |
| Processes | Flows, BPMN and DMN, rules, calculations, tasks, cases, forms, lifecycle transitions |
| Configuration | Configuration packages, import and export, example sets, settings, repair steps |
| Compliance | Archiving, e-Depot, retention, deletion, GDPR, audit trail, anonymisation |
| Integrations | MCP, app hosting, GraphQL and API clients, calendar, contacts, DAV, federation, webhooks |
| Files | File storage, text extraction |
| Search | Search, facets, aggregations, vectorisation, views |
| Notifications | Notifications, web push, activity |
| Platform | Bootstrap, middleware, capabilities, dashboard, settings pages |

| Measure | Value |
|---|---|
| Components (areas) | 10 |
| Largest component | Core, 33.3% of the code |
| Hidden code | 64.5% |
| Communication lines | 81 |
| Violation degree | 0.194 |

This view shows the real coupling more plainly than the directory view. Areas use each other's concrete classes and entities directly. Most pairs of areas reference each other in both directions:

| Pair | References each way | Most used in the lighter direction |
|---|---|---|
| Core / Processes | 250 / 234 | `Db/TypedFieldsJsonTrait`, `Db/AuditTrailMapperInterface`, `Db/MagicMapperInterface` |
| Core / Integrations | 189 / 313 | `Db/AccessLink`, `Db/Source`, `Service/LinkTarget` |
| Compliance / Core | 193 / 156 | `Db/AuditTrail`, `Service/AuthorizationAuditService`, `Service/Audit/PurposeGuardInterface` |
| Configuration / Core | 534 / 108 | `Service/ConfigurationServiceInterface`, `Service/LanguageService`, `Service/Export/ExportGateInterface` |
| Core / Kernel | 1,575 / 80 | `Event/AbstractObjectEntityEvent`, `Event/AbstractSchemaEvent`, `Event/AbstractPersonLinkEvent` |

The full list per commit is in its `report.md`, linked under [Results](#results).

## Where the coupling is

91 sets of files reference each other in a cycle, 434 files in all. Most sets have two to five files. One set is much larger: 190 files and 23,989 lines, 6.1% of `lib/`.

That set holds `lib/Db/MagicMapper/` (54 files), `lib/Service/Rbac/` (27), the object events (16), `lib/Service/Object/` (14), the background jobs that run object work (12) and `lib/Service/Lifecycle/` (11). `MagicMapper.php` alone has 50 references inside the set. 49 references inside the set go upward a layer. Those are the references that close the cycle: the persistence layer calling access rules, object policy, events and jobs.

So most of the upward references and most of the cyclic coupling have one source. That source is the object store and its access checks.

## The same analysis on later commits

| Commit | Files | Lines | Directory view: hidden / violation degree | Functional view: hidden / violation degree | Upward references | Largest cycle |
|---|---|---|---|---|---|---|
| `70e5570455` (9 Nov) | 5,387 | 394,863 | 88.7% / 0.0646 | 64.5% / 0.194 | 249 (1.6%) | 190 files, 6.1% |
| `d20a7aee8c` (10 Nov) | 6,846 | 414,315 | 89.4% / 0.0505 | 64.9% / 0.188 | 255 (1.3%) | 230 files, 5.9% |
| `29890847e7` (17 Nov) | 7,482 | 458,606 | 85.2% / 0.0554 | 62.5% / 0.194 | 302 (1.4%) | 280 files, 6.7% |

## How the script works

The script is `tools/sig/analyse_dependencies.py`. It uses the dependency reader and the line counter of `tools/sig/check_sig_maintainability.py`, the estimate tool our quality gate runs. Both live in `tools/`, which `sigrid.yaml` excludes from the analysed code.

### The graph

- **Nodes.** One node per PHP file under `lib/`. `lib/Migration/` is left out, because Sigrid excludes migrations by default.
- **Edges.** File A depends on file B when A names B's class in a `use` import, a fully qualified name, or a bare name in A's own namespace. Comments are stripped first, so a class named in a docblock does not count. One edge per pair of files, however often the name appears.
- **Lines of code.** Lines that hold code, without blank lines and comments, counted as the estimate tool counts them.

### The measures

- **Hidden code.** The lines of files that no file in another component references, as a share of all lines.
- **Communication lines.** The pairs of components with at least one reference between them, per direction. The weight of a line is the number of file-to-file references.
- **Violation degree.** The weight of the lines that break the expected structure, divided by the total weight. A line is a violation when the reverse line exists, when it is the weakest line of a longer cycle, or when a lighter direct line repeats a path that exists anyway. This follows the published description of component entanglement. The page reports the inputs, not star ratings, because the benchmark normalisation is not public.
- **Layering.** Each file gets the role of the first layer-named directory on its path, as in the table above. Every edge between two files with a role is downward, same level or upward.
- **Cycles.** The strongly connected sets of files (Tarjan's algorithm). For the largest set the script lists the files, their directories and areas, and the upward references inside it.
- **Areas.** Per area: lines, exposed lines, references inside the area, references out and in, and the pairs of areas that reference each other both ways.

### Older commits

`--rev` exports `lib/` of a commit with `git archive` into a temporary directory and measures that. The functional definition always comes from today's `sigrid.components.yaml`. A file it does not match (it moved or was deleted since) gets the area of most files in its directory. The report counts those files: 4 on `70e5570455`, 7 on `d20a7aee8c`, none on `29890847e7`.

### Limits

- The reader works on text with regular expressions. It is not a PHP parser.
- A class that extends another class or uses a trait does not inherit that class's references. The parent or the trait carries them once.
- Sigrid's own reader may place a few references differently. These numbers are a reproducible reading of the code, not Sigrid's result.

`tools/sig/test_analyse_dependencies.py` tests the script on a small `lib/` written by the test, where every count can be checked by hand.

## The deptrac check

[deptrac](https://github.com/deptrac/deptrac) is an open source tool that checks dependency rules between layers of PHP code. It parses the code with a real PHP parser. That makes it a second, independent reading of the same rules.

- `tools/sig/deptrac/layers.yaml` holds the layering rules: entry point, service, persistence, shared type. It uses the same role rule as the script.
- `tools/sig/deptrac/areas.yaml` holds the ten areas, generated from `sigrid.components.yaml` by `tools/sig/deptrac_areas.py`. Every area may use Kernel. Every other reference between areas is reported.

deptrac counts differently in two ways. It reports every line that names a forbidden class, not one per file pair. It also gives a class the dependencies of its parent classes and traits. To compare, `tools/sig/deptrac_summary.py` counts distinct class pairs as well.

| Upward reference (layers, `70e5570455`) | Script: file pairs | deptrac: class pairs | deptrac: lines |
|---|---|---|---|
| Persistence to service | 153 | 209 | 499 |
| Shared type to persistence | 54 | 93 | 532 |
| Service to entry point | 23 | 23 | 39 |
| Shared type to service | 17 | 17 | 58 |
| Persistence to entry point | 1 | 1 | 1 |
| Shared type to entry point | 1 | 1 | 2 |

The extra class pairs in deptrac come from inheritance and traits. For example, `DraftUpdatedEvent` extends `ObjectUpdatedEvent` and names nothing itself. deptrac still reports `ObjectEntity` for it. `RegisterMapper` uses `TenancyIdentityTrait`, and deptrac reports the trait's service interface for the mapper. The kinds of upward reference and their order are the same in both tools.

## Reproduce

From the repository root:

```bash
# The script: needs Python 3, nothing else.
python3 tools/sig/analyse_dependencies.py --rev 70e5570455 --out /tmp/analysis-70e5570455

# deptrac: install it once, then run both rule sets.
composer --working-dir=vendor-bin/deptrac install
tools/sig/deptrac.sh layers --rev 70e5570455 --out /tmp/analysis-70e5570455
tools/sig/deptrac.sh areas --rev 70e5570455 --out /tmp/analysis-70e5570455

# The tests of the script.
python3 -m unittest discover -s tools/sig -p 'test_*.py'
```

Without `--rev` both tools measure the working tree. The script takes about 15 seconds. Each deptrac run takes under a minute.

## Results

Each directory holds the output of both tools for one commit:

- `report.md`: the tables of this page
- `results.json`: every number, including the full lists of upward references and cycle members
- `areas.dot`: the area graph for Graphviz, with two-way pairs in red
- `deptrac-layers.json`, `deptrac-areas.json`: the deptrac counts per pair of layers or areas

| Commit | Results |
|---|---|
| `70e5570455`, main on 9 November 2026 | [report](./dependency-analysis/70e5570455/report.md) |
| `d20a7aee8c`, main on 10 November 2026 | [report](./dependency-analysis/d20a7aee8c/report.md) |
| `29890847e7`, main on 17 November 2026 | [report](./dependency-analysis/29890847e7/report.md) |

## Related

- [Maintainability questionnaire](./maintainability.md), question 16: the components and how they connect
- `ARCHITECTURE.md`: the component description of the repository
- `openspec/architecture/adr-012-functional-components-over-the-nextcloud-layout.md`: the functional areas
- `openspec/architecture/adr-013-component-dependency-rules.md`: the layering rules
