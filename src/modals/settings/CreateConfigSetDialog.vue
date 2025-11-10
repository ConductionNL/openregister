<template>
	<NcDialog
		v-if="navigationStore.dialog === 'createConfigSet'"
		:name="t('openregister', 'Create New ConfigSet')"
		:size="'normal'"
		@closing="closeDialog">
		<div class="create-configset-dialog">
			<p>{{ t('openregister', 'Create a new ConfigSet based on the _default template') }}</p>

			<div class="form-group">
				<label>{{ t('openregister', 'ConfigSet Name') }}*</label>
				<input
					v-model="configSetName"
					type="text"
					:placeholder="t('openregister', 'Enter ConfigSet name')"
					class="configset-name-input"
					@keyup.enter="!creating && configSetName && createConfigSet()">
				<p class="form-hint">
					{{ t('openregister', 'This will copy the _default ConfigSet with the new name') }}
				</p>
			</div>

			<div class="form-actions">
				<NcButton @click="closeDialog">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!configSetName || creating"
					@click="createConfigSet">
					<template #icon>
						<NcLoadingIcon v-if="creating" :size="20" />
						<Plus v-else :size="20" />
					</template>
					{{ creating ? t('openregister', 'Creating...') : t('openregister', 'Create ConfigSet') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { navigationStore } from '../../store/store.js'

export default {
	name: 'CreateConfigSetDialog',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		Plus,
	},

	data() {
		return {
			navigationStore,
			configSetName: '',
			creating: false,
		}
	},

	watch: {
		'navigationStore.dialog': {
			handler(newValue) {
				console.log('👁️ CreateConfigSetDialog - navigationStore.dialog changed to:', newValue)
				console.log('👁️ Should show?', newValue === 'createConfigSet')
			},
			immediate: true,
		},
	},

	mounted() {
		console.log('✅ CreateConfigSetDialog mounted')
		console.log('✅ navigationStore.dialog:', navigationStore.dialog)
	},

	methods: {
		closeDialog() {
			navigationStore.setDialog(false)
			this.configSetName = ''
			this.creating = false
		},

		async createConfigSet() {
			if (!this.configSetName) return

			this.creating = true
			try {
				const url = generateUrl('/apps/openregister/api/solr/configsets')
				const response = await axios.post(url, {
					name: this.configSetName,
					baseConfigSet: '_default',
				})

				if (response.data.success) {
					showSuccess(this.t('openregister', 'ConfigSet created successfully'))
					this.closeDialog()
					// Trigger reload of ConfigSets in the management modal
					this.$emit('configset-created')
				} else {
					showError(response.data.error || 'Failed to create ConfigSet')
				}
			} catch (error) {
				console.error('Failed to create ConfigSet:', error)
				showError(error.response?.data?.error || error.message || 'Failed to create ConfigSet')
			} finally {
				this.creating = false
			}
		},
	},
}
</script>

<style scoped>
.create-configset-dialog {
	padding: 20px;
}

.create-configset-dialog p {
	margin-bottom: 20px;
	color: var(--color-text-maxcontrast);
}

.form-group {
	margin-bottom: 20px;
}

.form-group label {
	display: block;
	margin-bottom: 8px;
	font-weight: 600;
	color: var(--color-main-text);
}

.configset-name-input {
	width: 100%;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 14px;
}

.form-hint {
	margin-top: 5px;
	font-size: 12px;
	color: var(--color-text-lighter);
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
