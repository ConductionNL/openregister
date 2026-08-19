// SPDX-License-Identifier: EUPL-1.2
//
// Fixture: the two registration dialects the first version of check (f) could
// not see. Both produced FALSE FAILs on live repos.
//
// 1. QUOTED, HYPHENATED KEYS — hermiq's dialect. The original matcher shared
//    one character class between quoted and bare keys, so `'agent-form'`
//    captured `agent` and stopped at the hyphen. Every hyphenated registration
//    was invisible and every manifest reference to one was reported
//    unresolved: 25 false FAILs on hermiq alone.
//
// 2. src/customComponents.js — the SECOND registration source (see the sibling
//    file in this fixture). The runtime resolution order is documented in
//    every app's own customComponents.js, and the console error this gate
//    quotes says it out loud: "not found in registry OR customComponents".
//    Reading only this file produced 9 false FAILs on softwarecatalog.
import AgentForm from './views/AgentForm.vue'
import AgentSkills from './views/AgentSkills.vue'
import PlainSection from './views/PlainSection.vue'

export default {
	'agent-form': { kind: 'page', component: AgentForm },
	"agent-skills": { kind: 'section', component: AgentSkills },
	PlainSection: { kind: 'section', component: PlainSection },
}
