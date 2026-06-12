<template>
	<div class="workflow-execution-panel">
		<h3>Execution History</h3>
		<div v-if="loading" class="loading">
			Loading...
		</div>
		<table v-else-if="executions.length" class="execution-table">
			<thead>
				<tr>
					<th>Timestamp</th>
					<th>Hook</th>
					<th>Object</th>
					<th>Status</th>
					<th>Duration</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="exec in executions" :key="exec.id" @click="selectedExecution = exec">
					<td>{{ formatDate(exec.executedAt) }}</td>
					<td>{{ exec.hookId }}</td>
					<td>{{ exec.objectUuid }}</td>
					<td>
						<span :class="['status-badge', `status-${exec.status}`]">
							{{ exec.status }}
						</span>
					</td>
					<td>{{ exec.durationMs }}ms</td>
				</tr>
			</tbody>
		</table>
		<p v-else>
			No executions found.
		</p>
		<div v-if="total > limit" class="pagination">
			<NcButton :disabled="offset === 0" @click="prevPage">
				Previous
			</NcButton>
			<span>{{ offset + 1 }} - {{ Math.min(offset + limit, total) }} of {{ total }}</span>
			<NcButton :disabled="offset + limit >= total" @click="nextPage">
				Next
			</NcButton>
		</div>
		<WorkflowExecutionDetail
			v-if="selectedExecution"
			:execution="selectedExecution"
			@close="selectedExecution = null" />
	</div>
</template>

<script>
/**
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-83
 */
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import WorkflowExecutionDetail from './WorkflowExecutionDetail.vue'

export default {
	name: 'WorkflowExecutionPanel',
	components: { NcButton, WorkflowExecutionDetail },
	props: {
		schemaId: { type: Number, default: null },
	},
	data() {
		return {
			executions: [],
			total: 0,
			limit: 20,
			offset: 0,
			loading: false,
			selectedExecution: null,
		}
	},
	mounted() {
		this.fetchExecutions()
	},
	methods: {
		/**
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-83
		 */
		async fetchExecutions() {
			this.loading = true
			try {
				const params = { limit: this.limit, offset: this.offset }
				if (this.schemaId) params.schemaId = this.schemaId
				const url = generateUrl('/apps/openregister/api/workflow-executions')
				const response = await axios.get(url, { params })
				this.executions = response.data.results || []
				this.total = response.data.total || 0
			} catch (error) {
				console.error('Failed to fetch executions:', error)
			} finally {
				this.loading = false
			}
		},
		/**
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-83
		 */
		formatDate(dateStr) {
			if (!dateStr) return '-'
			return new Date(dateStr).toLocaleString()
		},
		/**
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-83
		 */
		prevPage() {
			this.offset = Math.max(0, this.offset - this.limit)
			this.fetchExecutions()
		},
		/**
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-83
		 */
		nextPage() {
			this.offset += this.limit
			this.fetchExecutions()
		},
	},
}
</script>

<style scoped>
.execution-table { width: 100%; border-collapse: collapse; }
.execution-table th, .execution-table td { padding: 8px; border-bottom: 1px solid var(--color-border); }
.execution-table tr:hover { background: var(--color-background-hover); cursor: pointer; }
.status-badge { padding: 2px 6px; border-radius: 3px; font-size: 0.85em; }
.status-approved { background: var(--color-success); color: white; }
.status-error, .status-rejected { background: var(--color-error); color: white; }
.status-modified, .status-delivered { background: var(--color-warning); color: white; }
.pagination { display: flex; align-items: center; gap: 8px; margin-top: 12px; }
</style>
