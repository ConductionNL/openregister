## 1. Environment Setup

- [ ] 1.1 Enable LarpingApp: `docker exec nextcloud php occ app:enable larpingapp`
- [ ] 1.2 Create a register for LarpingApp in OpenRegister (via API or UI), note the register ID
- [ ] 1.3 Create a `character` schema in OpenRegister with properties: `name` (string), `ocName` (string), `skills` (array), `background` (string)
- [ ] 1.4 Create a `skill` schema in OpenRegister with properties: `name` (string), `description` (string), `effects` (array)
- [ ] 1.5 Link both schemas to the register
- [ ] 1.6 Configure LarpingApp to use OpenRegister data source for characters and skills via `occ config:app:set`
- [ ] 1.7 Seed test data: create 5+ skills (e.g., Swordsmanship, Healing, Alchemy, Archery, Stealth)
- [ ] 1.8 Seed test data: create 5+ characters with varying skill assignments
- [ ] 1.9 Verify data is queryable via GraphQL: `POST /api/graphql` with introspection query showing character and skill types

## 2. GraphQL Query Utility

- [ ] 2.1 Create `larpingapp/src/services/graphql.js` with a `queryGraphQL(query, variables)` helper
- [ ] 2.2 Helper MUST POST to `/index.php/apps/openregister/api/graphql` with `Content-Type: application/json`
- [ ] 2.3 Helper MUST include `requesttoken` header from `OC.requestToken` for CSRF protection
- [ ] 2.4 Helper MUST return parsed JSON response or throw on network/auth errors
- [ ] 2.5 Add error handling: 401 → "Authentication error", 429 → "Rate limited", network error → "Connection failed"

## 3. Skill Usage Data Fetching

- [ ] 3.1 Determine the correct GraphQL field names for the character and skill schemas (camelCase of their slugs)
- [ ] 3.2 Implement query 1: fetch all characters with `skills` array (up to 500)
- [ ] 3.3 Collect unique skill UUIDs from all characters' `skills` arrays
- [ ] 3.4 Implement query 2: fetch skill objects by UUID to get their `name` property
- [ ] 3.5 Aggregate: count how many characters reference each skill, sorted by popularity descending
- [ ] 3.6 Limit to top 10 skills for chart readability, group remaining into "Other"

## 4. Pie Chart Component

- [ ] 4.1 Create `larpingapp/src/views/dashboard/SkillUsageChart.vue` using ApexCharts donut chart
- [ ] 4.2 Implement loading state (NcLoadingIcon spinner while queries execute)
- [ ] 4.3 Implement empty state ("No skill data available" when no characters/skills exist)
- [ ] 4.4 Implement error state with error message and retry button
- [ ] 4.5 Pass aggregated data to ApexCharts: labels = skill names, series = character counts
- [ ] 4.6 Style chart card: title "Skill Usage by Characters", consistent card background with dashboard theme
- [ ] 4.7 Support both light and dark Nextcloud themes (use CSS variables or `prefers-color-scheme`)

## 5. Dashboard Integration

- [ ] 5.1 Import `SkillUsageChart` in `larpingapp/src/views/dashboard/DashboardIndex.vue`
- [ ] 5.2 Add `<SkillUsageChart />` inside the `.graphs` grid container
- [ ] 5.3 Verify chart respects responsive grid (2 columns > 1800px, 1 column below)
- [ ] 5.4 Build the LarpingApp frontend: `cd larpingapp && npm run build` (or `npm run dev`)

## 6. Testing & Verification

- [ ] 6.1 Browser test: navigate to LarpingApp dashboard, verify chart renders with seeded data
- [ ] 6.2 Verify GraphQL queries work (check browser Network tab for POST to `/api/graphql`)
- [ ] 6.3 Verify chart shows correct skill names and counts matching seeded data
- [ ] 6.4 Test empty state: remove all character-skill assignments, verify "No skill data" message
- [ ] 6.5 Test error state: temporarily break GraphQL endpoint URL, verify error + retry button
- [ ] 6.6 Test dark theme: switch Nextcloud to dark mode, verify chart is readable
- [ ] 6.7 Take screenshot of working widget for verification
