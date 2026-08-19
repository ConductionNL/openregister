// SPDX-License-Identifier: EUPL-1.2
//
// Fixture: DIRECTION 1 — ConductionNL/larpingapp#286, exactly as it shipped.
//
// `EventRoster` is registered, resolvable, and named by NO manifest position.
// The event check-in surface it renders therefore has no entry point: the UI
// was unreachable long enough for its openspec task to be ticked over it, and
// both gate-22 and gate-53 reported PASS the whole time, in both directions.
//
// Identical to registry-wired/src/registry.js. The ONLY difference between the
// two fixtures is the manifest: here the Check-in tab omits `component`.
import EventRoster from './views/EventRoster.vue'
import SkillTree from './views/SkillTree.vue'
import HelperThing from './lib/HelperThing.js'

export default {
	EventRoster: { kind: 'section', component: EventRoster },
	SkillTree: { kind: 'page', component: SkillTree },
	ConfirmDialog: { kind: 'modal', component: HelperThing },
	featureFlags: { enabled: true },
}
