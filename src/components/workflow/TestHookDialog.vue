<template>
	<NcDialog :open.sync="isOpen" name="Test Hook (Dry Run)" size="large">
		<div class="test-hook-dialog">
			<p class="warning-text">
				Dry run -- no data will be persisted.
			</p>
			<div class="form-group">
				<label>Sample Data (JSON)</label>
				<textarea v-model="sampleDataJson" rows="10" class="json-editor" />
			</div>
			<div class="form-actions">
				<NcButton @click="isOpen = false">
					Cancel
				</NcButton>
				<NcButton type="primary" :disabled="loading" @click="runTest">
					{{ loading ? 'Running...' : 'Run Test' }}
				</NcButton>
			</div>
			<div v-if="result" class="test-result">
				<h4>Result</h4>
				<div :class="['status-badge', `status-${result.status}`]">
					{{ result.status }}
				</div>
				<pre v-if="result.data">{{ JSON.stringify(result.data, null, 2) }}</pre>
				<div v-if="result.errors && result.errors.length" class="errors">
					<h5>Errors</h5>
					<ul>
						<li v-for="(err, i) in result.errors" :key="i">
							{{ err.message }}
						</li>
					</ul>
				</div>
				<p class="dry-run-note">
					Dry run -- no data was persisted
				</p>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'TestHookDialog',
	components: { NcButton, NcDialog },
	props: {
		hook: { type: Object, default: null },
		engineId: { type: Number, default: null },
	},
	emits: ['close'],
	data() {
		return {
			isOpen: true,
			loading: false,
			result: null,
			sampleDataJson: JSON.stringify({}, null, 2),
		}
	},
	methods: {
		async runTest() {
			this.loading = true
			this.result = null
			try {
				let sampleData = {}
				try {
					sampleData = JSON.parse(this.sampleDataJson)
				} catch (e) {
					this.result = { status: 'error', errors: [{ message: 'Invalid JSON' }] }
					return
				}
				const url = generateUrl(`/apps/openregister/api/engines/${this.engineId}/test-hook`)
				const response = await axios.post(url, {
					workflowId: this.hook?.workflowId,
					sampleData,
					timeout: this.hook?.timeout || 30,
				})
				this.result = response.data
			} catch (error) {
				this.result = error.response?.data || { status: 'error', errors: [{ message: error.message }] }
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.test-hook-dialog { padding: 16px; }
.warning-text { color: var(--color-warning); font-weight: bold; }
.json-editor { width: 100%; font-family: monospace; padding: 8px; }
.form-actions { display: flex; gap: 8px; justify-content: flex-end; margin: 12px 0; }
.status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-weight: bold; }
.status-approved { background: var(--color-success); color: white; }
.status-modified { background: var(--color-warning); color: white; }
.status-rejected, .status-error { background: var(--color-error); color: white; }
.dry-run-note { font-style: italic; color: var(--color-text-lighter); margin-top: 8px; }
</style>
