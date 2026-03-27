## Context

LarpingApp manages characters and skills. When configured to use OpenRegister as data source, character and skill objects are stored in OpenRegister's dynamic tables. Characters have a `skills` property containing an array of Skill UUIDs (plain array, not a `$ref` relation).

The dashboard currently shows a static welcome message. ApexCharts is already a dependency. OpenRegister now exposes a GraphQL API at `/index.php/apps/openregister/api/graphql`.

**Current state**: LarpingApp is not enabled in the dev environment. No character/skill schemas or data exist in OpenRegister yet. The first task is environment setup.

## Goals / Non-Goals

**Goals:**
- Set up LarpingApp with OpenRegister data source and seed test data
- Implement DASH-062 (skill popularity chart) from the LarpingApp dashboard spec
- Validate the OpenRegister GraphQL API works end-to-end for a real consumer app
- Demonstrate the two-query pattern: fetch characters, then resolve skill names

**Non-Goals:**
- Implementing all planned dashboard widgets (DASH-060 through DASH-064) — just the skill chart
- Modifying the OpenRegister GraphQL API or schema generator
- Real-time updates via SSE subscriptions (chart refreshes on page load only)
- Supporting the internal mapper data source — widget only works in OpenRegister mode

## Decisions

### 1. Two-query data fetching strategy

**Choice**: Two sequential GraphQL queries — first fetch characters with skill UUIDs, then fetch skills by UUID to get names.

```graphql
# Query 1: Get all characters with their skills array
{
  <characterPlural>(first: 500) {
    edges {
      node {
        _uuid
        name
        skills
      }
    }
    totalCount
  }
}

# Query 2: Get skill names for the collected UUIDs
{
  <skillPlural>(filter: { _uuid_in: [...collectedUuids] }, first: 100) {
    edges {
      node {
        _uuid
        name
      }
    }
  }
}
```

**Alternative considered**: Single query with nested resolution — rejected because `skills` is a plain UUID array, not a `$ref` relation. The GraphQL schema generator maps it as `[String]`, not as a resolved object type.

**Alternative considered**: Use facets on skills — rejected because facets aggregate on scalar values, and skills are stored as UUID arrays which aren't directly facetable.

**Rationale**: Two queries is simple, explicit, and works with the current schema. Total data volume is small (< 500 characters, < 100 skills in a typical LARP).

### 2. Dynamic schema slug discovery

**Choice**: Read the register and schema slugs from LarpingApp's `IAppConfig` settings at runtime via the Nextcloud OCS API, OR hardcode sensible defaults and let users override.

**Rationale**: Schema slugs are configurable per-install. Hardcoding would break portability. The LarpingApp frontend already reads these settings for its own API calls.

### 3. GraphQL authentication

**Choice**: Use Nextcloud cookie auth with CSRF request token. The widget runs inside the Nextcloud Vue SPA, so the user is already authenticated. The GraphQL endpoint has `@PublicPage @NoCSRFRequired @CORS` annotations but still accepts cookie auth.

**How it works**:
1. Widget reads `OC.requestToken` from the Nextcloud JS runtime
2. Includes it as `requesttoken` header in the GraphQL POST
3. Cookies are sent automatically (same origin)

**Rationale**: No need for Basic Auth or API keys — the user is already logged in.

### 4. Client-side aggregation

**Choice**: Aggregate skill counts in JavaScript after fetching raw data.

```javascript
// characterSkills = [{_uuid, name, skills: [uuid1, uuid2]}, ...]
// skillNames = {uuid1: "Swordsmanship", uuid2: "Healing", ...}
const counts = {};
characters.forEach(char => {
  (char.skills || []).forEach(skillUuid => {
    const name = skillNames[skillUuid] || skillUuid;
    counts[name] = (counts[name] || 0) + 1;
  });
});
// Sort by count descending, take top 10
```

**Rationale**: Simple, no server-side changes needed. Data volume is small enough for client-side processing.

### 5. Chart type and library

**Choice**: ApexCharts donut chart (variant of pie chart) — already installed in LarpingApp.

**Rationale**: Donut chart is more readable than a full pie chart for this data. ApexCharts is already a dependency, supports dark mode, and has responsive options.

### 6. Widget placement

**Choice**: Add `<SkillUsageChart />` to `DashboardIndex.vue` inside the existing `.graphs` CSS grid.

**Rationale**: Dashboard already has CSS infrastructure for chart containers (DASH-032). No layout changes needed.

## Risks / Trade-offs

**[Setup complexity] LarpingApp must be enabled and configured for OpenRegister** → Mitigation: Tasks include setup steps with exact commands. This is a one-time operation.

**[No data] Empty chart if no characters have skills** → Mitigation: Widget shows "No skill data available" empty state instead of rendering an empty chart.

**[Schema slug mismatch] Different installs may use different slugs** → Mitigation: Widget reads slugs from LarpingApp config, falls back to defaults.

**[Query field names] GraphQL field names are camelCase-ified from schema slugs** → Mitigation: Widget must use the same slug-to-camelCase conversion as SchemaGenerator. The `singularize()` logic may produce unexpected field names for Dutch slugs.

**[Cross-project dependency] Spec in OpenRegister, code in LarpingApp** → Mitigation: This is intentional — the spec validates the GraphQL API. Implementation tasks clearly state which files are in which project.

## Open Questions

- Should the widget show top-N skills (e.g., top 10) or all skills? For games with 50+ skills, a pie chart with 50 slices is unreadable.
- Should the widget link to the character list when clicking a pie slice (drill-down)?
- Should we add a refresh button or auto-refresh on a timer?
