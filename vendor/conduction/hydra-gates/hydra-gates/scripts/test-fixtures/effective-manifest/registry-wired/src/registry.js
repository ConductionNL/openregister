// SPDX-License-Identifier: EUPL-1.2
//
// Fixture: larpingapp#286 shape, CORRECTLY WIRED. Every kind:'section'/'page'
// entry is named by a manifest position, so check (f) reports nothing.
//
// The real file imports .vue SFCs, which is why the gate parses the export
// block textually instead of require()-ing it. Kept here for fidelity.
import EventRoster from './views/EventRoster.vue'
import SkillTree from './views/SkillTree.vue'
import HelperThing from './lib/HelperThing.js'

export default {
	// Event check-in roster — a sidebar-tab section on the event detail page.
	EventRoster: { kind: 'section', component: EventRoster },
	// Skill-tree visualization — a read-only type:"custom" page.
	SkillTree: { kind: 'page', component: SkillTree },
	// kind:'modal' is deliberately NOT required to have a manifest position:
	// open-modal targets are resolved at runtime and gate (b) already WARNs
	// about them. If this ever starts producing an orphan warning, direction 1
	// has begun reporting on a surface it cannot see.
	ConfirmDialog: { kind: 'modal', component: HelperThing },
	// A metadata-only entry with no kind at all — not a renderable surface.
	featureFlags: { enabled: true },
}
