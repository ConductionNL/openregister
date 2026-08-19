// SPDX-License-Identifier: EUPL-1.2
//
// Fixture: DIRECTION 2 — the manifest names a component nobody registers.
//
// The Check-in tab in this fixture's manifest names
// `ThisComponentDoesNotExistAnywhere`. At runtime
// CnObjectSidebar.resolveTabComponent() logs `component "…" not found in
// registry or customComponents` and renders a BLANK TAB. Unambiguously broken,
// so check (f) FAILS rather than warns — this is gate-14 route-reachability
// one layer up.
//
// `EventRoster` is still registered here and still named by nothing (the tab
// that used to name it now names the missing component), so this fixture
// carries BOTH directions at once — which is also the realistic shape of a
// botched rename.
import EventRoster from './views/EventRoster.vue'
import SkillTree from './views/SkillTree.vue'
import HelperThing from './lib/HelperThing.js'

export default {
	EventRoster: { kind: 'section', component: EventRoster },
	SkillTree: { kind: 'page', component: SkillTree },
	ConfirmDialog: { kind: 'modal', component: HelperThing },
	featureFlags: { enabled: true },
}
