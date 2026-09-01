<template>
	<div class="task-sequence-panel">
		<h3>{{ t('openregister', 'Approval sequences') }}</h3>

		<NcEmptyContent v-if="chainEntries.length === 0"
			:name="t('openregister', 'No approval chains declared')"
			:description="t('openregister', 'Declare x-openregister-approval-chains on this schema to gate a lifecycle transition on approval.')">
			<template #icon>
				<CheckDecagramOutline :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<p class="task-sequence-panel__hint">
				{{ t('openregister', 'Each declared chain provisions an ordered task sequence when its transition is attempted. Approvers decide in their task inbox, not here.') }}
			</p>

			<div v-for="entry in chainEntries" :key="entry.key" class="task-sequence-panel__chain">
				<h4>{{ entry.key }}</h4>
				<ul class="task-sequence-panel__meta">
					<li>{{ t('openregister', 'Gated transition: {transition}', { transition: entry.transition || '?' }) }}</li>
					<li v-if="entry.amountField">
						{{ t('openregister', 'Tier routing on field: {field}', { field: entry.amountField }) }}
					</li>
					<li v-if="entry.separationOfDuties">
						{{ t('openregister', 'The requester may not decide their own approval.') }}
					</li>
					<li v-if="entry.onApprove === 'advanceTransition'">
						{{ t('openregister', 'The transition is applied automatically once every step approves.') }}
					</li>
				</ul>
				<ol class="task-sequence-panel__steps">
					<li v-for="(approver, index) in entry.approvers" :key="index">
						{{ t('openregister', 'Role {role}', { role: approver.role || '?' }) }}
						<span v-if="approver.minAmount !== undefined" class="task-sequence-panel__tier">
							{{ t('openregister', 'from amount {amount}', { amount: approver.minAmount }) }}
						</span>
					</li>
				</ol>
			</div>
		</template>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import CheckDecagramOutline from 'vue-material-design-icons/CheckDecagramOutline.vue'

/**
 * Read-only view of a schema's declared approval chains.
 *
 * Replaces the retired ApprovalChainPanel (flow-approval-consolidation task
 * 4.2). There is no CRUD here on purpose: the declaration on the schema is
 * the one authoring surface, and deciding happens in the task inbox.
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-006
 */
export default {
	name: 'TaskSequencePanel',
	components: {
		NcEmptyContent,
		CheckDecagramOutline,
	},

	props: {
		schema: { type: Object, required: true },
	},

	computed: {
		/**
		 * The declared chains, normalised for the template.
		 *
		 * @return {Array} One entry per declared chain key.
		 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-006
		 */
		chainEntries() {
			const chains = this.schema?.configuration?.['x-openregister-approval-chains'] || {}
			return Object.entries(chains)
				.filter(([, spec]) => spec && typeof spec === 'object')
				.map(([key, spec]) => ({
					key,
					transition: spec.transition || '',
					amountField: spec.amountField || '',
					separationOfDuties: spec.separationOfDuties !== false,
					onApprove: spec.onApprove || '',
					approvers: Array.isArray(spec.approvers) ? spec.approvers : [],
				}))
		},
	},
}
</script>

<style scoped>
.task-sequence-panel__hint {
	color: var(--color-text-maxcontrast);
}

.task-sequence-panel__chain {
	margin-bottom: 16px;
}

.task-sequence-panel__meta {
	color: var(--color-text-maxcontrast);
	list-style: none;
	padding: 0;
}

.task-sequence-panel__steps {
	margin-left: 20px;
}

.task-sequence-panel__tier {
	color: var(--color-text-maxcontrast);
}
</style>
