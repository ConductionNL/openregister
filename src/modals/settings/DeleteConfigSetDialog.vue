<template>
	<NcDialog
		v-if="navigationStore.dialog === 'deleteConfigSet' && navigationStore.transferData"
		:name="t('openregister', 'Delete ConfigSet')"
		:size="'normal'"
		@closing="closeDialog">
		<div class="delete-configset-dialog">
			<p>{{ t('openregister', 'Are you sure you want to delete this ConfigSet?') }}</p>
			<div class="warning-box">
				<p><strong>⚠️ {{ t('openregister', 'Warning') }}</strong></p>
				<p>{{ t('openregister', 'ConfigSet:') }} <strong>{{ navigationStore.transferData.name }}</strong></p>
				<p>{{ t('openregister', 'This action cannot be undone. Make sure no collections are using this ConfigSet.') }}</p>
			</div>

			<div class="form-actions">
				<NcButton @click="closeDialog">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					type="error"
					:disabled="deleting"
					@click="deleteConfigSet">
					<template #icon>
						<NcLoadingIcon v-if="deleting" :size="20" />
						<Delete v-else :size="20" />
					</template>
					{{ deleting ? t('openregister', 'Deleting...') : t('openregister', 'Delete ConfigSet') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { navigationStore } from '../../store/store.js'

export default {
	name: 'DeleteConfigSetDialog',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		Delete,
	},

	data() {
		return {
			navigationStore,
			deleting: false,
		}
	},

	methods: {
		closeDialog() {
			navigationStore.setDialog(false)
			navigationStore.clearTransferData()
			this.deleting = false
		},

		async deleteConfigSet() {
			const configSet = navigationStore.transferData
			if (!configSet) return

			this.deleting = true
			try {
				const url = generateUrl('/apps/openregister/api/solr/configsets/{name}', {
					name: configSet.name,
				})
				const response = await axios.delete(url)

				if (response.data.success) {
					showSuccess(this.t('openregister', 'ConfigSet deleted successfully'))
					this.closeDialog()
					// Trigger reload of ConfigSets in the management modal
					this.$emit('configset-deleted')
				} else {
					showError(response.data.error || 'Failed to delete ConfigSet')
				}
			} catch (error) {
				console.error('Failed to delete ConfigSet:', error)
				showError(error.response?.data?.error || error.message || 'Failed to delete ConfigSet')
			} finally {
				this.deleting = false
			}
		},
	},
}
</script>

<style scoped>
.delete-configset-dialog {
	padding: 20px;
}

.delete-configset-dialog > p {
	margin-bottom: 20px;
	color: var(--color-text-maxcontrast);
}

.warning-box {
	background: var(--color-warning-light);
	padding: 15px;
	border-radius: var(--border-radius);
	border-left: 4px solid var(--color-warning);
	margin: 15px 0;
}

.warning-box p {
	margin: 8px 0;
}

.warning-box strong {
	color: var(--color-warning-text);
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
	padding-top: 15px;
	border-top: 1px solid var(--color-border);
}
</style>
