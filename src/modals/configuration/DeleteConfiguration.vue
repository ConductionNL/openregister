<template>
	<ConfirmDialogFrame
		v-if="navigationStore.dialog === 'deleteConfiguration'"
		name="Delete Configuration"
		size="small"
		:error="error"
		:loading="loading"
		closeLabel="Cancel"
		confirmLabel="Delete"
		showConfirm
		@cancel="closeModal"
		@confirm="deleteConfiguration">
		<div class="formContainer">
			<p>
				Are you sure you want to delete the configuration "{{
					configurationStore.configurationItem?.title
				}}"?
			</p>
			<p>{{ t('openregister', 'This action cannot be undone.') }}</p>
		</div>
		<template #confirmIcon>
			<Delete :size="20" />
		</template>
	</ConfirmDialogFrame>
</template>

<script>
import Delete from 'vue-material-design-icons/Delete.vue'
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'
import { configurationStore, navigationStore } from '../../store/store.js'

export default {
	name: 'DeleteConfiguration',
	components: { ConfirmDialogFrame, Delete },

	/**
	 * @spec exclude Vue setup() exposing stores to the template; framework plumbing.
	 */
	setup() {
		return { configurationStore, navigationStore }
	},

	data() {
		return {
			loading: false,
			error: null,
		}
	},

	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		closeModal() {
			navigationStore.setDialog(false)
			this.loading = false
			this.error = null
		},

		/**
		 * @spec exclude Delete-confirm handler delegating to configurationStore.deleteConfiguration; entity mutation lives in the store, this is modal orchestration plumbing.
		 */
		async deleteConfiguration() {
			this.loading = true
			this.error = null

			try {
				await configurationStore.deleteConfiguration(
					configurationStore.configurationItem,
				)
				this.closeModal()
			} catch (error) {
				this.error = error.message || 'Failed to delete configuration'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style lang="scss">
@use '../../styles/modals.scss' as *;

@include form-container;
</style>
