<script setup>
import { translate as t } from '@nextcloud/l10n'
import { sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editSource'"
		:name="sourceStore.sourceItem?.id ? t('openregister', 'Edit Source') : t('openregister', 'Add Source')"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>{{ t('openregister', 'Source successfully updated') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="actionMessage" :type="actionMessageType">
			<p>{{ actionMessage }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
			<NcTextField :disabled="loading"
				:label="t('openregister', 'Title *')"
				:value.sync="sourceItem.title" />
			<NcTextArea :disabled="loading"
				:label="t('openregister', 'Description')"
				:value.sync="sourceItem.description" />
			<NcSelect v-bind="typeOptions"
				v-model="typeOptions.value"
				:input-label="t('openregister', 'Type')"
				:disabled="loading" />

			<template v-if="!isDatabaseType">
				<NcTextField :disabled="loading"
					:label="t('openregister', 'Database URL')"
					:value.sync="sourceItem.databaseUrl" />
			</template>

			<template v-if="isDatabaseType">
				<NcSelect v-bind="driverOptions"
					v-model="driverOptions.value"
					:input-label="t('openregister', 'Driver')"
					:disabled="loading" />
				<template v-if="driverOptions.value?.id === 'pdo_sqlite'">
					<NcTextField :disabled="loading"
						:label="t('openregister', 'Database file path *')"
						:value.sync="connection.path" />
				</template>
				<template v-else>
					<NcTextField :disabled="loading"
						:label="t('openregister', 'Host *')"
						:value.sync="connection.host" />
					<NcTextField :disabled="loading"
						:label="t('openregister', 'Port')"
						:value.sync="connection.port" />
					<NcTextField :disabled="loading"
						:label="t('openregister', 'Database name *')"
						:value.sync="connection.dbname" />
					<NcTextField :disabled="loading"
						:label="t('openregister', 'User')"
						:value.sync="connection.user" />
					<NcTextField :disabled="loading"
						:label="t('openregister', 'Credential (UUID from the credential store)')"
						:value.sync="connection.credential" />
				</template>
				<p class="databaseHint">
					{{ t('openregister', 'The database password is never stored on the source; reference a credential from the credential store instead.') }}
				</p>
			</template>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? t('openregister', 'Close') : t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton v-if="!success && isDatabaseType && sourceItem.id"
				:disabled="loading || actionLoading"
				@click="testConnection()">
				<template #icon>
					<NcLoadingIcon v-if="actionLoading" :size="20" />
					<DatabaseCheckOutline v-else :size="20" />
				</template>
				{{ t('openregister', 'Test connection') }}
			</NcButton>
			<NcButton v-if="!success && isDatabaseType && sourceItem.id"
				:disabled="loading || actionLoading"
				@click="introspect()">
				<template #icon>
					<NcLoadingIcon v-if="actionLoading" :size="20" />
					<DatabaseImportOutline v-else :size="20" />
				</template>
				{{ t('openregister', 'Create virtual register') }}
			</NcButton>
			<NcButton v-if="!success"
				:disabled="loading || !sourceItem.title"
				type="primary"
				@click="editSource()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && sourceStore.sourceItem?.id" :size="20" />
					<Plus v-if="!loading && !sourceStore.sourceItem?.id" :size="20" />
				</template>
				{{ sourceStore.sourceItem?.id ? t('openregister', 'Save') : t('openregister', 'Create') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcTextArea,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import DatabaseCheckOutline from 'vue-material-design-icons/DatabaseCheckOutline.vue'
import DatabaseImportOutline from 'vue-material-design-icons/DatabaseImportOutline.vue'

export default {
	name: 'EditSource',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
		DatabaseCheckOutline,
		DatabaseImportOutline,
	},
	data() {
		return {
			sourceItem: {
				title: '',
				description: '',
				databaseUrl: '',
			},
			connection: {
				host: '',
				port: '',
				dbname: '',
				user: '',
				credential: '',
				path: '',
			},
			typeOptions: {
				clearable: false,
				options: [
					{ label: 'Internal', id: 'internal' },
					{ label: 'MongoDB', id: 'mongodb' },
					{ label: 'Database (virtual register)', id: 'database' },
				],
				value: { label: 'Internal', id: 'internal' },
			},
			driverOptions: {
				clearable: false,
				options: [
					{ label: 'MySQL / MariaDB', id: 'pdo_mysql' },
					{ label: 'PostgreSQL', id: 'pdo_pgsql' },
					{ label: 'SQLite', id: 'pdo_sqlite' },
				],
				value: { label: 'MySQL / MariaDB', id: 'pdo_mysql' },
			},
			success: false,
			loading: false,
			actionLoading: false,
			actionMessage: '',
			actionMessageType: 'success',
			error: false,
			hasUpdated: false,
			closeModalTimeout: null,
		}
	},
	computed: {
		/**
		 * Whether the selected source type is a DBAL virtual-register database.
		 *
		 * @spec openspec/specs/dbal-virtual-registers/spec.md
		 * @return {boolean} True when the database type is selected.
		 */
		isDatabaseType() {
			return this.typeOptions.value?.id === 'database'
		},
	},
	mounted() {
		this.initializeSourceItem()
	},
	/**
	 * @spec exclude Vue lifecycle hook — initializes the source form once when the modal opens.
	 */
	updated() {
		if (navigationStore.modal === 'editSource' && !this.hasUpdated) {
			this.initializeSourceItem()
			this.hasUpdated = true
		}
	},
	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		initializeSourceItem() {
			if (sourceStore.sourceItem?.id) {
				this.sourceItem = {
					...sourceStore.sourceItem,
				}

				// set typeOptions to the sourceItem type
				this.typeOptions.value = this.typeOptions.options.find(option => option.id === this.sourceItem.type)
					|| this.typeOptions.options.find(option => option.id === 'internal')

				// Restore non-secret database connection parts (the password is
				// never on the source — it lives behind the credential store).
				const authConfig = sourceStore.sourceItem.authConfig || {}
				this.connection = {
					host: authConfig.host || '',
					port: authConfig.port || '',
					dbname: authConfig.dbname || '',
					user: authConfig.user || '',
					credential: authConfig.credential || '',
					path: authConfig.path || '',
				}
				if (authConfig.driver) {
					this.driverOptions.value = this.driverOptions.options.find(option => option.id === authConfig.driver)
						|| this.driverOptions.value
				}
			}
		},
		/**
		 * @spec exclude Modal close plumbing — resets the source form and closes the modal.
		 */
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.actionLoading = false
			this.actionMessage = ''
			this.error = false
			this.hasUpdated = false
			this.sourceItem = {
				id: '',
				title: '',
				description: '',
				databaseUrl: '',
			}
			this.connection = {
				host: '',
				port: '',
				dbname: '',
				user: '',
				credential: '',
				path: '',
			}
			// reset typeOptions to the internal option
			this.typeOptions.value = this.typeOptions.options.find(option => option.id === 'internal')
		},
		/**
		 * @spec exclude Modal save plumbing — delegates source persistence to sourceStore.saveSource.
		 */
		async editSource() {
			this.loading = true

			const payload = {
				...this.sourceItem,
				type: this.typeOptions.value.id,
			}

			// Database sources carry only NON-SECRET connection parts in
			// authConfig; the password stays behind the credential store.
			if (this.isDatabaseType) {
				delete payload.databaseUrl
				payload.authConfig = {
					driver: this.driverOptions.value.id,
					host: this.connection.host,
					port: this.connection.port,
					dbname: this.connection.dbname,
					user: this.connection.user,
					credential: this.connection.credential,
					path: this.connection.path,
				}
			}

			sourceStore.saveSource(payload).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && (this.closeModalTimeout = setTimeout(this.closeModal, 2000))
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while saving the source'
			}).finally(() => {
				this.loading = false
			})
		},
		/**
		 * Test the saved database source's connection (never exposes the password).
		 *
		 * @spec openspec/specs/dbal-virtual-registers/spec.md
		 * @return {Promise<void>} Resolves when the test finished.
		 */
		async testConnection() {
			await this.runSourceAction('test-connection', t('openregister', 'Connection successful'))
		},
		/**
		 * Introspect the database source into a virtual register + schemas.
		 *
		 * @spec openspec/specs/dbal-virtual-registers/spec.md
		 * @return {Promise<void>} Resolves when introspection finished.
		 */
		async introspect() {
			await this.runSourceAction('introspect', null)
		},
		/**
		 * POST a source action endpoint and surface the outcome in the modal.
		 *
		 * @spec openspec/specs/dbal-virtual-registers/spec.md
		 * @param {string} action The action path segment (test-connection|introspect).
		 * @param {string|null} successMessage Message on success, or null to derive from the response.
		 * @return {Promise<void>} Resolves when the action finished.
		 */
		async runSourceAction(action, successMessage) {
			this.actionLoading = true
			this.actionMessage = ''

			try {
				const response = await fetch(
					generateUrl(`/apps/openregister/api/sources/${this.sourceItem.id}/${action}`),
					{
						method: 'POST',
						headers: { requesttoken: window.OC?.requestToken || '' },
					},
				)
				const data = await response.json()

				if (response.ok) {
					this.actionMessageType = 'success'
					if (successMessage) {
						this.actionMessage = successMessage
					} else {
						const created = (data.created || []).length
						const updated = (data.updated || []).length
						this.actionMessage = t(
							'openregister',
							'Virtual register created: {created} new and {updated} updated schemas',
							{ created, updated },
						)
					}
				} else {
					this.actionMessageType = 'error'
					this.actionMessage = data.error || t('openregister', 'The action failed')
				}
			} catch (error) {
				this.actionMessageType = 'error'
				this.actionMessage = error.message || t('openregister', 'The action failed')
			} finally {
				this.actionLoading = false
			}
		},
	},
}
</script>

<style scoped>
.databaseHint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
