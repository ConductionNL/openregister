<script setup>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
</script>

<template>
	<div class="approval-step-list">
		<h4>{{ t('openregister', 'Approval Progress') }}</h4>
		<div v-if="steps.length === 0">
			<p>{{ t('openregister', 'No approval steps for this object.') }}</p>
		</div>
		<div v-for="step in steps" :key="step.id" class="step-row">
			<span class="step-order">{{ t('openregister', 'Step') }} {{ step.stepOrder }}</span>
			<span class="step-role">{{ step.role }}</span>
			<span :class="['status-badge', `status-${step.status}`]">{{ step.status }}</span>
			<span v-if="step.decidedBy" class="decided-by">{{ t('openregister', 'by') }} {{ step.decidedBy }}</span>
			<div v-if="step.status === 'pending' && canDecide(step)" class="step-actions">
				<input v-model="comments[step.id]" type="text" :placeholder="t('openregister', 'Comment...')">
				<NcButton type="success" @click="approve(step)">
					{{ t('openregister', 'Approve') }}
				</NcButton>
				<NcButton type="error" @click="reject(step)">
					{{ t('openregister', 'Reject') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ApprovalStepList',
	components: { NcButton },
	props: {
		objectUuid: { type: String, required: true },
	},
	data() {
		return {
			steps: [],
			comments: {},
		}
	},
	mounted() {
		this.fetchSteps()
	},
	methods: {
		async fetchSteps() {
			try {
				const url = generateUrl('/apps/openregister/api/approval-steps')
				const response = await axios.get(url, { params: { objectUuid: this.objectUuid } })
				this.steps = response.data || []
			} catch (error) {
				console.error('Failed to fetch steps:', error)
			}
		},
		canDecide() {
			return true
		},
		async approve(step) {
			try {
				const url = generateUrl(`/apps/openregister/api/approval-steps/${step.id}/approve`)
				await axios.post(url, { comment: this.comments[step.id] || '' })
				this.fetchSteps()
			} catch (error) {
				console.error('Failed to approve:', error)
			}
		},
		async reject(step) {
			try {
				const url = generateUrl(`/apps/openregister/api/approval-steps/${step.id}/reject`)
				await axios.post(url, { comment: this.comments[step.id] || '' })
				this.fetchSteps()
			} catch (error) {
				console.error('Failed to reject:', error)
			}
		},
	},
}
</script>

<style scoped>
.step-row { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--color-border); }
.step-order { font-weight: bold; }
.status-badge { padding: 2px 6px; border-radius: 3px; font-size: 0.85em; }
.status-pending { background: var(--color-warning); color: white; }
.status-approved { background: var(--color-success); color: white; }
.status-rejected { background: var(--color-error); color: white; }
.status-waiting { background: var(--color-background-dark); }
.step-actions { display: flex; gap: 4px; margin-left: auto; }
</style>
