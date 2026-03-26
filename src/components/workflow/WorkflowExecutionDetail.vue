<template>
	<div class="execution-detail">
		<h4>Execution Detail</h4>
		<NcButton type="tertiary" @click="$emit('close')">
			Close
		</NcButton>
		<dl class="detail-list">
			<dt>Hook ID</dt>
			<dd>{{ execution.hookId }}</dd>
			<dt>Event Type</dt>
			<dd>{{ execution.eventType }}</dd>
			<dt>Object UUID</dt>
			<dd>{{ execution.objectUuid }}</dd>
			<dt>Engine</dt>
			<dd>{{ execution.engine }}</dd>
			<dt>Workflow ID</dt>
			<dd>{{ execution.workflowId }}</dd>
			<dt>Mode</dt>
			<dd>{{ execution.mode }}</dd>
			<dt>Status</dt>
			<dd>
				<span :class="['status-badge', `status-${execution.status}`]">{{ execution.status }}</span>
			</dd>
			<dt>Duration</dt>
			<dd>{{ execution.durationMs }}ms</dd>
			<dt>Executed At</dt>
			<dd>{{ execution.executedAt }}</dd>
		</dl>
		<div v-if="execution.errors" class="section">
			<h5>Errors</h5>
			<pre>{{ JSON.stringify(execution.errors, null, 2) }}</pre>
		</div>
		<div v-if="execution.metadata" class="section">
			<h5>Metadata</h5>
			<pre>{{ JSON.stringify(execution.metadata, null, 2) }}</pre>
		</div>
		<div v-if="execution.payload" class="section">
			<h5>Payload</h5>
			<pre>{{ JSON.stringify(execution.payload, null, 2) }}</pre>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'WorkflowExecutionDetail',
	components: { NcButton },
	props: {
		execution: { type: Object, required: true },
	},
	emits: ['close'],
}
</script>

<style scoped>
.detail-list { display: grid; grid-template-columns: auto 1fr; gap: 4px 16px; }
.detail-list dt { font-weight: bold; }
.section { margin-top: 12px; }
.section pre { background: var(--color-background-dark); padding: 8px; border-radius: 4px; overflow: auto; }
</style>
