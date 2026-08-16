## Why

The LarpingApp dashboard (DASH-062) has a planned "skill popularity across characters" chart but no implementation. This is the ideal test case for the new GraphQL API — it requires querying character objects, extracting skill references, aggregating counts, and rendering a pie chart. Building this widget validates that OpenRegister's GraphQL endpoint works end-to-end for a real consumer app.

## Prerequisites

Before the widget can work, the following must be in place:

1. **LarpingApp must be enabled** — currently disabled on this environment. Run `occ app:enable larpingapp`.

2. **LarpingApp must be configured to use OpenRegister as data source** — by default it uses internal Nextcloud DB mappers. The following app config keys must be set to `openregister` mode:
   - `character_source` = `openregister`, `character_register` = `<register-slug>`, `character_schema` = `character`
   - `skill_source` = `openregister`, `skill_register` = `<register-slug>`, `skill_schema` = `skill`

3. **Character and Skill schemas must exist in OpenRegister** — either created automatically by LarpingApp on first use, or manually via API/UI. Required schemas:
   - **Character schema** (slug: `character`) with properties including `name` (string), `skills` (array of UUIDs referencing skill objects)
   - **Skill schema** (slug: `skill`) with properties including `name` (string), `effects` (array of UUIDs)

4. **Sample data must exist** — at least 3-5 characters with skills assigned, so the chart has something to display. This can be seeded via the LarpingApp UI or API.

## Data Model

The relevant data relationships:

```
Character (schema: character)
├── name: string ("Aldric the Bold")
├── skills: UUID[] → references Skill objects
├── items: UUID[] → references Item objects
└── ...other fields

Skill (schema: skill)
├── name: string ("Swordsmanship")
├── effects: UUID[] → references Effect objects
└── ...other fields
```

**Key detail**: The `skills` property on Character is a **plain UUID array**, NOT a `$ref` relation. This means GraphQL's auto-resolution won't resolve skill names automatically. The widget must either:
- Fetch characters first, collect unique skill UUIDs, then fetch skill names in a second query
- OR use the REST API's `_extend=skills` pattern if available through GraphQL

The register and schema slugs are **configurable per-install** via LarpingApp's `IAppConfig` settings (e.g., `character_register`, `character_schema`). The widget should read these settings or use sensible defaults.

## What Changes

**In LarpingApp** (cross-project — code lives in `larpingapp/` submodule):
- New `src/services/graphql.js` — reusable GraphQL query helper with CSRF token handling
- New `src/views/dashboard/SkillUsageChart.vue` — ApexCharts pie chart component
- Modified `src/views/dashboard/DashboardIndex.vue` — add chart to `.graphs` grid

**In OpenRegister** (this project — spec only):
- No code changes — uses existing GraphQL API as-is
- This change validates the GraphQL API works for a real consumer

## Capabilities

### New Capabilities
- `larping-skill-widget`: Dashboard pie chart widget showing skill usage distribution across characters, powered by OpenRegister's GraphQL API

### Modified Capabilities
- `dashboard`: Existing LarpingApp dashboard spec gains a concrete implementation of planned item DASH-062

## Impact

- **New code in LarpingApp**: 3 files (GraphQL utility, chart component, dashboard integration)
- **No changes to OpenRegister code**: Uses existing `/api/graphql` endpoint
- **Environment setup required**: LarpingApp must be enabled and configured for OpenRegister data source
- **Dependencies**: ApexCharts (already in LarpingApp's `package.json`), no new deps
- **Data flow**: LarpingApp Vue SPA → `POST /index.php/apps/openregister/api/graphql` (with Nextcloud cookie auth + CSRF token) → character + skill data → client-side aggregation → ApexCharts pie chart
- **Cross-project**: Spec lives in OpenRegister (validates GraphQL), implementation lives in LarpingApp
