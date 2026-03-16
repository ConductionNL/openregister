<script setup>
import { dashboardStore, registerStore, schemaStore, navigationStore, configurationStore } from '../../store/store.js'
</script>

<template>
	<div
		class="registerSchemaCard"
		:class="{
			'registerSchemaCard--managed': isManagedByExternalConfig,
			'registerSchemaCard--local': isManagedByLocalConfig,
		}">
		<!-- Card Header -->
		<div class="cardHeader">
			<div class="cardHeaderTitleRow">
				<div v-tooltip.bottom="register.description || register.title" class="cardTitleClip">
					<h2 class="cardTitleTextWrapper">
						<DatabaseOutline :size="20" class="cardTitleIcon" />
						<span class="cardTitleText">{{ register.title }}</span>
					</h2>
				</div>
				<NcActions :primary="true" menu-name="Actions">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton
						v-tooltip="isManagedByExternalConfig ? 'Cannot edit: This register is managed by external configuration ' + managingConfiguration?.title : ''"
						close-after-click
						:disabled="isManagedByExternalConfig"
						@click="openEditRegister">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton
						v-if="!register.published || (register.depublished && new Date(register.depublished) <= new Date())"
						close-after-click
						@click="publishRegister">
						<template #icon>
							<Publish :size="20" />
						</template>
						Publish
					</NcActionButton>
					<NcActionButton
						v-if="register.published && (!register.depublished || new Date(register.depublished) > new Date())"
						close-after-click
						@click="depublishRegister">
						<template #icon>
							<PublishOff :size="20" />
						</template>
						Depublish
					</NcActionButton>
					<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); navigationStore.setModal('publishRegister')">
						<template #icon>
							<CloudUploadOutline :size="20" />
						</template>
						Publish OAS
					</NcActionButton>
					<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); navigationStore.setModal('importRegister')">
						<template #icon>
							<Upload :size="20" />
						</template>
						Import
					</NcActionButton>
					<NcActionButton close-after-click @click="viewOasDoc">
						<template #icon>
							<ApiIcon :size="20" />
						</template>
						View API Documentation
					</NcActionButton>
					<NcActionButton close-after-click @click="downloadOas">
						<template #icon>
							<Download :size="20" />
						</template>
						Download API Specification
					</NcActionButton>
					<NcActionButton v-tooltip="register.stats?.total > 0 ? 'Cannot delete: objects are still attached' : ''"
						close-after-click
						:disabled="register.stats?.total > 0"
						@click="registerStore.setRegisterItem(register); navigationStore.setDialog('deleteRegister')">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						Delete
					</NcActionButton>
					<NcActionButton close-after-click @click="viewRegisterDetails">
						<template #icon>
							<InformationOutline :size="20" />
						</template>
						View Details
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="isManagedByExternalConfig || isManagedByLocalConfig" class="cardHeaderBadgeRow">
				<span v-if="isManagedByExternalConfig" class="managedBadge managedBadge--external">
					<CogOutline :size="16" />
					{{ t('openregister', 'Managed') }}
				</span>
				<span v-else-if="isManagedByLocalConfig" class="managedBadge managedBadge--local">
					<CogOutline :size="16" />
					{{ t('openregister', 'Local') }}
				</span>
			</div>
		</div>

		<!-- Description -->
		<div class="registerDescription-container" @click="register.description ? descriptionExpanded = !descriptionExpanded : null">
			<div class="registerDescription"
				:class="{ 'registerDescription--expanded': descriptionExpanded, 'registerDescription--empty': !register.description }">
				{{ register.description || t('openregister', 'No description found') }}
			</div>
		</div>

		<!-- Schemas Table -->
		<div class="registerSchemasScroll">
			<table class="statisticsTable registerSchemas">
				<thead>
					<tr>
						<th>{{ t('openregister', 'Schema Name') }}</th>
						<th>{{ t('openregister', 'Objects') }}</th>
						<th>{{ t('openregister', 'Configuration') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="schema in displayedSchemas" :key="schema.id">
						<td class="schemaNameCell">
							<Table
								v-if="hasMagicMapping(schema)"
								v-tooltip="'Magic Table'"
								:size="18"
								class="schemaIcon schemaIcon--magic" />
							<DatabaseOutline
								v-else
								v-tooltip="'Blob Storage'"
								:size="18"
								class="schemaIcon schemaIcon--blob" />
							{{ schema.title }}
						</td>
						<td>
							<span v-if="schema.stats?.objects?.deleted > 0" v-tooltip="t('openregister', '{active} active, {deleted} deleted', { active: (schema.stats.objects.total - schema.stats.objects.deleted), deleted: schema.stats.objects.deleted })">
								{{ (schema.stats.objects.total - schema.stats.objects.deleted) || 0 }} <span class="deletedCount">({{ schema.stats?.objects?.deleted || 0 }} deleted)</span>
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
								<NcActionButton close-after-click @click="setSchemaConfiguration(schema, 'magic')">
									<template #icon>
										<Table :size="20" />
									</template>
									{{ hasMagicMapping(schema) ? '&#10003; ' : '' }}Use Magic Table
								</NcActionButton>
								<NcActionButton close-after-click @click="setSchemaConfiguration(schema, 'blob')">
									<template #icon>
										<DatabaseOutline :size="20" />
									</template>
									{{ !hasMagicMapping(schema) ? '&#10003; ' : '' }}Use Blob Storage
								</NcActionButton>
								<NcActionButton
									v-tooltip="!hasMagicMapping(schema) ? t('openregister', 'This schema must use Magic Table configuration to sync') : ''"
									:disabled="!hasMagicMapping(schema)"
									close-after-click
									@click="syncMagicTable(schema)">
									<template #icon>
										<Sync :size="20" />
									</template>
									{{ t('openregister', 'Sync Table') }}
								</NcActionButton>
								<NcActionButton
									close-after-click
									@click="validateSchemaObjects(schema)">
									<template #icon>
										<CheckCircle :size="20" />
									</template>
									{{ t('openregister', 'Validate') }}
								</NcActionButton>
								<NcActionButton
									close-after-click
									@click="registerStore.setRegisterItem(register); schemaStore.setSchemaItem(schema); navigationStore.setModal('exportRegister')">
									<template #icon>
										<Export :size="20" />
									</template>
									{{ t('openregister', 'Export') }}
								</NcActionButton>
								<NcActionButton
									close-after-click
									@click="registerStore.setRegisterItem(register); schemaStore.setSchemaItem(schema); navigationStore.setModal('importRegister')">
									<template #icon>
										<Upload :size="20" />
									</template>
									{{ t('openregister', 'Import') }}
								</NcActionButton>
								<NcActionButton
									v-tooltip="(schema.stats?.objects?.total || 0) === 0 ? t('openregister', 'No objects to delete') : t('openregister', 'Soft delete all objects for this schema ({active} active, {deleted} already deleted)', { active: getSchemaObjectCount(schema), deleted: (schema.stats?.objects?.deleted || 0) })"
									:disabled="getSchemaObjectCount(schema) === 0"
									close-after-click
									@click="deleteSchemaObjects(schema, false)">
									<template #icon>
										<DeleteOutline :size="20" />
									</template>
									{{ t('openregister', 'Delete Objects') }}
								</NcActionButton>
								<NcActionButton
									v-if="(schema.stats?.objects?.deleted || 0) > 0"
									v-tooltip="t('openregister', 'Permanently delete all {count} soft-deleted objects. This cannot be undone!', { count: (schema.stats?.objects?.deleted || 0) })"
									type="error"
									close-after-click
									@click="deleteSchemaObjects(schema, true)">
									<template #icon>
										<DeleteOutline :size="20" />
									</template>
									{{ t('openregister', 'Permanently Delete ({count})', { count: (schema.stats?.objects?.deleted || 0) }) }}
								</NcActionButton>
								<NcActionButton
									v-tooltip="getSchemaObjectCount(schema) > 0 ? t('openregister', 'Cannot remove schema with existing objects ({count} objects)', { count: getSchemaObjectCount(schema) }) : ''"
									:disabled="getSchemaObjectCount(schema) > 0"
									close-after-click
									@click="removeSchemaFromRegister(schema)">
									<template #icon>
										<TrashCanOutline :size="20" />
									</template>
									{{ t('openregister', 'Remove') }}
								</NcActionButton>
							</NcActions>
						</td>
					</tr>
					<tr v-if="!register.schemas || register.schemas.length === 0">
						<td colspan="3" class="emptyText">
							{{ t('openregister', 'No schemas found') }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- View More Button -->
		<div v-if="remainingSchemasCount > 0" class="viewMoreContainer">
			<NcButton
				type="secondary"
				@click="registerExpanded = !registerExpanded">
				<template #icon>
					<ChevronDown v-if="!registerExpanded" :size="20" />
					<ChevronUp v-else :size="20" />
				</template>
				{{ registerExpanded
					? t('openregister', 'Show less')
					: t('openregister', 'View {count} more', { count: remainingSchemasCount })
				}}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcButton } from '@nextcloud/vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Export from 'vue-material-design-icons/Export.vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import Download from 'vue-material-design-icons/Download.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import Table from 'vue-material-design-icons/Table.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'RegisterSchemaCard',
	components: {
		NcActions,
		NcActionButton,
		NcButton,
		DatabaseOutline,
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
		Publish,
		PublishOff,
		Sync,
		// eslint-disable-next-line vue/no-reserved-component-names
		Table,
		CheckCircle,
		DeleteOutline,
	},
	props: {
		register: {
			type: Object,
			required: true,
		},
	},
	emits: ['refresh'],
	data() {
		return {
			registerExpanded: false,
			descriptionExpanded: false,
		}
	},
	computed: {
		managingConfiguration() {
			if (!this.register || !this.register.id) return null
			return configurationStore.configurationList.find(
				config => config.registers && config.registers.includes(this.register.id),
			) || null
		},
		isManagedByExternalConfig() {
			const config = this.managingConfiguration
			if (!config) return false
			return (config.sourceType && ['github', 'gitlab', 'url'].includes(config.sourceType)) || config.isLocal === false
		},
		isManagedByLocalConfig() {
			const config = this.managingConfiguration
			if (!config) return false
			return config.sourceType === 'local' || config.sourceType === 'manual' || config.isLocal === true
		},
		displayedSchemas() {
			if (!this.register.schemas || this.register.schemas.length === 0) {
				return []
			}
			if (this.registerExpanded) {
				return this.register.schemas
			}
			return this.register.schemas.slice(0, 5)
		},
		remainingSchemasCount() {
			const total = this.register.schemas?.length || 0
			return Math.max(0, total - 5)
		},
	},
	methods: {
		openEditRegister() {
			registerStore.setRegisterItem({
				...this.register,
				schemas: Array.isArray(this.register.schemas)
					? this.register.schemas.map(schema => typeof schema === 'object' ? schema.id : schema)
					: [],
			})
			navigationStore.setModal('editRegister')
		},

		async publishRegister() {
			try {
				await registerStore.publishRegister(this.register.id)
				showSuccess(t('openregister', 'Register published successfully'))
			} catch (error) {
				console.error('Error publishing register:', error)
				showError(t('openregister', 'Failed to publish register: {error}', { error: error.message }))
			}
		},

		async depublishRegister() {
			try {
				await registerStore.depublishRegister(this.register.id)
				showSuccess(t('openregister', 'Register depublished successfully'))
			} catch (error) {
				console.error('Error depublishing register:', error)
				showError(t('openregister', 'Failed to depublish register: {error}', { error: error.message }))
			}
		},

		viewRegisterDetails() {
			registerStore.setRegisterItem({ id: this.register.id })
			this.$router.push(`/registers/${this.register.id}`)
		},

		async downloadOas() {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${this.register.id}/oas`
			try {
				const response = await axios.get(apiUrl)
				const blob = new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' })
				const downloadLink = document.createElement('a')
				downloadLink.href = URL.createObjectURL(blob)
				downloadLink.download = `${this.register.title.toLowerCase()}-api-specification.json`
				document.body.appendChild(downloadLink)
				downloadLink.click()
				document.body.removeChild(downloadLink)
				URL.revokeObjectURL(downloadLink.href)
			} catch (error) {
				showError(t('openregister', 'Failed to download API specification'))
				console.error('Error downloading OAS:', error)
			}
		},

		viewOasDoc() {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${this.register.id}/oas`
			window.open(`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`, '_blank')
		},

		hasMagicMapping(schema) {
			if (!schema || !schema.properties) {
				return false
			}
			return Object.values(schema.properties).some(property =>
				property && property.table && typeof property.table === 'object',
			)
		},

		getSchemaObjectCount(schema) {
			if (!schema || !schema.stats || !schema.stats.objects) {
				return 0
			}
			const total = schema.stats.objects.total || 0
			const deleted = schema.stats.objects.deleted || 0
			return total - deleted
		},

		async syncMagicTable(schema) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/tables/sync/${this.register.id}/${schema.id}`

			try {
				showSuccess(t('openregister', 'Syncing magic table for {schema}...', { schema: schema.title }))

				const response = await axios.post(apiUrl, {}, {
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
				})

				if (response.data && response.data.success) {
					const stats = response.data.statistics
					const details = []

					if (stats.metadata && stats.properties) {
						details.push(`${stats.metadata.count} metadata columns, ${stats.properties.count} property columns`)
					}

					if (stats.columns) {
						const changes = []
						if (stats.columns.added.count > 0) {
							changes.push(`${stats.columns.added.count} added`)
						}
						if (stats.columns.removed.count > 0) {
							changes.push(`${stats.columns.removed.count} removed`)
						}
						if (stats.columns.deRequired.count > 0) {
							changes.push(`${stats.columns.deRequired.count} de-required`)
						}
						if (stats.columns.unchanged.count > 0) {
							changes.push(`${stats.columns.unchanged.count} unchanged`)
						}

						if (changes.length > 0) {
							details.push(`Columns: ${changes.join(', ')}`)
						}
					}

					const message = details.length > 0
						? `Magic table synced: ${details.join(' \u2022 ')}`
						: `Magic table synced successfully for ${schema.title}`

					showSuccess(t('openregister', message))
				} else {
					showSuccess(t('openregister', 'Magic table sync completed for {schema}', { schema: schema.title }))
				}
			} catch (error) {
				console.error('Error syncing magic table:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to sync magic table for {schema}: {error}', {
					schema: schema.title,
					error: errorMessage,
				}))
			}
		},

		async setSchemaConfiguration(schema, configurationType) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/schemas/${schema.id}`

			try {
				const fetchResponse = await axios.get(apiUrl, {
					headers: { Accept: 'application/json' },
				})

				const currentSchema = fetchResponse.data

				const updatedSchema = {
					title: currentSchema.title,
					type: currentSchema.type || 'object',
					properties: currentSchema.properties || {},
					required: currentSchema.required || [],
					description: currentSchema.description || '',
				}

				if (configurationType === 'magic') {
					if (!updatedSchema.properties || typeof updatedSchema.properties !== 'object') {
						updatedSchema.properties = {}
					}

					Object.keys(updatedSchema.properties).forEach(key => {
						if (!updatedSchema.properties[key].table) {
							updatedSchema.properties[key].table = {
								enabled: true,
								indexed: false,
								searchable: false,
							}
						}
					})

					showSuccess(t('openregister', 'Converting {schema} to magic table...', { schema: schema.title }))
				} else {
					if (updatedSchema.properties && typeof updatedSchema.properties === 'object') {
						Object.keys(updatedSchema.properties).forEach(key => {
							if (updatedSchema.properties[key].table) {
								delete updatedSchema.properties[key].table
							}
						})
					}

					showSuccess(t('openregister', 'Converting {schema} to blob storage...', { schema: schema.title }))
				}

				await axios.put(apiUrl, updatedSchema, {
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
				})

				showSuccess(t('openregister', 'Schema configuration updated successfully for {schema}', { schema: schema.title }))
				this.$emit('refresh')
			} catch (error) {
				console.error('Error updating schema configuration:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to update schema configuration for {schema}: {error}', {
					schema: schema.title,
					error: errorMessage,
				}))
			}
		},

		async validateSchemaObjects(schema) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/objects/validate`

			try {
				showSuccess(t('openregister', 'Starting validation for {schema}...', { schema: schema.title }))

				const response = await axios.post(apiUrl, {
					register: this.register.id,
					schema: schema.id,
				}, {
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
				})

				if (response.data && response.data.success) {
					const stats = response.data.statistics
					showSuccess(t('openregister', 'Validation completed for {schema}: {processed} processed, {updated} updated, {failed} failed', {
						schema: schema.title,
						processed: stats.processed,
						updated: stats.updated,
						failed: stats.failed,
					}))

					if (stats.errors && stats.errors.length > 0) {
						console.warn('Validation errors:', stats.errors)
					}

					this.$emit('refresh')
				} else {
					showSuccess(t('openregister', 'Validation completed for {schema}', { schema: schema.title }))
				}
			} catch (error) {
				console.error('Error validating schema objects:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to validate {schema}: {error}', {
					schema: schema.title,
					error: errorMessage,
				}))
			}
		},

		async deleteSchemaObjects(schema, hardDelete = false) {
			const totalObjects = schema.stats?.objects?.total || 0
			const activeObjects = this.getSchemaObjectCount(schema)
			const deletedObjects = schema.stats?.objects?.deleted || 0

			if (totalObjects === 0) {
				showError(t('openregister', 'No objects to delete for schema {schema}', {
					schema: schema.title,
				}))
				return
			}

			let confirmMessage = ''
			if (hardDelete) {
				if (activeObjects > 0 && deletedObjects > 0) {
					confirmMessage = t('openregister', '\u26A0\uFE0F PERMANENT DELETION WARNING \u26A0\uFE0F\n\nYou are about to PERMANENTLY delete ALL objects for schema "{schema}":\n\n\u2022 Active objects: {active}\n\u2022 Soft-deleted objects: {deleted}\n\u2022 Total: {total}\n\nThese objects will be completely removed from the database and CANNOT be recovered.\n\nAre you absolutely sure?', {
						active: activeObjects,
						deleted: deletedObjects,
						total: totalObjects,
						schema: schema.title,
					})
				} else {
					confirmMessage = t('openregister', '\u26A0\uFE0F PERMANENT DELETION WARNING \u26A0\uFE0F\n\nYou are about to PERMANENTLY delete {count} soft-deleted objects for schema "{schema}".\n\nThese objects will be completely removed from the database and CANNOT be recovered.\n\nAre you absolutely sure?', {
						count: deletedObjects,
						schema: schema.title,
					})
				}
			} else {
				if (activeObjects === 0) {
					showError(t('openregister', 'No active objects to soft-delete for schema {schema}. Use "Permanently Delete" to remove soft-deleted objects.', {
						schema: schema.title,
					}))
					return
				}
				confirmMessage = t('openregister', 'Are you sure you want to soft-delete {count} active objects for schema "{schema}"?\n\nThey will be marked as deleted but can be permanently removed later.', {
					count: activeObjects,
					schema: schema.title,
				})
			}

			if (!confirm(confirmMessage)) {
				return
			}

			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/bulk/${this.register.id}/${schema.id}/delete-objects`

			try {
				const actionType = hardDelete ? 'permanently deleting' : 'soft-deleting'
				showSuccess(t('openregister', 'Starting {action} for {schema}...', {
					action: actionType,
					schema: schema.title,
				}))

				const response = await axios.post(apiUrl, {
					hardDelete,
				}, {
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
				})

				if (response.data && response.data.success) {
					const deletedCount = response.data.deleted_count || 0

					showSuccess(t('openregister', 'Successfully deleted {count} objects for {schema}', {
						count: deletedCount,
						schema: schema.title,
					}))
				} else {
					showSuccess(t('openregister', 'Objects deletion completed for {schema}', { schema: schema.title }))
				}

				await Promise.all([
					registerStore.refreshRegisterList(),
					dashboardStore.fetchRegisters(),
				])
			} catch (error) {
				console.error('Error deleting schema objects:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to delete objects for {schema}: {error}', {
					schema: schema.title,
					error: errorMessage,
				}))
			}
		},

		async removeSchemaFromRegister(schema) {
			const objectCount = this.getSchemaObjectCount(schema)

			if (objectCount > 0) {
				showError(t('openregister', 'Cannot remove schema {schema} because it contains {count} objects', {
					schema: schema.title,
					count: objectCount,
				}))
				return
			}

			if (!confirm(t('openregister', 'Are you sure you want to remove the schema "{schema}" from register "{register}"? This action cannot be undone.', {
				schema: schema.title,
				register: this.register.title,
			}))) {
				return
			}

			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/schemas/${schema.id}`

			try {
				showSuccess(t('openregister', 'Removing schema {schema}...', { schema: schema.title }))

				await axios.delete(apiUrl, {
					headers: { Accept: 'application/json' },
				})

				showSuccess(t('openregister', 'Schema {schema} removed successfully', { schema: schema.title }))
				this.$emit('refresh')
			} catch (error) {
				console.error('Error removing schema:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to remove schema {schema}: {error}', {
					schema: schema.title,
					error: errorMessage,
				}))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
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
}

.cardHeaderBadgeRow .managedBadge {
	margin-left: 0;
}

// style and clamping for registerDescription cannot be mixed due to odd behavior when using padding with clamping,
// and margin is not a good substitution due to style changes.
.registerDescription-container { // ensures style
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
.registerDescription { // does the clamping
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

.managedBadge--external {
	background: var(--color-success);
	color: white;
}

.managedBadge--local {
	background: var(--color-warning);
	color: var(--color-main-background);
}

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
</style>
