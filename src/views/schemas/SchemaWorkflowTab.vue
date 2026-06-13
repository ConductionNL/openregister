<template>
	<div class="schema-workflow-tab">
		<NcAppContentDetails>
			<h2>Workflows</h2>

			<section class="tab-section">
				<HookList
					:hooks="hooks"
					@add="showHookForm = true; editingHookIndex = null"
					@edit="editHook"
					@delete="deleteHook"
					@test="openTestDialog" />
			</section>

			<HookForm
				v-if="showHookForm"
				:hook="editingHookIndex !== null ? hooks[editingHookIndex] : null"
				:engines="engines"
				@save="saveHook"
				@cancel="showHookForm = false" />

			<TestHookDialog
				v-if="testHook"
				:hook="testHook"
				:engine-id="testEngineId"
				@close="testHook = null" />

			<section class="tab-section">
				<WorkflowExecutionPanel :schema-id="schemaId" />
			</section>

			<section class="tab-section">
				<ScheduledWorkflowPanel />
			</section>

			<section class="tab-section">
				<ApprovalChainPanel :schema-id="schemaId" />
			</section>
		</NcAppContentDetails>
	</div>
</template>

<script>
import { NcAppContentDetails } from '@nextcloud/vue'
import HookList from '../../components/workflow/HookList.vue'
import HookForm from '../../components/workflow/HookForm.vue'
import TestHookDialog from '../../dialogs/TestHookDialog.vue'
import WorkflowExecutionPanel from '../../components/workflow/WorkflowExecutionPanel.vue'
import ScheduledWorkflowPanel from '../../components/workflow/ScheduledWorkflowPanel.vue'
import ApprovalChainPanel from '../../components/workflow/ApprovalChainPanel.vue'

export default {
	name: 'SchemaWorkflowTab',
	components: {
		NcAppContentDetails,
		HookList,
		HookForm,
		TestHookDialog,
		WorkflowExecutionPanel,
		ScheduledWorkflowPanel,
		ApprovalChainPanel,
	},
	props: {
		schema: { type: Object, required: true },
	},
	data() {
		return {
			showHookForm: false,
			editingHookIndex: null,
			testHook: null,
			testEngineId: null,
			engines: [],
		}
	},
	computed: {
		/**
		 * @spec exclude tab-view prop passthrough for the schema id (computed)
		 */
		schemaId() {
			return this.schema?.id || null
		},
		/**
		 * @spec exclude tab-view prop passthrough for the schema hooks list (computed)
		 */
		hooks() {
			return this.schema?.hooks || []
		},
	},
	methods: {
		/**
		 * @param index
		 * @spec exclude tab-view hook-form open plumbing for editing
		 */
		editHook(index) {
			this.editingHookIndex = index
			this.showHookForm = true
		},
		/**
		 * @param index
		 * @spec exclude tab-view hook-list mutation; emits an update:hooks event with the entry removed
		 */
		deleteHook(index) {
			const hooks = [...this.hooks]
			hooks.splice(index, 1)
			this.$emit('update:hooks', hooks)
		},
		/**
		 * @param hookData
		 * @spec exclude tab-view hook-list mutation; emits an update:hooks event with the saved entry
		 */
		saveHook(hookData) {
			const hooks = [...this.hooks]
			if (this.editingHookIndex !== null) {
				hooks[this.editingHookIndex] = hookData
			} else {
				hookData.id = `hook-${Date.now()}`
				hooks.push(hookData)
			}
			this.$emit('update:hooks', hooks)
			this.showHookForm = false
			this.editingHookIndex = null
		},
		/**
		 * @param hook
		 * @spec exclude tab-view test-dialog open plumbing for a hook
		 */
		openTestDialog(hook) {
			this.testHook = hook
			this.testEngineId = 1
		},
	},
}
</script>

<style scoped>
.schema-workflow-tab { padding: 20px; }
.tab-section { margin-bottom: 24px; }
</style>
