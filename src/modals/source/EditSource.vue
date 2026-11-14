<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, sourceStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'editSource'"
		:name="
			sourceStore.sourceItem?.id
				? t('openregister', 'Edit Source')
				: t('openregister', 'Add Source')
		"
		size="normal"
		noClose>
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
			<NcTextField
				v-model="sourceItem.title"
				:disabled="loading"
				:label="t('openregister', 'Title *')" />
			<NcTextArea
				v-model="sourceItem.description"
				:disabled="loading"
				:label="t('openregister', 'Description')" />
			<NcSelect
				v-bind="typeOptions"
				v-model="typeOptions.value"
				:inputLabel="t('openregister', 'Type')"
				:disabled="loading" />

			<template v-if="!isDatabaseType">
				<NcTextField
					v-model="sourceItem.schedule"
					:disabled="loading"
					:label="t('openregister', 'Schedule')"
					placeholder="0 * * * *" />
				<NcTextField
					v-model="sourceItem.runAs"
					:disabled="loading"
					:label="t('openregister', 'Account')" />
				<NcTextField
					v-model="sourceItem.databaseUrl"
					:disabled="loading"
					:label="t('openregister', 'Database URL')" />
			</template>

			<template v-if="isDatabaseType">
				<NcSelect
					v-bind="driverOptions"
					v-model="driverOptions.value"
					:inputLabel="t('openregister', 'Driver')"
					:disabled="loading" />
				<template v-if="driverOptions.value?.id === 'pdo_sqlite'">
					<NcTextField
						v-model="connection.path"
						:disabled="loading"
						:label="t('openregister', 'Database file path *')" />
				</template>
				<template v-else>
					<NcTextField
						v-model="connection.host"
						:disabled="loading"
						:label="t('openregister', 'Host *')" />
					<NcTextField
						v-model="connection.port"
						:disabled="loading"
						:label="t('openregister', 'Port')" />
					<NcTextField
						v-model="connection.dbname"
						:disabled="loading"
						:label="t('openregister', 'Database name *')" />
					<NcTextField
						v-model="connection.user"
						:disabled="loading"
						:label="t('openregister', 'User')" />
					<NcTextField
						v-model="connection.credential"
						:disabled="loading"
						:label="
							t(
								'openregister',
								'Credential (UUID from the credential store)',
							)
						" />
				</template>
				<NcCheckboxRadioSwitch v-model="connection.writable" type="switch">
					{{
						t(
							'openregister',
							'Allow writes (create, update and delete push through to the external database)',
						)
					}}
				</NcCheckboxRadioSwitch>
				<p v-if="connection.writable" class="databaseHint">
					{{
						t(
							'openregister',
							'Warning: writes are executed directly on the external database. Use a database user with least-privilege grants. Re-run "Create virtual register" to unlock existing schemas.',
						)
					}}
				</p>
				<p class="databaseHint">
					{{
						t(
							'openregister',
							'The database password is never stored on the source; reference a credential from the credential store instead.',
						)
					}}
				</p>
			</template>
		</div>

		<template #actions>
			<CloseCancelButton :success="success" @click="closeModal" />
			<BusyButton
				v-if="!success && isDatabaseType && sourceItem.id"
				:busy="actionLoading"
				:disabled="loading || actionLoading"
				@click="testConnection()">
				<template #icon>
					<DatabaseCheckOutline :size="20" />
				</template>
				{{ t('openregister', 'Test connection') }}
			</BusyButton>
			<BusyButton
				v-if="!success && isDatabaseType && sourceItem.id"
				:busy="actionLoading"
				:disabled="loading || actionLoading"
				@click="introspect()">
				<template #icon>
					<DatabaseImportOutline :size="20" />
				</template>
				{{ t('openregister', 'Create virtual register') }}
			</BusyButton>
			<BusyButton
				v-if="!success"
				:busy="loading"
				:disabled="loading || !sourceItem.title"
				variant="primary"
				@click="editSource()">
				<template #icon>
					<ContentSaveOutline
						v-if="sourceStore.sourceItem?.id"
						:size="20" />
					<Plus v-else :size="20" />
				</template>
				{{
					sourceStore.sourceItem?.id
						? t('openregister', 'Save')
						: t('openregister', 'Create')
				}}
			</BusyButton>
		</template>
	</NcDialog>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import {
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import DatabaseCheckOutline from 'vue-material-design-icons/DatabaseCheckOutline.vue'
import DatabaseImportOutline from 'vue-material-design-icons/DatabaseImportOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import BusyButton from '../../components/shared/BusyButton.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import { freshState } from '../../services/initialState.js'
import { authConfigOf, closedForm, connectionOf, optionOf } from './sourceForm.js'

/**
 * The state every instance of this component starts from.
 */
const INITIAL_STATE = {
	sourceItem: {
		title: '',
		description: '',
		databaseUrl: '',
		schedule: '',
		runAs: '',
	},

	connection: {
		host: '',
		port: '',
		dbname: '',
		user: '',
		credential: '',
		path: '',
		writable: false,
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

export default {
	name: 'EditSource',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		BusyButton,
		CloseCancelButton,
		// Icons
		ContentSaveOutline,
		Plus,
		DatabaseCheckOutline,
		DatabaseImportOutline,
	},

	data() {
		return freshState(INITIAL_STATE)
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
		this.loadTypes()
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
			const source = sourceStore.sourceItem
			if (!source?.id) {
				// The harvest account is prefilled with the admin creating the
				// source, visibly and editable; nothing fills it in silently.
				this.sourceItem.runAs = getCurrentUser()?.uid || ''
				return
			}

			const authConfig = source.authConfig || {}
			this.sourceItem = { ...source }
			this.typeOptions.value =
				optionOf(this.typeOptions, source.type)
				|| optionOf(this.typeOptions, 'internal')
			this.connection = connectionOf(authConfig)
			this.driverOptions.value =
				optionOf(this.driverOptions, authConfig.driver)
				|| this.driverOptions.value
		},

		/**
		 * Add every source type an app registered to the type list.
		 *
		 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
		 * @return {Promise<void>} Resolves when the list is read; a failed read keeps the built-in types.
		 */
		async loadTypes() {
			try {
				const response = await fetch(generateUrl('/apps/openregister/api/sources/types'), {
					headers: { requesttoken: window.OC?.requestToken || '' },
				})
				if (!response?.ok) return
				const body = await response.json()
				this.addTypes(body?.results || [])
			} catch {
				// The built-in types stay available.
			}
		},

		/**
		 * Append registered types the list does not hold yet.
		 *
		 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
		 * @param {Array<{type: string, displayName: string}>} types The registered types
		 * @return {void}
		 */
		addTypes(types) {
			const known = new Set(this.typeOptions.options.map((option) => option.id))
			for (const entry of types) {
				if (!entry?.type || known.has(entry.type)) continue
				this.typeOptions.options.push({ label: entry.displayName || entry.type, id: entry.type })
				known.add(entry.type)
			}
			const current = optionOf(this.typeOptions, sourceStore.sourceItem?.type)
			if (current) this.typeOptions.value = current
		},

		/**
		 * @spec exclude Modal close plumbing — resets the source form and closes the modal.
		 */
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			Object.assign(this, closedForm())
			this.typeOptions.value = optionOf(this.typeOptions, 'internal')
		},

		/**
		 * The source to save. A database source carries its connection in
		 * authConfig instead of a database URL.
		 *
		 * @spec exclude Modal save plumbing — builds the payload for sourceStore.saveSource.
		 * @return {object} The source
		 */
		sourcePayload() {
			const payload = {
				...this.sourceItem,
				type: this.typeOptions.value.id,
			}
			if (!this.isDatabaseType) return payload

			delete payload.databaseUrl
			payload.authConfig = authConfigOf(
				this.connection,
				this.driverOptions.value.id,
			)
			return payload
		},

		/**
		 * @spec exclude Modal save plumbing — delegates source persistence to sourceStore.saveSource.
		 */
		async editSource() {
			this.loading = true
			sourceStore
				.saveSource(this.sourcePayload())
				.then(this.showSaved)
				.catch(this.showSaveFailure)
				.finally(() => {
					this.loading = false
				})
		},

		/**
		 * Show the answer to a save, and close the modal soon after a success.
		 *
		 * @spec exclude Modal save plumbing — shows the outcome of a save.
		 * @param {{response: Response}} answer The store's answer
		 * @return {void}
		 */
		showSaved({ response }) {
			this.success = response.ok
			this.error = false
			if (response.ok) {
				this.closeModalTimeout = setTimeout(this.closeModal, 2000)
			}
		},

		/**
		 * Show why a save failed.
		 *
		 * @spec exclude Modal save plumbing — shows the outcome of a save.
		 * @param {Error} error The failure
		 * @return {void}
		 */
		showSaveFailure(error) {
			this.success = false
			this.error = error.message || 'An error occurred while saving the source'
		},

		/**
		 * Test the saved database source's connection (never exposes the password).
		 *
		 * @spec openspec/specs/dbal-virtual-registers/spec.md
		 * @return {Promise<void>} Resolves when the test finished.
		 */
		async testConnection() {
			await this.runSourceAction(
				'test-connection',
				t('openregister', 'Connection successful'),
			)
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
				const answer = await this.postSourceAction(action)
				this.showActionAnswer(answer, successMessage)
			} catch (error) {
				this.showActionFailure(error.message)
			} finally {
				this.actionLoading = false
			}
		},

		/**
		 * POST a source action endpoint.
		 *
		 * @spec openspec/specs/dbal-virtual-registers/spec.md
		 * @param {string} action The action path segment
		 * @return {Promise<{ok: boolean, data: object}>} The outcome and response body
		 */
		async postSourceAction(action) {
			const response = await fetch(
				generateUrl(
					`/apps/openregister/api/sources/${this.sourceItem.id}/${action}`,
				),
				{
					method: 'POST',
					headers: { requesttoken: window.OC?.requestToken || '' },
				},
			)
			return { ok: response.ok, data: await response.json() }
		},

		/**
		 * Show the answer to an action.
		 *
		 * @spec openspec/specs/dbal-virtual-registers/spec.md
		 * @param {{ok: boolean, data: object}} answer The outcome and response body
		 * @param {string|null} successMessage The message on success, if any
		 * @return {void}
		 */
		showActionAnswer({ ok, data }, successMessage) {
			if (ok) {
				this.showActionSuccess(data, successMessage)
			} else {
				this.showActionFailure(data.error)
			}
		},

		/**
		 * Show a finished action: the given message, or else what it created.
		 *
		 * @spec openspec/specs/dbal-virtual-registers/spec.md
		 * @param {object} data The response body
		 * @param {string|null} successMessage The message, if any
		 * @return {void}
		 */
		showActionSuccess(data, successMessage) {
			this.actionMessageType = 'success'
			this.actionMessage =
				successMessage
				|| t(
					'openregister',
					'Virtual register created: {created} new and {updated} updated schemas',
					{
						created: (data.created || []).length,
						updated: (data.updated || []).length,
					},
				)
		},

		/**
		 * Show a failed action.
		 *
		 * @spec openspec/specs/dbal-virtual-registers/spec.md
		 * @param {string} [message] What went wrong, if known
		 * @return {void}
		 */
		showActionFailure(message) {
			this.actionMessageType = 'error'
			this.actionMessage = message || t('openregister', 'The action failed')
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
