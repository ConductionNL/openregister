<template>
	<SettingsSection
		id="export-keys"
		:name="t('openregister', 'Export keys')"
		:description="
			t(
				'openregister',
				'An export key encrypts an instance set, so the set opens only where that key is present. Keys belong to your active organisation.',
			)
		">
		<NcNoteCard v-if="recovery" type="warning">
			<p>
				{{
					t(
						'openregister',
						'Store this recovery string somewhere else now. It is shown only once, and without it a set encrypted with this key cannot be opened on another instance.',
					)
				}}
			</p>
			<p class="recovery">
				<span class="recovery-label">{{ t('openregister', 'Recovery string') }}</span>
				<code data-test="recovery">{{ recovery.recovery }}</code>
			</p>
			<NcButton @click="dismissRecovery">
				{{ t('openregister', 'Close') }}
			</NcButton>
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="keys.length === 0" class="field-hint">
			{{ t('openregister', 'No export keys yet') }}
		</p>
		<table v-else class="export-keys">
			<thead>
				<tr>
					<th scope="col">
						{{ t('openregister', 'Fingerprint') }}
					</th>
					<th scope="col">
						{{ t('openregister', 'Created') }}
					</th>
					<th scope="col">
						{{ t('openregister', 'Actions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="key in keys" :key="key.id">
					<td><code>{{ key.fingerprint || t('openregister', 'Unknown') }}</code></td>
					<td>{{ formatDate(key.createdAt) }}</td>
					<td class="actions">
						<NcButton :disabled="busy" @click="rotate(key)">
							{{ t('openregister', 'Rotate') }}
						</NcButton>
						<NcButton variant="error" :disabled="busy" @click="remove(key)">
							{{ t('openregister', 'Delete') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NcButton
			variant="primary"
			:disabled="loading || busy"
			@click="create">
			<template #icon>
				<NcLoadingIcon v-if="busy" :size="20" />
				<KeyPlus v-else :size="20" />
			</template>
			{{ t('openregister', 'Create export key') }}
		</NcButton>
	</SettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import KeyPlus from 'vue-material-design-icons/KeyPlus.vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'

/**
 * The export keys of the active organisation: list, create, rotate, delete.
 *
 * A new key's recovery string is held in component state only, shown once
 * and dropped on close; the list the server answers never carries it, so a
 * reload shows the key by fingerprint and creation date alone.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */
export default {
	name: 'ExportKeysConfiguration',
	components: { KeyPlus, NcButton, NcLoadingIcon, NcNoteCard, SettingsSection },

	data() {
		return {
			keys: [],
			recovery: null,
			loading: false,
			busy: false,
		}
	},

	/**
	 * Load the keys.
	 *
	 * @spec exclude UI plumbing: initial data fetch for display only
	 * @return {Promise<void>}
	 */
	async created() {
		await this.load()
	},

	methods: {
		/**
		 * The base URL of the export-key routes.
		 *
		 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
		 * @param {string} path The path after /api/export-keys
		 * @return {string} The URL
		 */
		url(path = '') {
			return generateUrl('/apps/openregister/api/export-keys' + path)
		},

		/**
		 * Read the organisation's keys; never a recovery string.
		 *
		 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(this.url())
				this.keys = (response.data?.results || []).map((key) => ({
					id: key.id,
					fingerprint: key.fingerprint || null,
					createdAt: key.createdAt || null,
				}))
			} catch {
				showError(this.t('openregister', 'Could not load the export keys'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a key and show its recovery string once.
		 *
		 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
		 * @return {Promise<void>}
		 */
		async create() {
			await this.act(() => axios.post(this.url()), true)
		},

		/**
		 * Rotate a key: a new key with its recovery string, the old one kept.
		 *
		 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
		 * @param {{id: string}} key The key being rotated out
		 * @return {Promise<void>}
		 */
		async rotate(key) {
			await this.act(() => axios.post(this.url('/' + encodeURIComponent(key.id) + '/rotate')), true)
		},

		/**
		 * Delete a key after the administrator confirms what it costs.
		 *
		 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
		 * @param {{id: string}} key The key to delete
		 * @return {Promise<void>}
		 */
		async remove(key) {
			const question = this.t('openregister', 'Delete this export key? A set encrypted with it can no longer be opened here.')
			if (window.confirm(question) !== true) {
				return
			}
			await this.act(() => axios.delete(this.url('/' + encodeURIComponent(key.id))), false)
		},

		/**
		 * Run one change, keep a returned recovery string, and reload the list.
		 *
		 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
		 * @param {function(): Promise<object>} request Sends the request
		 * @param {boolean} showsRecovery Whether the answer carries a recovery string
		 * @return {Promise<void>}
		 */
		async act(request, showsRecovery) {
			this.busy = true
			try {
				const response = await request()
				if (showsRecovery === true && response.data?.recovery) {
					this.recovery = {
						id: response.data.id,
						fingerprint: response.data.fingerprint,
						recovery: response.data.recovery,
					}
				}
			} catch {
				showError(this.t('openregister', 'The export key action failed'))
			} finally {
				this.busy = false
			}
			await this.load()
		},

		/**
		 * Forget the recovery string; it is never shown again.
		 *
		 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
		 * @return {void}
		 */
		dismissRecovery() {
			this.recovery = null
		},

		/**
		 * A creation date for the table, or Unknown.
		 *
		 * @spec exclude UI plumbing: display formatting only
		 * @param {string|null} value An ATOM timestamp
		 * @return {string} The localised date and time
		 */
		formatDate(value) {
			const date = value ? new Date(value) : null
			if (date === null || Number.isNaN(date.getTime())) {
				return this.t('openregister', 'Unknown')
			}
			return date.toLocaleString()
		},
	},
}
</script>

<style scoped lang="scss">
.field-hint {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 16px;
}

.export-keys {
	width: 100%;
	margin-bottom: 16px;
	border-collapse: collapse;

	th,
	td {
		padding: 8px;
		text-align: start;
		border-bottom: 1px solid var(--color-border);
	}

	.actions {
		display: flex;
		gap: 8px;
	}
}

.recovery {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 8px 0;

	code {
		word-break: break-all;
		user-select: all;
	}
}

.recovery-label {
	font-weight: bold;
}
</style>
