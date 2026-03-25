<template>
	<div class="scheduled-workflow-panel">
		<h3>Scheduled Workflows</h3>
		<table v-if="schedules.length" class="schedule-table">
			<thead>
				<tr>
					<th>Name</th>
					<th>Engine</th>
					<th>Workflow</th>
					<th>Interval</th>
					<th>Enabled</th>
					<th>Last Run</th>
					<th>Last Status</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="s in schedules" :key="s.id">
					<td>{{ s.name }}</td>
					<td>{{ s.engine }}</td>
					<td>{{ s.workflowId }}</td>
					<td>{{ formatInterval(s.intervalSec) }}</td>
					<td>{{ s.enabled ? 'Yes' : 'No' }}</td>
					<td>{{ s.lastRun ? new Date(s.lastRun).toLocaleString() : '-' }}</td>
					<td>
						<span v-if="s.lastStatus" :class="['status-badge', `status-${s.lastStatus}`]">
							{{ s.lastStatus }}
						</span>
						<span v-else>-</span>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else>
			No scheduled workflows configured.
		</p>
		<NcButton type="primary" @click="showForm = !showForm">
			{{ showForm ? 'Cancel' : 'Add Schedule' }}
		</NcButton>
		<div v-if="showForm" class="create-form">
			<div class="form-group">
				<label>Name</label>
				<input v-model="form.name" type="text" class="input-field">
			</div>
			<div class="form-group">
				<label>Engine</label>
				<input v-model="form.engine" type="text" class="input-field">
			</div>
			<div class="form-group">
				<label>Workflow ID</label>
				<input v-model="form.workflowId" type="text" class="input-field">
			</div>
			<div class="form-group">
				<label>Interval (seconds)</label>
				<input v-model.number="form.interval" type="number" class="input-field">
			</div>
			<NcButton type="primary" @click="createSchedule">
				Save
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ScheduledWorkflowPanel',
	components: { NcButton },
	data() {
		return {
			schedules: [],
			showForm: false,
			form: { name: '', engine: 'n8n', workflowId: '', interval: 86400 },
		}
	},
	mounted() {
		this.fetchSchedules()
	},
	methods: {
		async fetchSchedules() {
			try {
				const url = generateUrl('/apps/openregister/api/scheduled-workflows')
				const response = await axios.get(url)
				this.schedules = response.data || []
			} catch (error) {
				console.error('Failed to fetch schedules:', error)
			}
		},
		async createSchedule() {
			try {
				const url = generateUrl('/apps/openregister/api/scheduled-workflows')
				await axios.post(url, this.form)
				this.showForm = false
				this.fetchSchedules()
			} catch (error) {
				console.error('Failed to create schedule:', error)
			}
		},
		formatInterval(seconds) {
			if (seconds >= 86400) return `${Math.floor(seconds / 86400)}d`
			if (seconds >= 3600) return `${Math.floor(seconds / 3600)}h`
			return `${Math.floor(seconds / 60)}m`
		},
	},
}
</script>

<style scoped>
.schedule-table { width: 100%; border-collapse: collapse; }
.schedule-table th, .schedule-table td { padding: 8px; border-bottom: 1px solid var(--color-border); }
.create-form { margin-top: 12px; padding: 12px; border: 1px solid var(--color-border); border-radius: 8px; }
.form-group { margin-bottom: 8px; }
.form-group label { display: block; font-weight: bold; }
.input-field { width: 100%; padding: 8px; }
</style>
