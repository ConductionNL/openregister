<template>
	<div class="schema-workflow-tab">
		<NcAppContentDetails>
			<h2>{{ t('openregister', 'Workflows') }}</h2>

			<section class="tab-section">
				<TaskSequencePanel :schema="schema" />
			</section>
		</NcAppContentDetails>
	</div>
</template>

<script>
import { NcAppContentDetails } from '@nextcloud/vue'
import TaskSequencePanel from '../../components/workflow/TaskSequencePanel.vue'

export default {
	name: 'SchemaWorkflowTab',
	components: {
		NcAppContentDetails,
		TaskSequencePanel,
	},

	props: {
		schema: { type: Object, required: true },
	},
}
</script>

<style scoped>
.schema-workflow-tab {
	padding: 20px;
}

.tab-section {
	margin-bottom: 24px;
}
</style>
