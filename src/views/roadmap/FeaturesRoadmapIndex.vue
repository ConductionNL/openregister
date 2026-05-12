<template>
	<NcAppContent>
		<CnFeaturesAndRoadmapView :repo="repo" :features="features" :disabled="disabled" />
	</NcAppContent>
</template>

<script>
/**
 * Features & Roadmap route view (pilot — add-features-roadmap-menu).
 *
 * Thin wrapper around `CnFeaturesAndRoadmapView` from `@conduction/nextcloud-vue`.
 * `repo`, `features` and `disabled` come from server-provided initial state when
 * available (task 5.11 wires `openregister::features_roadmap_enabled` /
 * `openregister::github_repo` through `IInitialState`); until then the fallbacks
 * below keep the route usable.
 *
 * @category View
 * @package  OCA\OpenRegister
 */
import { NcAppContent } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import { CnFeaturesAndRoadmapView } from '@conduction/nextcloud-vue'

export default {
	name: 'FeaturesRoadmapIndex',

	components: {
		NcAppContent,
		CnFeaturesAndRoadmapView,
	},

	data() {
		return {
			repo: loadState('openregister', 'features_roadmap_repo', 'ConductionNL/openregister'),
			features: loadState('openregister', 'features_roadmap_features', []),
			disabled: loadState('openregister', 'features_roadmap_disabled', false),
		}
	},
}
</script>
