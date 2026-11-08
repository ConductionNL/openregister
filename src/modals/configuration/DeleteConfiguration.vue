<template>
	<NcDialog
		v-if="navigationStore.dialog === 'deleteConfiguration'"
		name="Delete Configuration"
		size="small"
		noClose>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<p>
				Are you sure you want to delete the configuration "{{
					configurationStore.configurationItem?.title
				}}"?
			</p>
			<p>{{ t('openregister', 'This action cannot be undone.') }}</p>
		</div>

		<template #actions>
			<CloseCancelButton label="Cancel" @click="closeModal" />
			<NcButton
				:disabled="loading"
				variant="error"
				@click="deleteConfiguration">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Delete v-else :size="20" />
				</template>
				Delete
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import { configurationStore, navigationStore } from '../../store/store.js'

export default {
	name: 'DeleteConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		CloseCancelButton,
		Delete,
	},

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
