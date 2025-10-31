<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'AI Agents') }}
				</h1>
				<p>
					{{ t('openregister', 'Manage and configure AI agents for automated tasks') }}
				</p>
			</div>

			<!-- Placeholder Content -->
			<NcEmptyContent
				:name="t('openregister', 'Coming Soon')"
				:description="t('openregister', 'AI Agents functionality will be available in a future release')">
				<template #icon>
					<Robot :size="64" />
				</template>
			</NcEmptyContent>
		</div>
	</NcAppContent>
</template>

<script>
import { t } from '@nextcloud/l10n'

import {
	NcAppContent,
	NcEmptyContent,
} from '@nextcloud/vue'

import Robot from 'vue-material-design-icons/Robot.vue'

export default {
	name: 'AgentsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		Robot,
	},
	methods: {
		t,
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	max-width: 100%;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	min-height: 60vh;
}

.viewHeader {
	margin-bottom: 40px;
	text-align: center;
}

.viewHeaderTitleIndented {
	margin: 0 0 8px 0;
	font-size: 28px;
	font-weight: 600;
}

.viewHeader p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>

