<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	configurationStore,
	dashboardStore,
	navigationStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<div
		class="registerSchemaCard"
		:class="{
			'registerSchemaCard--managed': isManagedByExternalConfig,
			'registerSchemaCard--local': isManagedByLocalConfig,
			'registerSchemaCard--in-use': type === 'schema' && hasObjects,
		}">
		<!-- Card Header -->
		<div class="cardHeader">
			<div class="cardHeaderTitleRow">
				<div :title="item.description || item.title" class="cardTitleClip">
					<h2 class="cardTitleTextWrapper">
						<DatabaseOutline
							v-if="type === 'register'"
							:size="20"
							class="cardTitleIcon" />
						<FileTreeOutline v-else :size="20" class="cardTitleIcon" />
						<span class="cardTitleText">{{ item.title }}</span>
					</h2>
				</div>
				<NcActions :primary="true" :menuName="t('openregister', 'Actions')">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton
						:title="
							isManagedByExternalConfig
								? 'Cannot edit: This '
									+ type
									+ ' is managed by external configuration '
									+ (managingConfiguration?.title || '')
								: ''
						"
						closeAfterClick
						:disabled="isManagedByExternalConfig"
						@click="openEdit">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<!-- Register-only actions -->
					<template v-if="type === 'register'">
						<NcActionButton
							closeAfterClick
							@click="openRegisterModal('publishRegister')">
							<template #icon>
								<CloudUploadOutline :size="20" />
							</template>
							Publish OAS
						</NcActionButton>
						<NcActionButton
							closeAfterClick
							@click="openRegisterModal('importRegister')">
							<template #icon>
								<Upload :size="20" />
							</template>
							Import
						</NcActionButton>
						<NcActionButton closeAfterClick @click="viewOasDoc">
							<template #icon>
								<ApiIcon :size="20" />
							</template>
							View API Documentation
						</NcActionButton>
						<NcActionButton closeAfterClick @click="downloadOas">
							<template #icon>
								<Download :size="20" />
							</template>
							Download API Specification
						</NcActionButton>
					</template>
					<NcActionButton
						:title="deleteDisabledTooltip"
						closeAfterClick
						:disabled="deleteDisabled"
						@click="openDelete">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						Delete
					</NcActionButton>
					<!-- Register-only: View Details -->
					<NcActionButton
						v-if="type === 'register'"
						closeAfterClick
						@click="viewRegisterDetails">
						<template #icon>
							<InformationOutline :size="20" />
						</template>
						View Details
					</NcActionButton>
				</NcActions>
			</div>
			<div class="cardHeaderBadgeRow">
				<span
					v-if="type === 'schema' && item.extend"
					class="statusPill statusPill--alert">
					{{ t('openregister', 'Extended') }}
				</span>
				<span
					v-if="type === 'schema' && hasObjects"
					class="statusPill statusPill--success">
					{{ t('openregister', 'In use') }}
				</span>
				<span
					v-if="isManagedByExternalConfig"
					class="managedBadge managedBadge--external">
					<CogOutline :size="16" />
					{{ t('openregister', 'Managed') }}
				</span>
				<span
					v-else-if="isManagedByLocalConfig"
					class="managedBadge managedBadge--local">
					<CogOutline :size="16" />
					{{ t('openregister', 'Local') }}
				</span>
			</div>
		</div>

		<!-- Description -->
		<!-- Only a disclosure control when there is actually a description to expand;
			with no description the click is a no-op, so it must not be a tab stop. -->
		<div
			class="registerDescription-container"
			:role="item.description ? 'button' : null"
			:tabindex="item.description ? 0 : null"
			:aria-expanded="item.description ? String(descriptionExpanded) : null"
			@click="
				item.description
					? (descriptionExpanded = !descriptionExpanded)
					: null
			"
			@keydown.enter="
				item.description
					? (descriptionExpanded = !descriptionExpanded)
					: null
			"
			@keydown.space.prevent="
				item.description
					? (descriptionExpanded = !descriptionExpanded)
					: null
			">
			<div
				class="registerDescription"
				:class="{
					'registerDescription--expanded': descriptionExpanded,
					'registerDescription--empty': !item.description,
				}">
				{{ item.description || t('openregister', 'No description found') }}
			</div>
		</div>

		<!-- Register: Schemas Table -->
		<template v-if="type === 'register'">
			<div class="registerSchemasScroll">
				<table class="statisticsTable registerSchemas">
					<thead>
						<tr>
							<th scope="col">
								{{ t('openregister', 'Schema Name') }}
							</th>
							<th scope="col">
								{{ t('openregister', 'Objects') }}
							</th>
							<th scope="col">
								{{ t('openregister', 'Configuration') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="schema in displayedItems" :key="schema.id">
							<td class="schemaNameCell">
								<Table
									v-if="hasMagicMapping(schema)"
									title="Magic Table"
									:size="18"
									class="schemaIcon schemaIcon--magic" />
								<DatabaseOutline
									v-else
									title="Blob Storage"
									:size="18"
									class="schemaIcon schemaIcon--blob" />
								{{ schema.title }}
							</td>
							<td>
								<span
									v-if="schema.stats?.objects?.deleted > 0"
									:title="
										t(
											'openregister',
											'{active} active, {deleted} deleted',
											{
												active:
													schema.stats.objects.total
													- schema.stats.objects.deleted,
												deleted:
													schema.stats.objects.deleted,
											},
										)
									">
									{{
										schema.stats.objects.total
											- schema.stats.objects.deleted || 0
									}}
									<span class="deletedCount"
										>({{
											schema.stats?.objects?.deleted || 0
										}}
										deleted)</span
									>
								</span>
								<span v-else>
									{{ schema.stats?.objects?.total || 0 }}
								</span>
							</td>
							<td class="tableColumnActions">
								<NcActions :primary="false">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton
										closeAfterClick
										@click="
											setSchemaConfiguration(schema, 'magic')
										">
										<template #icon>
											<Table :size="20" />
										</template>
										{{
											hasMagicMapping(schema)
												? '&#10003; '
												: ''
										}}Use Magic Table
									</NcActionButton>
									<NcActionButton
										closeAfterClick
										@click="
											setSchemaConfiguration(schema, 'blob')
										">
										<template #icon>
											<DatabaseOutline :size="20" />
										</template>
										{{
											!hasMagicMapping(schema)
												? '&#10003; '
												: ''
										}}Use Blob Storage
									</NcActionButton>
									<NcActionButton
										:title="
											!hasMagicMapping(schema)
												? t(
														'openregister',
														'This schema must use magic table configuration to sync',
													)
												: ''
										"
										:disabled="!hasMagicMapping(schema)"
										closeAfterClick
										@click="syncMagicTable(schema)">
										<template #icon>
											<Sync :size="20" />
										</template>
										{{ t('openregister', 'Sync Table') }}
									</NcActionButton>
									<NcActionButton
										closeAfterClick
										@click="validateSchemaObjects(schema)">
										<template #icon>
											<CheckCircle :size="20" />
										</template>
										{{ t('openregister', 'Validate') }}
									</NcActionButton>
									<NcActionButton
										closeAfterClick
										@click="
											openSchemaModal(schema, 'exportRegister')
										">
										<template #icon>
											<Export :size="20" />
										</template>
										{{ t('openregister', 'Export') }}
									</NcActionButton>
									<NcActionButton
										closeAfterClick
										@click="
											openSchemaModal(schema, 'importRegister')
										">
										<template #icon>
											<Upload :size="20" />
										</template>
										{{ t('openregister', 'Import') }}
									</NcActionButton>
									<NcActionButton
										:title="
											(schema.stats?.objects?.total || 0) === 0
												? t(
														'openregister',
														'No objects to delete',
													)
												: t(
														'openregister',
														'Soft delete all objects for this schema ({active} active, {deleted} already deleted)',
														{
															active: getSchemaObjectCount(
																schema,
															),
															deleted:
																schema.stats?.objects
																	?.deleted || 0,
														},
													)
										"
										:disabled="
											getSchemaObjectCount(schema) === 0
										"
										closeAfterClick
										@click="deleteSchemaObjects(schema, false)">
										<template #icon>
											<DeleteOutline :size="20" />
										</template>
										{{ t('openregister', 'Delete Objects') }}
									</NcActionButton>
									<NcActionButton
										v-if="deletedCount(schema) > 0"
										:title="
											t(
												'openregister',
												'Permanently delete all {count} soft-deleted objects. This cannot be undone!',
												{ count: deletedCount(schema) },
											)
										"
										closeAfterClick
										@click="deleteSchemaObjects(schema, true)">
										<template #icon>
											<DeleteOutline :size="20" />
										</template>
										{{
											t(
												'openregister',
												'Permanently Delete ({count})',
												{ count: deletedCount(schema) },
											)
										}}
									</NcActionButton>
									<NcActionButton
										:title="
											getSchemaObjectCount(schema) > 0
												? t(
														'openregister',
														'Cannot remove schema with existing objects ({count} objects)',
														{
															count: getSchemaObjectCount(
																schema,
															),
														},
													)
												: ''
										"
										:disabled="getSchemaObjectCount(schema) > 0"
										closeAfterClick
										@click="removeSchemaFromRegister(schema)">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										{{ t('openregister', 'Remove') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
						<tr v-if="!item.schemas || item.schemas.length === 0">
							<td colspan="3" class="emptyText">
								{{ t('openregister', 'No schemas found') }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<!-- Schema: Properties Table -->
		<template v-else>
			<div class="registerSchemasScroll">
				<table class="statisticsTable registerSchemas">
					<thead>
						<tr>
							<th scope="col">
								{{ t('openregister', 'Name') }}
							</th>
							<th scope="col">
								{{ t('openregister', 'Type') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(property, key) in displayedItems" :key="key">
							<td>
								{{ key }}
								<span
									v-if="isPropertyRequired(key)"
									class="required-indicator"
									>({{ t('openregister', 'required') }})</span
								>
							</td>
							<td>{{ property.type }}</td>
						</tr>
						<tr v-if="!Object.keys(item.properties || {}).length">
							<td colspan="2" class="emptyText">
								{{ t('openregister', 'No properties found') }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<!-- View More Button -->
		<div v-if="remainingItemsCount > 0" class="viewMoreContainer">
			<NcButton variant="secondary" @click="itemsExpanded = !itemsExpanded">
				<template #icon>
					<ChevronDown v-if="!itemsExpanded" :size="20" />
					<ChevronUp v-else :size="20" />
				</template>
				{{
					itemsExpanded
						? t('openregister', 'Show less')
						: t('openregister', 'View {count} more', {
								count: remainingItemsCount,
							})
				}}
			</NcButton>
		</div>

		<!-- Edit Register Dialog (register type only) -->
		<EditRegisterFormDialog
			v-if="type === 'register' && showEditRegisterDialog"
			:item="item"
			schemasLabel="Schemas"
			:afterSave="emitRefresh"
			@close="showEditRegisterDialog = false" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcActionButton,
	NcActions,
	NcButton,
} from '@nextcloud/vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Export from 'vue-material-design-icons/Export.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import Table from 'vue-material-design-icons/Table.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import EditRegisterFormDialog from '../../modals/register/EditRegisterFormDialog.vue'
import { downloadRegisterOas, viewRegisterOas } from '../../services/registerOas.js'

const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}

const COLUMN_CHANGES = [
	['added', 'added'],
	['removed', 'removed'],
	['deRequired', 'de-required'],
	['unchanged', 'unchanged'],
]

/**
 * The absolute URL of an OpenRegister API path.
 *
 * @param {string} path The path below /api/
 * @return {string} The URL
 */
function apiUrl(path) {
	return `${window.location.origin}/index.php/apps/openregister/api/${path}`
}

/**
 * The message of a failed request: the server's, else the error's own.
 *
 * @param {Error} error The error
 * @return {string} The message
 */
function errorText(error) {
	return error.response?.data?.message || error.message || 'Unknown error'
}

/**
 * The placeholders of a "failed for {schema}: {error}" message.
 *
 * @param {object} schema The schema the request was for
 * @param {Error} error The error
 * @return {{schema: string, error: string}} The placeholders
 */
function schemaFailure(schema, error) {
	return { schema: schema.title, error: errorText(error) }
}

/**
 * The column changes of a magic table sync, as short phrases.
 *
 * @param {object} columns The column statistics
 * @return {Array<string>} The phrases
 */
function columnChanges(columns) {
	return COLUMN_CHANGES.filter(([key]) => columns[key].count > 0).map(
		([key, label]) => `${columns[key].count} ${label}`,
	)
}

/**
 * The detail phrases of a magic table sync.
 *
 * @param {object} stats The sync statistics
 * @return {Array<string>} The phrases
 */
function magicTableDetails(stats) {
	const details = []
	if (stats.metadata && stats.properties) {
		details.push(
			`${stats.metadata.count} metadata columns, ${stats.properties.count} property columns`,
		)
	}
	const changes = stats.columns ? columnChanges(stats.columns) : []
	if (changes.length > 0) {
		details.push(`Columns: ${changes.join(', ')}`)
	}
	return details
}

/**
 * The toast for a magic table sync response.
 *
 * @param {object} data The response body
 * @param {object} schema The synced schema
 * @return {string} The message
 */
function magicTableSyncMessage(data, schema) {
	if (!data || !data.success) {
		return t('openregister', 'Magic table sync completed for {schema}', {
			schema: schema.title,
		})
	}
	const details = magicTableDetails(data.statistics)
	const message =
		details.length > 0
			? `Magic table synced: ${details.join(' • ')}`
			: `Magic table synced successfully for ${schema.title}`
	return t('openregister', message)
}

/**
 * The fields of a stored schema that a storage change writes back.
 *
 * @param {object} current The stored schema
 * @return {object} The schema to save
 */
function schemaUpdate(current) {
	return {
		title: current.title,
		type: current.type || 'object',
		properties: current.properties || {},
		required: current.required || [],
		description: current.description || '',
	}
}

/**
 * Give every property a table column, or take them all away.
 *
 * @param {object} schema The schema to save; its properties change in place
 * @param {string} configurationType 'magic' for table columns, else blob storage
 */
function convertStorage(schema, configurationType) {
	if (configurationType !== 'magic') {
		removeTableColumns(schema.properties)
		return
	}
	if (!schema.properties || typeof schema.properties !== 'object') {
		schema.properties = {}
	}
	addTableColumns(schema.properties)
}

/**
 * Give each property without a table column the default one.
 *
 * @param {object} properties The schema properties
 */
function addTableColumns(properties) {
	Object.keys(properties).forEach((key) => {
		if (!properties[key].table) {
			properties[key].table = {
				enabled: true,
				indexed: false,
				searchable: false,
			}
		}
	})
}

/**
 * Remove the table column of each property.
 *
 * @param {object} properties The schema properties
 */
function removeTableColumns(properties) {
	if (!properties || typeof properties !== 'object') {
		return
	}
	Object.keys(properties).forEach((key) => {
		if (properties[key].table) {
			delete properties[key].table
		}
	})
}

/**
 * The toast announcing a storage change.
 *
 * @param {object} schema The schema
 * @param {string} configurationType 'magic' for table columns, else blob storage
 * @return {string} The message
 */
function conversionNotice(schema, configurationType) {
	return configurationType === 'magic'
		? t('openregister', 'Converting {schema} to magic table...', {
				schema: schema.title,
			})
		: t('openregister', 'Converting {schema} to blob storage...', {
				schema: schema.title,
			})
}

/**
 * The confirmation text for removing objects for good.
 *
 * @param {object} counts Active, deleted and total objects, and the schema title
 * @return {string} The text
 */
function hardDeletionPrompt(counts) {
	if (counts.active > 0 && counts.deleted > 0) {
		return t(
			'openregister',
			'⚠️ PERMANENT DELETION WARNING ⚠️\n\nYou are about to PERMANENTLY delete ALL objects for schema "{schema}":\n\n• Active objects: {active}\n• Soft-deleted objects: {deleted}\n• Total: {total}\n\nThese objects will be completely removed from the database and CANNOT be recovered.\n\nAre you absolutely sure?',
			{
				active: counts.active,
				deleted: counts.deleted,
				total: counts.total,
				schema: counts.schema,
			},
		)
	}
	return t(
		'openregister',
		'⚠️ PERMANENT DELETION WARNING ⚠️\n\nYou are about to PERMANENTLY delete {count} soft-deleted objects for schema "{schema}".\n\nThese objects will be completely removed from the database and CANNOT be recovered.\n\nAre you absolutely sure?',
		{ count: counts.deleted, schema: counts.schema },
	)
}

/**
 * The confirmation text for a soft delete, or null after explaining that
 * there are no active objects.
 *
 * @param {object} counts Active objects and the schema title
 * @return {string|null} The text
 */
function softDeletionPrompt(counts) {
	if (counts.active === 0) {
		showError(
			t(
				'openregister',
				'No active objects to soft-delete for schema {schema}. Use "Permanently Delete" to remove soft-deleted objects.',
				{ schema: counts.schema },
			),
		)
		return null
	}
	return t(
		'openregister',
		'Are you sure you want to soft-delete {count} active objects for schema "{schema}"?\n\nThey will be marked as deleted but can be permanently removed later.',
		{ count: counts.active, schema: counts.schema },
	)
}

/**
 * Say a schema's objects are about to be deleted, permanently or softly.
 *
 * @param {object} schema The schema
 * @param {boolean} hardDelete Whether the objects are deleted permanently
 * @return {void}
 */
function announceDeletionStart(schema, hardDelete) {
	const action = hardDelete ? 'permanently deleting' : 'soft-deleting'
	showSuccess(
		t('openregister', 'Starting {action} for {schema}...', {
			action,
			schema: schema.title,
		}),
	)
}

/**
 * Why deleting a schema's objects failed.
 *
 * @param {object} schema The schema
 * @param {Error} error What went wrong
 * @return {string} The message
 */
function deletionFailureText(schema, error) {
	return t(
		'openregister',
		'Failed to delete objects for {schema}: {error}',
		schemaFailure(schema, error),
	)
}

/**
 * Ask the server to delete a schema's objects.
 *
 * @param {string} url The bulk delete URL
 * @param {boolean} hardDelete Whether the objects are deleted permanently
 * @return {Promise<object>} The axios response
 */
function postDeletion(url, hardDelete) {
	return axios.post(url, { hardDelete }, { headers: JSON_HEADERS })
}

/**
 * Refresh the register list and the dashboard's registers together.
 *
 * @return {Promise<void>}
 */
async function refreshRegisterViews() {
	await Promise.all([
		registerStore.refreshRegisterList(),
		dashboardStore.fetchRegisters(),
	])
}

/**
 * The toast for a bulk delete response.
 *
 * @param {object} data The response body
 * @param {object} schema The schema
 * @return {string} The message
 */
function deletionResult(data, schema) {
	if (data && data.success) {
		return t(
			'openregister',
			'Successfully deleted {count} objects for {schema}',
			{ count: data.deleted_count || 0, schema: schema.title },
		)
	}
	return t('openregister', 'Objects deletion completed for {schema}', {
		schema: schema.title,
	})
}

export default {
	name: 'RegisterSchemaCard',
	components: {
		EditRegisterFormDialog,
		NcActions,
		NcActionButton,
		NcButton,
		DatabaseOutline,
		FileTreeOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Upload,
		Export,
		ApiIcon,
		Download,
		InformationOutline,
		ChevronDown,
		ChevronUp,
		CogOutline,
		CloudUploadOutline,
		Sync,
		// eslint-disable-next-line vue/no-reserved-component-names
		Table,
		CheckCircle,
		DeleteOutline,
	},

	props: {
		item: {
			type: Object,
			required: true,
		},

		type: {
			type: String,
			required: true,
			validator: (v) => ['register', 'schema'].includes(v),
		},
	},

	emits: ['refresh'],
	/**
	 * @spec exclude local data state; showEditRegisterDialog toggles the edit dialog, UI plumbing
	 */
	data() {
		return {
			itemsExpanded: false,
			descriptionExpanded: false,
			showEditRegisterDialog: false,
		}
	},

	computed: {
		/**
		 * @spec exclude computed lookup of managing configuration from store, UI plumbing
		 */
		managingConfiguration() {
			if (!this.item || !this.item.id) return null
			if (this.type === 'register') {
				return (
					configurationStore.configurationList.find(
						(config) =>
							config.registers
							&& config.registers.includes(this.item.id),
					) || null
				)
			}
			// Schema: config.schemas is an array of objects with .id
			return (
				configurationStore.configurationList.find(
					(config) =>
						config.schemas
						&& config.schemas.some((s) => s.id === this.item.id),
				) || null
			)
		},

		/**
		 * @spec exclude computed external-management display flag, UI plumbing
		 */
		isManagedByExternalConfig() {
			const config = this.managingConfiguration
			if (!config) return false
			return (
				(config.sourceType
					&& ['github', 'gitlab', 'url'].includes(config.sourceType))
				|| config.isLocal === false
			)
		},

		/**
		 * @spec exclude computed local-management display flag, UI plumbing
		 */
		isManagedByLocalConfig() {
			const config = this.managingConfiguration
			if (!config) return false
			return (
				config.sourceType === 'local'
				|| config.sourceType === 'manual'
				|| config.isLocal === true
			)
		},

		hasObjects() {
			return this.item.stats?.objects?.total > 0
		},

		/**
		 * @spec exclude computed delete-disabled display flag from object stats, UI plumbing
		 */
		deleteDisabled() {
			if (this.type === 'register') {
				return this.item.stats?.total > 0
			}
			return this.item.stats?.objects?.total > 0
		},

		/**
		 * @spec exclude computed delete-disabled tooltip display helper, UI plumbing
		 */
		deleteDisabledTooltip() {
			if (this.deleteDisabled) {
				return 'Cannot delete: objects are still attached'
			}
			return ''
		},

		/**
		 * @spec exclude computed paginated schema/property list for display, UI plumbing
		 */
		displayedItems() {
			if (this.type === 'register') {
				if (!this.item.schemas || this.item.schemas.length === 0) return []
				if (this.itemsExpanded) return this.item.schemas
				return this.item.schemas.slice(0, 5)
			}
			// Schema: show sorted properties
			const sorted = this.sortedProperties
			const entries = Object.entries(sorted)
			if (this.itemsExpanded) return sorted
			return Object.fromEntries(entries.slice(0, 5))
		},

		/**
		 * @spec exclude computed remaining-items count for "view more" display, UI plumbing
		 */
		remainingItemsCount() {
			if (this.type === 'register') {
				const total = this.item.schemas?.length || 0
				return Math.max(0, total - 5)
			}
			const total = Object.keys(this.item.properties || {}).length
			return Math.max(0, total - 5)
		},

		/**
		 * @spec exclude computed order-sorted property list for display, UI plumbing
		 */
		sortedProperties() {
			const properties = this.item.properties || {}
			return Object.entries(properties)
				.sort(([, propA], [, propB]) => {
					const orderA = propA.order || 0
					const orderB = propB.order || 0
					if (orderA > 0 && orderB > 0) return orderA - orderB
					if (orderA > 0) return -1
					if (orderB > 0) return 1
					const createdA = propA.created || ''
					const createdB = propB.created || ''
					return createdA.localeCompare(createdB)
				})
				.reduce((acc, [key, value]) => {
					acc[key] = value
					return acc
				}, {})
		},
	},

	methods: {
		// Common methods
		/**
		 * Open a register modal for this card's register.
		 *
		 * @param {string} modal The modal to open
		 * @spec exclude store/modal-dispatch UI handler
		 */
		openRegisterModal(modal) {
			registerStore.setRegisterItem(this.item)
			navigationStore.setModal(modal)
		},

		/**
		 * Open a register modal for one schema of this card's register.
		 *
		 * @param {object} schema The schema
		 * @param {string} modal The modal to open
		 * @spec exclude store/modal-dispatch UI handler
		 */
		openSchemaModal(schema, modal) {
			registerStore.setRegisterItem(this.item)
			schemaStore.setSchemaItem(schema)
			navigationStore.setModal(modal)
		},

		/**
		 * The number of soft-deleted objects of a schema, 0 when unknown.
		 *
		 * @param {object} schema The schema
		 * @return {number} The count
		 * @spec exclude UI plumbing: reads the schema statistics
		 */
		deletedCount(schema) {
			return schema.stats?.objects?.deleted || 0
		},

		/**
		 * @spec exclude store/modal-dispatch UI handler opening edit, register/schema contract owned by register/schema capability
		 */
		openEdit() {
			if (this.type === 'register') {
				this.showEditRegisterDialog = true
			} else {
				schemaStore.setSchemaItem(this.item)
				navigationStore.setModal('editSchema')
			}
		},

		/**
		 * @spec exclude UI event relay after the register dialog saved
		 */
		emitRefresh() {
			this.$emit('refresh')
		},

		/**
		 * @spec exclude store/dialog-dispatch UI handler opening delete, contract owned by register/schema capability
		 */
		openDelete() {
			if (this.type === 'register') {
				registerStore.setRegisterItem(this.item)
				navigationStore.setDialog('deleteRegister')
			} else {
				schemaStore.setSchemaItem(this.item)
				navigationStore.setDialog('deleteSchema')
			}
		},

		// Schema-only methods
		isPropertyRequired(key) {
			return this.item.required && this.item.required.includes(key)
		},

		// Register-only methods
		/**
		 * @spec exclude router-navigation UI handler to register detail, UI plumbing
		 */
		viewRegisterDetails() {
			registerStore.setRegisterItem({ id: this.item.id })
			this.$router.push(`/registers/${this.item.id}`)
		},

		/**
		 * @spec exclude API fetch + browser file-download UI plumbing; OAS contract owned by openapi-generation capability
		 */
		async downloadOas() {
			try {
				await downloadRegisterOas(this.item)
			} catch (error) {
				showError(t('openregister', 'Failed to download API specification'))
				console.error('Error downloading OAS:', error)
			}
		},

		/**
		 * @spec exclude window.open to external Redoc viewer, UI plumbing
		 */
		viewOasDoc() {
			viewRegisterOas(this.item.id)
		},

		/**
		 * @param {object} schema
		 * @spec exclude computed read of schema property table mapping for icon display, UI plumbing
		 */
		hasMagicMapping(schema) {
			if (!schema || !schema.properties) {
				return false
			}
			return Object.values(schema.properties).some(
				(property) =>
					property && property.table && typeof property.table === 'object',
			)
		},

		/**
		 * @param {object} schema
		 * @spec exclude computed active-object count from schema stats for display, UI plumbing
		 */
		getSchemaObjectCount(schema) {
			if (!schema || !schema.stats || !schema.stats.objects) {
				return 0
			}
			const total = schema.stats.objects.total || 0
			const deleted = schema.stats.objects.deleted || 0
			return total - deleted
		},

		/**
		 * @param {object} schema
		 * @spec exclude API passthrough triggering magic-table sync with toast; sync contract owned by magic-table capability
		 */
		async syncMagicTable(schema) {
			const url = apiUrl(`tables/sync/${this.item.id}/${schema.id}`)
			try {
				showSuccess(
					t('openregister', 'Syncing magic table for {schema}...', {
						schema: schema.title,
					}),
				)
				const response = await axios.post(url, {}, { headers: JSON_HEADERS })
				showSuccess(magicTableSyncMessage(response.data, schema))
			} catch (error) {
				console.error('Error syncing magic table:', error)
				showError(
					t(
						'openregister',
						'Failed to sync magic table for {schema}: {error}',
						schemaFailure(schema, error),
					),
				)
			}
		},

		/**
		 * @param {object} schema
		 * @param {string} configurationType
		 * @spec exclude API passthrough toggling schema magic/blob config with toast; schema contract owned by schema capability
		 */
		async setSchemaConfiguration(schema, configurationType) {
			const url = apiUrl(`schemas/${schema.id}`)
			try {
				const fetchResponse = await axios.get(url, {
					headers: { Accept: 'application/json' },
				})
				const updatedSchema = schemaUpdate(fetchResponse.data)
				convertStorage(updatedSchema, configurationType)
				showSuccess(conversionNotice(schema, configurationType))
				await axios.put(url, updatedSchema, { headers: JSON_HEADERS })
				showSuccess(
					t(
						'openregister',
						'Schema configuration updated successfully for {schema}',
						{ schema: schema.title },
					),
				)
				this.$emit('refresh')
			} catch (error) {
				console.error('Error updating schema configuration:', error)
				showError(
					t(
						'openregister',
						'Failed to update schema configuration for {schema}: {error}',
						schemaFailure(schema, error),
					),
				)
			}
		},

		/**
		 * @param {object} schema
		 * @spec exclude API passthrough triggering object validation with toast; validation contract owned by oas-validation capability
		 */
		async validateSchemaObjects(schema) {
			const url = apiUrl('objects/validate')
			try {
				showSuccess(
					t('openregister', 'Starting validation for {schema}...', {
						schema: schema.title,
					}),
				)
				const response = await axios.post(
					url,
					{ register: this.item.id, schema: schema.id },
					{ headers: JSON_HEADERS },
				)
				this.reportValidation(response.data, schema)
			} catch (error) {
				console.error('Error validating schema objects:', error)
				showError(
					t(
						'openregister',
						'Failed to validate {schema}: {error}',
						schemaFailure(schema, error),
					),
				)
			}
		},

		/**
		 * Show the outcome of a validation run and refresh the card when it ran.
		 *
		 * @param {object} data The response body
		 * @param {object} schema The validated schema
		 * @spec exclude UI plumbing: toast for a validation response
		 */
		reportValidation(data, schema) {
			if (!data || !data.success) {
				showSuccess(
					t('openregister', 'Validation completed for {schema}', {
						schema: schema.title,
					}),
				)
				return
			}
			const stats = data.statistics
			showSuccess(
				t(
					'openregister',
					'Validation completed for {schema}: {processed} processed, {updated} updated, {failed} failed',
					{
						schema: schema.title,
						processed: stats.processed,
						updated: stats.updated,
						failed: stats.failed,
					},
				),
			)
			if (stats.errors && stats.errors.length > 0) {
				console.warn('Validation errors:', stats.errors)
			}
			this.$emit('refresh')
		},

		/**
		 * @param {object} schema
		 * @param {boolean} hardDelete
		 * @spec exclude confirm-dialog + bulk-delete API passthrough with toast; bulk-delete contract owned by scoped-object-delete-api capability
		 */
		async deleteSchemaObjects(schema, hardDelete = false) {
			const prompt = this.deletionPrompt(schema, hardDelete)
			if (prompt && confirm(prompt)) {
				await this.runSchemaDeletion(schema, hardDelete)
			}
		},

		/**
		 * The confirmation text for deleting a schema's objects, or null after
		 * explaining why there is nothing to delete.
		 *
		 * @param {object} schema The schema
		 * @param {boolean} hardDelete Whether objects are removed for good
		 * @return {string|null} The confirmation text
		 * @spec exclude UI plumbing: confirmation text for a bulk delete
		 */
		deletionPrompt(schema, hardDelete) {
			const counts = {
				total: schema.stats?.objects?.total || 0,
				active: this.getSchemaObjectCount(schema),
				deleted: this.deletedCount(schema),
				schema: schema.title,
			}
			if (counts.total === 0) {
				showError(
					t('openregister', 'No objects to delete for schema {schema}', {
						schema: schema.title,
					}),
				)
				return null
			}
			return hardDelete
				? hardDeletionPrompt(counts)
				: softDeletionPrompt(counts)
		},

		/**
		 * Delete a schema's objects on the server and refresh the lists.
		 *
		 * @param {object} schema The schema
		 * @param {boolean} hardDelete Whether objects are removed for good
		 * @spec exclude bulk-delete API passthrough with toast
		 */
		async runSchemaDeletion(schema, hardDelete) {
			const url = apiUrl(`bulk/${this.item.id}/${schema.id}/delete-objects`)
			try {
				announceDeletionStart(schema, hardDelete)
				const response = await postDeletion(url, hardDelete)
				showSuccess(deletionResult(response.data, schema))
				await refreshRegisterViews()
			} catch (error) {
				console.error('Error deleting schema objects:', error)
				showError(deletionFailureText(schema, error))
			}
		},

		/**
		 * @param {object} schema
		 * @spec exclude confirm-dialog + schema-delete API passthrough with toast; schema contract owned by schema capability
		 */
		async removeSchemaFromRegister(schema) {
			if (!this.mayRemoveSchema(schema)) {
				return
			}
			const url = apiUrl(`schemas/${schema.id}`)
			try {
				showSuccess(
					t('openregister', 'Removing schema {schema}...', {
						schema: schema.title,
					}),
				)
				await axios.delete(url, { headers: { Accept: 'application/json' } })
				showSuccess(
					t('openregister', 'Schema {schema} removed successfully', {
						schema: schema.title,
					}),
				)
				this.$emit('refresh')
			} catch (error) {
				console.error('Error removing schema:', error)
				showError(
					t(
						'openregister',
						'Failed to remove schema {schema}: {error}',
						schemaFailure(schema, error),
					),
				)
			}
		},

		/**
		 * Whether a schema may leave the register: it holds no objects and the
		 * user confirms.
		 *
		 * @param {object} schema The schema
		 * @return {boolean} Whether to remove it
		 * @spec exclude UI plumbing: guard and confirmation before removing a schema
		 */
		mayRemoveSchema(schema) {
			const count = this.getSchemaObjectCount(schema)
			if (count > 0) {
				showError(
					t(
						'openregister',
						'Cannot remove schema {schema} because it contains {count} objects',
						{ schema: schema.title, count },
					),
				)
				return false
			}
			return confirm(
				t(
					'openregister',
					'Are you sure you want to remove the schema "{schema}" from register "{register}"? This action cannot be undone.',
					{ schema: schema.title, register: this.item.title },
				),
			)
		},
	},
}
</script>

<style lang="scss" scoped>
@use '../../styles/common.scss' as *;

.registerSchemaCard {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.registerSchemaCard--managed {
	border: 2px solid var(--color-success);
}

.registerSchemaCard--local {
	border: 2px solid var(--color-warning);
}

.registerSchemaCard--in-use {
	border: 2px solid var(--color-success);
}

.cardHeader {
	padding: 16px;
	flex-wrap: wrap;
	margin: 0;
}

.cardHeader h2 {
	margin-bottom: 0;
}

.cardHeaderTitleRow {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
	flex: 1;
}

.cardTitleClip {
	overflow: hidden;
	min-width: 0;
	flex: 1;
}

.cardTitleTextWrapper {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 1.2em;
	color: var(--color-main-text);
	min-width: 0;
}

.cardTitleIcon {
	flex-shrink: 0;
}

.cardTitleText {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
	min-width: 0;
}

.cardHeaderBadgeRow {
	width: 100%;
	flex-basis: 100%;
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
}

.cardHeaderBadgeRow .managedBadge {
	margin-left: 0;
}

// style and clamping for registerDescription cannot be mixed due to odd behavior when using padding with clamping,
// and margin is not a good substitution due to style changes.
.registerDescription-container {
	// ensures style
	padding: 16px;
	margin: 0 0 12px 0;
	font-size: 0.95em;
	line-height: 1.5;
	background-color: var(--color-background-hover);
	color: var(--color-text-lighter);
	cursor: pointer;
	transition: max-height 0.3s ease;
}

.registerDescription-container:hover {
	background-color: var(--color-background-dark);
}

.registerDescription {
	// does the clamping
	display: -webkit-box;
	-webkit-line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
	cursor: pointer;
}

.registerDescription--expanded {
	max-height: none !important;
	display: block;
	-webkit-line-clamp: unset;
	line-clamp: unset;
}

.registerDescription--empty {
	cursor: default;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.registerDescription--empty:hover {
	background-color: var(--color-background-hover);
}

.registerSchemasScroll {
	overflow-x: auto;
	overflow-y: visible;
	-webkit-overflow-scrolling: touch;
	min-width: 0;
}

.registerSchemasScroll .registerSchemas {
	min-width: max-content;
	width: 100%;
}

.registerSchemas {
	border-top: none !important;
	margin-top: 0 !important;
}

.registerSchemas thead {
	border-top: none !important;
}

.registerSchemas thead tr {
	border-top: none !important;
}

.registerSchemas thead th {
	border-top: none !important;
}

.viewMoreContainer {
	display: flex;
	justify-content: stretch;
	padding: 0;
}

.viewMoreContainer button {
	width: 100%;
	border-radius: 0 0 8px 8px;
}

.emptyText {
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: 16px !important;
}

@include managed-badge;

@include managed-badge-external;

@include managed-badge-local;

@include status-pill-rules;

.schemaNameCell {
	display: table-cell;
	vertical-align: middle;
}

.schemaNameCell .schemaIcon {
	margin-right: 8px;
	vertical-align: middle;
	display: inline-block;
}

.schemaIcon--magic {
	color: var(--color-success);
}

.schemaIcon--blob {
	color: var(--color-primary);
}

.deletedCount {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-style: italic;
}

.required-indicator {
	color: var(--color-warning-dark);
	font-size: 0.8em;
	margin-left: 4px;
}

@media (prefers-reduced-motion: reduce) {
	.registerDescription-container {
		transition: none;
	}
}
</style>
