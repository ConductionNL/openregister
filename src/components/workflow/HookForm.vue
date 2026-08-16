<template>
	<div class="hook-form">
		<h3>{{ isEdit ? 'Edit Hook' : 'Add Hook' }}</h3>
		<div class="form-group">
			<label>Event Type</label>
			<NcSelect
				v-model="form.event"
				inputLabel="Form Event"
				:options="eventTypes" />
		</div>
		<div class="form-group">
			<label>Engine</label>
			<NcSelect
				v-model="form.engine"
				inputLabel="Form Engine"
				:options="engineOptions" />
		</div>
		<div class="form-group">
			<label for="hook-form-workflow-id">Workflow ID</label>
			<input
				id="hook-form-workflow-id"
				v-model="form.workflowId"
				type="text"
				class="input-field" />
		</div>
		<div class="form-group">
			<label>Mode</label>
			<NcSelect
				v-model="form.mode"
				inputLabel="Form Mode"
				:options="['sync', 'async']" />
		</div>
		<div class="form-group">
			<label for="hook-form-order">Order</label>
			<input
				id="hook-form-order"
				v-model.number="form.order"
				type="number"
				class="input-field" />
		</div>
		<div class="form-group">
			<label for="hook-form-timeout">Timeout (seconds)</label>
			<input
				id="hook-form-timeout"
				v-model.number="form.timeout"
				type="number"
				class="input-field" />
		</div>
		<div class="form-group">
			<label>On Failure</label>
			<NcSelect
				v-model="form.onFailure"
				inputLabel="Form On Failure"
				:options="failureModes" />
		</div>
		<div class="form-group">
			<label>On Timeout</label>
			<NcSelect
				v-model="form.onTimeout"
				inputLabel="Form On Timeout"
				:options="failureModes" />
		</div>
		<div class="form-group">
			<label>On Engine Down</label>
			<NcSelect
				v-model="form.onEngineDown"
				inputLabel="Form On Engine Down"
				:options="failureModes" />
		</div>
		<div class="form-group">
			<NcCheckboxRadioSwitch v-model="form.enabled">
				Enabled
			</NcCheckboxRadioSwitch>
		</div>
		<div class="form-actions">
			<NcButton @click="$emit('cancel')"> Cancel </NcButton>
			<NcButton variant="primary" @click="save">
				{{ isEdit ? 'Update' : 'Create' }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'

export default {
	name: 'HookForm',
	components: { NcButton, NcSelect, NcCheckboxRadioSwitch },
	props: {
		hook: { type: Object, default: null },
		engines: { type: Array, default: () => [] },
	},

	emits: ['save', 'cancel'],
	data() {
		return {
			form: {
				event: this.hook?.event || 'creating',
				engine: this.hook?.engine || '',
				workflowId: this.hook?.workflowId || '',
				mode: this.hook?.mode || 'sync',
				order: this.hook?.order || 0,
				timeout: this.hook?.timeout || 30,
				onFailure: this.hook?.onFailure || 'reject',
				onTimeout: this.hook?.onTimeout || 'reject',
				onEngineDown: this.hook?.onEngineDown || 'allow',
				enabled: this.hook?.enabled !== false,
			},

			eventTypes: [
				'creating',
				'updating',
				'deleting',
				'created',
				'updated',
				'deleted',
			],

			failureModes: ['reject', 'allow', 'flag', 'queue'],
		}
	},

	computed: {
		isEdit() {
			return this.hook !== null
		},

		/**
		 * @spec exclude computed select-option mapping from engines prop, UI plumbing
		 */
		engineOptions() {
			return this.engines.map((e) => e.engineType || e.name || e)
		},
	},

	methods: {
		/**
		 * @spec exclude emit UI handler dispatching save event with form data, UI plumbing
		 */
		save() {
			this.$emit('save', { ...this.form })
		},
	},
}
</script>

<style scoped>
.hook-form {
	padding: 16px;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.input-field {
	width: 100%;
	padding: 8px;
}

.form-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}
</style>
