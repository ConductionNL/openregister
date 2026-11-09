<!--
  The migration packs: every column mapping an import can run, with edit,
  export, delete and import from a JSON file.

  Any signed-in user may read the packs (the API allows it, so an import can
  pick one). Only a Nextcloud administrator is offered import and delete; the
  server refuses both for anyone else.

  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcAppContent>
		<div class="migration-packs">
			<div class="migration-packs__header">
				<h2 ref="heading" tabindex="-1">
					{{ t('openregister', 'Migration packs') }}
				</h2>
				<div v-if="isAdmin" class="migration-packs__toolbar">
					<input
						ref="importInput"
						class="hidden-visually"
						type="file"
						accept="application/json,.json"
						tabindex="-1"
						aria-hidden="true"
						@change="onImportFile" />
					<NcButton
						:disabled="importing"
						@click="$refs.importInput.click()">
						<template #icon>
							<Upload :size="20" />
						</template>
						{{ t('openregister', 'Import JSON') }}
					</NcButton>
				</div>
			</div>
			<p class="migration-packs__intro">
				{{
					t(
						'openregister',
						'A migration pack says which column of an import file becomes which field, and how its value is converted on the way.',
					)
				}}
			</p>
			<NcNoteCard v-if="!isAdmin" type="info">
				{{
					t(
						'openregister',
						'Only administrators can change migration packs.',
					)
				}}
			</NcNoteCard>
			<div aria-live="polite">
				<NcNoteCard v-if="notice" :type="notice.type">
					<p>{{ notice.message }}</p>
					<ul v-if="notice.details && notice.details.length > 0">
						<li v-for="detail in notice.details" :key="detail">
							{{ detail }}
						</li>
					</ul>
				</NcNoteCard>
			</div>

			<NcLoadingIcon v-if="loading" />
			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcEmptyContent
				v-else-if="packs.length === 0"
				:name="t('openregister', 'No migration packs yet')" />
			<CnDataTable
				v-else
				class="migration-packs__table"
				:columns="columns"
				:rows="packs">
				<template #column-name="{ row: pack }">
					<router-link :to="editRoute(pack)">
						{{ pack.name || pack.packSlug }}
					</router-link>
				</template>
				<template #column-packSlug="{ value }">
					<code>{{ value }}</code>
				</template>
				<template #column-sourceFormat="{ value }">
					{{ value }}
				</template>
				<template #column-version="{ value }">
					{{ value }}
				</template>
				<template #column-builtin="{ value }">
					{{ value ? t('openregister', 'Yes') : t('openregister', 'No') }}
				</template>
				<template #column-updatedAt="{ value }">
					{{ formatDate(value) }}
				</template>
				<template #actions-header>
					<span class="hidden-visually">{{ t('openregister', 'Actions') }}</span>
				</template>
				<template #row-actions="{ row: pack }">
					<div class="migration-packs__actions">
						<NcButton
							variant="tertiary"
							:aria-label="
								isAdmin
									? t('openregister', 'Edit {name}', {
											name: pack.name || pack.packSlug,
										})
									: t('openregister', 'View {name}', {
											name: pack.name || pack.packSlug,
										})
							"
							@click="$router.push(editRoute(pack))">
							<template #icon>
								<Pencil v-if="isAdmin" :size="20" />
								<Eye v-else :size="20" />
							</template>
						</NcButton>
						<NcButton
							variant="tertiary"
							:href="exportHref(pack)"
							download
							:aria-label="
								t('openregister', 'Export {name}', {
									name: pack.name || pack.packSlug,
								})
							">
							<template #icon>
								<Download :size="20" />
							</template>
						</NcButton>
						<NcButton
							v-if="isAdmin"
							variant="tertiary"
							:aria-label="
								t('openregister', 'Delete {name}', {
									name: pack.name || pack.packSlug,
								})
							"
							@click="deleting = pack">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
						</NcButton>
					</div>
				</template>
			</CnDataTable>
		</div>

		<DeleteMigrationPackDialog
			v-if="deleting"
			:open="deleting !== null"
			:pack="deleting"
			:busy="busy"
			@close="deleting = null"
			@confirm="confirmDelete" />
	</NcAppContent>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { getCanonicalLocale } from '@nextcloud/l10n'
import { pageShellComponents } from '../pageShell.js'
import Download from 'vue-material-design-icons/Download.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import DeleteMigrationPackDialog from '../../dialogs/migrationPack/DeleteMigrationPackDialog.vue'
import {
	canEditPacks,
	deletePack,
	explainError,
	exportUrl,
	importPack,
	listPacks,
} from '../../services/migrationPacks.js'
import { t } from './l10n.js'

export default {
	name: 'MigrationPacksIndex',
	components: {
		CnDataTable,
		DeleteMigrationPackDialog,
		Download,
		Eye,
		...pageShellComponents,
		Pencil,
		TrashCanOutline,
		Upload,
	},

	data() {
		return {
			packs: [],
			loading: true,
			error: '',
			notice: null,
			deleting: null,
			busy: false,
			importing: false,
			isAdmin: canEditPacks(),
		}
	},

	computed: {
		/**
		 * The list's columns; the row buttons add the last one.
		 *
		 * @return {Array<object>} The CnDataTable columns.
		 */
		columns() {
			return [
				{ key: 'name', label: t('openregister', 'Name'), cellClass: 'cn-cell--wrap' },
				{ key: 'packSlug', label: t('openregister', 'Slug'), cellClass: 'cn-cell--wrap' },
				{ key: 'sourceFormat', label: t('openregister', 'Source format') },
				{ key: 'version', label: t('openregister', 'Version') },
				{ key: 'builtin', label: t('openregister', 'Built-in') },
				{ key: 'updatedAt', label: t('openregister', 'Updated') },
			]
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,
		/**
		 * Load the packs, sorted by name.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const packs = await listPacks()
				this.packs = packs
					.slice()
					.sort((a, b) =>
						String(a.name || a.packSlug).localeCompare(
							String(b.name || b.packSlug),
						),
					)
			} catch {
				this.error = t('openregister', 'Could not load the migration packs.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The editor route of a pack.
		 *
		 * @param {object} pack The pack.
		 * @return {object} The route location.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		editRoute(pack) {
			return { name: 'migration-pack-detail', params: { id: String(pack.id) } }
		},

		/**
		 * The download link of a pack.
		 *
		 * @param {object} pack The pack.
		 * @return {string} The URL.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		exportHref(pack) {
			return exportUrl(pack.id)
		},

		/**
		 * A stored timestamp in the user's Nextcloud locale, not the browser's.
		 *
		 * @param {string|null} value An ISO timestamp.
		 * @return {string} The date and time, or a dash.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		formatDate(value) {
			if (!value) {
				return '-'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime())
				? value
				: date.toLocaleString(getCanonicalLocale())
		},

		/**
		 * Delete the pack the dialog asks about, then focus the page heading.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
		 */
		async confirmDelete() {
			const pack = this.deleting
			this.busy = true
			try {
				await deletePack(pack.id)
				this.packs = this.packs.filter((p) => p.id !== pack.id)
				this.notice = {
					type: 'success',
					message: t('openregister', 'Deleted "{name}".', {
						name: pack.name || pack.packSlug,
					}),
				}
			} catch (error) {
				this.notice = {
					type: 'error',
					...explainError(
						error,
						t('openregister', 'Could not delete the migration pack.'),
					),
				}
			} finally {
				this.busy = false
				this.deleting = null
				// The focused delete button is gone with its row or dialog.
				this.$nextTick(() => {
					this.$refs.heading?.focus()
				})
			}
		},

		/**
		 * Import the chosen JSON file, then reload the list.
		 *
		 * @param {Event} event The file input's change event.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
		 */
		async onImportFile(event) {
			const file = event.target.files?.[0]
			event.target.value = ''
			if (!file) {
				return
			}
			this.importing = true
			try {
				const { pack, replaced } = await importPack(file)
				const name = pack?.name || pack?.packSlug || file.name
				// A pack with the file's id was already stored (and may have been edited
				// on this instance): say it was overwritten, not that a new one arrived.
				this.notice = {
					type: 'success',
					message: replaced
						? t('openregister', 'Replaced "{name}".', { name })
						: t('openregister', 'Imported "{name}".', { name }),
				}
				await this.load()
			} catch (error) {
				this.notice = {
					type: 'error',
					...explainError(
						error,
						t('openregister', 'Could not import the file.'),
						{
							'invalid-request': t(
								'openregister',
								'The file is not a migration pack. Choose a JSON file that holds one pack definition.',
							),
						},
					),
				}
			} finally {
				this.importing = false
			}
		},
	},
}
</script>

<style scoped>
.migration-packs {
	padding: 16px 24px;
}

.migration-packs__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	flex-wrap: wrap;
}

.migration-packs__intro {
	color: var(--color-text-maxcontrast);
	margin-block-end: 16px;
}

.migration-packs__actions {
	display: flex;
	gap: 4px;
}
</style>
