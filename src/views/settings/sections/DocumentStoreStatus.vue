<template>
	<div class="document-store-status">
		<h3>{{ t('openregister', 'Document store') }}</h3>
		<p v-if="store === null" class="document-store-status__unreadable">
			{{ t('openregister', 'The document store could not be read.') }}
		</p>
		<dl v-else>
			<dt>{{ t('openregister', 'Root folder') }}</dt>
			<dd>{{ store.root }}</dd>
			<dt>{{ t('openregister', 'Storage type') }}</dt>
			<dd>{{ backend }}</dd>
			<dt>{{ t('openregister', 'Storage') }}</dt>
			<dd>{{ store.storageId }}</dd>
			<dt>{{ t('openregister', 'Mount point') }}</dt>
			<dd>{{ store.mountPoint }}</dd>
			<dt>{{ t('openregister', 'Separate store') }}</dt>
			<dd>{{ separate }}</dd>
			<dt>{{ t('openregister', 'Free space') }}</dt>
			<dd>{{ freeSpace }}</dd>
		</dl>
	</div>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'

/** Byte units, each 1024 times the one before. */
const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']

/**
 * Where OpenRegister keeps the bytes of its managed files, read-only.
 *
 * Shows the `documentStore` that `GET /api/settings/files` returns: the root
 * folder, the backend, the storage and mount point, whether the store is
 * separate from the instance's primary storage, and its free space.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
export default {
	name: 'DocumentStoreStatus',

	computed: {
		...mapStores(useSettingsStore),

		/**
		 * The document store as the file settings returned it, or null when
		 * the server could not read it.
		 *
		 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
		 * @return {object|null} The store
		 */
		store() {
			const store = this.settingsStore?.fileOptions?.documentStore
			if (store === undefined || store === null || typeof store !== 'object') {
				return null
			}
			return store
		},

		/**
		 * The backend with the storage class behind it, as occ prints it.
		 *
		 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
		 * @return {string} For example `external (local)`
		 */
		backend() {
			if (!this.store.backendName) {
				return this.store.backend
			}
			return `${this.store.backend} (${this.store.backendName})`
		},

		/**
		 * Whether the store is separate from the primary storage, in words.
		 *
		 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
		 * @return {string} The answer
		 */
		separate() {
			if (this.store.separate === true) {
				return this.t('openregister', 'Yes, apart from the primary storage')
			}
			return this.t('openregister', 'No, on the primary storage')
		},

		/**
		 * The free space, or that the storage does not report it.
		 *
		 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
		 * @return {string} The free space
		 */
		freeSpace() {
			const bytes = this.store.freeBytes
			if (typeof bytes !== 'number' || bytes < 0) {
				return this.t('openregister', 'Not reported')
			}
			let value = bytes
			let unit = 0
			while (value >= 1024 && unit < UNITS.length - 1) {
				value = value / 1024
				unit++
			}
			const shown = unit === 0 ? String(value) : value.toFixed(1)
			return `${shown} ${UNITS[unit]}`
		},
	},
}
</script>

<style scoped lang="scss">
.document-store-status {
	margin-bottom: 24px;

	dl {
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: 4px 16px;
		margin: 8px 0 0;
	}

	dt {
		font-weight: bold;
	}

	dd {
		margin: 0;
		overflow-wrap: anywhere;
	}
}

.document-store-status__unreadable {
	color: var(--color-text-maxcontrast);
}
</style>
