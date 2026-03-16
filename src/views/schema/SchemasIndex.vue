<script setup>
import { schemaStore, navigationStore, configurationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Schemas')"
			:description="t('openregister', 'Manage your data schemas and their properties')"
			:show-title="true"
			:objects="schemaStore.schemaList"
			:columns="tableColumns"
			:pagination="paginationData"
			:view-mode="schemaStore.viewMode"
			:selectable="true"
			:selected-ids="selectedSchemas"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			add-label="Add Schema"
			row-key="id"
			:empty-text="emptyContentName"
			:row-class="getRowClass"
			:refreshing="isRefreshing"
			@add="schemaStore.setSchemaItem(null); navigationStore.setModal('editSchema')"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="schemaStore.setViewMode($event)"
			@select="onSelect">
			<!-- TODO: Convert EditSchema.vue to a component in @conduction/nextcloud-vue -->

			<!-- Custom card template -->
			<template #card="{ object }">
				<RegisterSchemaCard :item="object" type="schema" @refresh="handleRefresh" />
			</template>

			<!-- Custom column: title with badges -->
			<template #column-title="{ row }">
				<div class="titleContent">
					<div class="titleWithBadges">
						<strong>{{ row.title }}</strong>
						<span v-if="row.extend" class="statusPill statusPill--alert">
							{{ t('openregister', 'Extended') }}
						</span>
						<span v-if="hasObjects(row)" class="statusPill statusPill--success">
							{{ t('openregister', 'In use') }}
						</span>
						<span v-if="isManagedByExternalConfig(row)" class="managedBadge managedBadge--external">
							<CogOutline :size="16" />
							{{ t('openregister', 'Managed') }}
						</span>
						<span v-else-if="isManagedByLocalConfig(row)" class="managedBadge managedBadge--local">
							<CogOutline :size="16" />
							{{ t('openregister', 'Local') }}
						</span>
					</div>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<!-- Custom column: properties count -->
			<template #column-properties="{ row }">
				{{ Object.keys(row.properties || {}).length }}
			</template>

			<!-- Custom column: created date -->
			<template #column-created="{ row }">
				{{ row.created ? new Date(row.created).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(row.created).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}
			</template>

			<!-- Custom column: updated date -->
			<template #column-updated="{ row }">
				{{ row.updated ? new Date(row.updated).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(row.updated).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}
			</template>

			<!-- Custom row actions for table view -->
			<template #row-actions="{ row }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton
						v-tooltip="isManagedByExternalConfig(row) ? 'Cannot edit: This schema is managed by external configuration ' + (getManagingConfiguration(row)?.title || '') : ''"
						close-after-click
						:disabled="isManagedByExternalConfig(row)"
						@click="schemaStore.setSchemaItem(row); navigationStore.setModal('editSchema')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton
						v-if="!row.published || (row.depublished && new Date(row.depublished) <= new Date())"
						close-after-click
						@click="publishSchema(row)">
						<template #icon>
							<Publish :size="20" />
						</template>
						Publish
					</NcActionButton>
					<NcActionButton
						v-if="row.published && (!row.depublished || new Date(row.depublished) > new Date())"
						close-after-click
						@click="depublishSchema(row)">
						<template #icon>
							<PublishOff :size="20" />
						</template>
						Depublish
					</NcActionButton>
					<NcActionButton v-tooltip="row.stats?.objects?.total > 0 ? 'Cannot delete: objects are still attached' : ''"
						close-after-click
						:disabled="row.stats?.objects?.total > 0"
						@click="schemaStore.setSchemaItem(row); navigationStore.setDialog('deleteSchema')">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						Delete
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import RegisterSchemaCard from '../../components/cards/RegisterSchemaCard.vue'

export default {
	name: 'SchemasIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		CogOutline,
		Publish,
		PublishOff,
		RegisterSchemaCard,
	},
	data() {
		return {
			selectedSchemas: [],
			isRefreshing: false,
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), sortable: true },
				{ key: 'properties', label: t('openregister', 'Properties') },
				{ key: 'created', label: t('openregister', 'Created'), sortable: true },
				{ key: 'updated', label: t('openregister', 'Updated'), sortable: true },
			]
		},
		paginationData() {
			const page = schemaStore.pagination.page || 1
			const limit = schemaStore.pagination.limit || 20
			const total = schemaStore.schemaList.length
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (!schemaStore.schemaList.length) {
				return t('openregister', 'No schemas found')
			}
			return t('openregister', 'Loading schemas...')
		},
	},
	async mounted() {
		try {
			await Promise.all([
				schemaStore.refreshSchemaList(),
				configurationStore.refreshConfigurationList(),
			])
		} catch (error) {
			console.error('Failed to load data:', error)
		}
	},
	methods: {
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await schemaStore.refreshSchemaList()
			} finally {
				this.isRefreshing = false
			}
		},

		onPageChanged(page) {
			schemaStore.setPagination(page, schemaStore.pagination.limit)
		},

		onPageSizeChanged(pageSize) {
			schemaStore.setPagination(1, pageSize)
		},

		onSelect(ids) {
			this.selectedSchemas = ids
		},

		getRowClass(schema) {
			if (this.isManagedByExternalConfig(schema)) return 'viewTableRow--managed'
			if (this.isManagedByLocalConfig(schema)) return 'viewTableRow--local'
			if (this.hasObjects(schema)) return 'viewTableRow--in-use'
			return ''
		},

		hasObjects(schema) {
			return schema.stats?.objects?.total > 0
		},

		getManagingConfiguration(schema) {
			if (!schema || !schema.id) return null
			return configurationStore.configurationList.find(
				config => config.schemas && config.schemas.some(s => s.id === schema.id),
			) || null
		},

		isManagedByExternalConfig(schema) {
			const config = this.getManagingConfiguration(schema)
			if (!config) return false
			return (config.sourceType && ['github', 'gitlab', 'url'].includes(config.sourceType)) || config.isLocal === false
		},

		isManagedByLocalConfig(schema) {
			const config = this.getManagingConfiguration(schema)
			if (!config) return false
			return config.sourceType === 'local' || config.sourceType === 'manual' || config.isLocal === true
		},

		async publishSchema(schema) {
			try {
				await schemaStore.publishSchema(schema.id)
				showSuccess(t('openregister', 'Schema published successfully'))
			} catch (error) {
				console.error('Error publishing schema:', error)
				showError(t('openregister', 'Failed to publish schema: {error}', { error: error.message }))
			}
		},

		async depublishSchema(schema) {
			try {
				await schemaStore.depublishSchema(schema.id)
				showSuccess(t('openregister', 'Schema depublished successfully'))
			} catch (error) {
				console.error('Error depublishing schema:', error)
				showError(t('openregister', 'Failed to depublish schema: {error}', { error: error.message }))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
/* Table row borders for managed schemas (external - green) */
:deep(.viewTableRow--managed) {
	border-left: 4px solid var(--color-success);
}

/* Table row borders for local configurations (orange) */
:deep(.viewTableRow--local) {
	border-left: 4px solid var(--color-warning);
}

/* Table row borders for in-use schemas */
:deep(.viewTableRow--in-use) {
	border-left: 4px solid var(--color-success);
}

/* Status Pills */
.statusPill {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
	margin-left: 8px;
	white-space: nowrap;
}

.statusPill--alert {
	background-color: var(--color-warning);
	color: var(--color-main-background);
}

.statusPill--success {
	background-color: var(--color-success);
	color: white;
}

/* Title with badges layout */
.titleWithBadges {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 4px;
}

.textDescription {
	display: block;
	overflow: hidden;
}

.textEllipsis {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

/* Managed by Configuration badge */
.managedBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	margin-left: 8px;
	vertical-align: middle;
}

/* External (managed) badge - green */
.managedBadge--external {
	background: var(--color-success);
	color: white;
}

/* Local configuration badge - orange */
.managedBadge--local {
	background: var(--color-warning);
	color: var(--color-main-background);
}
</style>
