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
<<<<<<< HEAD
import TestHookDialog from '../../dialogs/TestHookDialog.vue'
=======
import TestHookDialog from '../../components/workflow/TestHookDialog.vue'
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
<<<<<<< HEAD
		/**
		 * @spec exclude tab-view prop passthrough for the schema id (computed)
		 */
		schemaId() {
			return this.schema?.id || null
		},
		/**
		 * @spec exclude tab-view prop passthrough for the schema hooks list (computed)
		 */
=======
		schemaId() {
			return this.schema?.id || null
		},
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
		hooks() {
			return this.schema?.hooks || []
		},
	},
	methods: {
<<<<<<< HEAD
		/**
		 * @spec exclude tab-view hook-form open plumbing for editing
		 */
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
		editHook(index) {
			this.editingHookIndex = index
			this.showHookForm = true
		},
<<<<<<< HEAD
		/**
		 * @spec exclude tab-view hook-list mutation; emits an update:hooks event with the entry removed
		 */
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
		deleteHook(index) {
			const hooks = [...this.hooks]
			hooks.splice(index, 1)
			this.$emit('update:hooks', hooks)
		},
<<<<<<< HEAD
		/**
		 * @spec exclude tab-view hook-list mutation; emits an update:hooks event with the saved entry
		 */
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
<<<<<<< HEAD
		/**
		 * @spec exclude tab-view test-dialog open plumbing for a hook
		 */
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
