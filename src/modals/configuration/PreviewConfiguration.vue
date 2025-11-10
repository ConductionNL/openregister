<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'previewConfiguration'"
		name="Preview Configuration Changes"
		size="large"
		:can-close="true"
		:open="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="64" />

		<div v-else-if="preview" class="previewContainer">
			<!-- Summary -->
			<NcNoteCard type="info">
				<p>
					<strong>Configuration:</strong> {{ configurationStore.configurationItem?.title }}<br>
					<strong>Remote Version:</strong> {{ preview.metadata?.remoteVersion || '-' }}<br>
					<strong>Local Version:</strong> {{ preview.metadata?.localVersion || '-' }}<br>
					<strong>Total Changes:</strong> {{ preview.metadata?.totalChanges || 0 }}
				</p>
			</NcNoteCard>

			<!-- Selection Controls -->
			<div class="selectionControls">
				<NcButton @click="selectAll">
					<template #icon>
						<CheckAll :size="20" />
					</template>
					Select All
				</NcButton>
				<NcButton @click="deselectAll">
					<template #icon>
						<CloseCircle :size="20" />
					</template>
					Deselect All
				</NcButton>
			</div>

			<!-- Registers Section -->
			<div v-if="preview.registers && preview.registers.length > 0" class="changeSection">
				<h3>
					<Database :size="20" />
					Registers ({{ preview.registers.length }})
				</h3>
				<div class="changeList">
					<div v-for="(change, index) in preview.registers"
						:key="'register-' + index"
						class="changeItem"
						:class="{ 'changeItem-selected': isRegisterSelected(change.slug) }">
						<div class="changeHeader">
							<NcCheckboxRadioSwitch
								:checked="isRegisterSelected(change.slug)"
								@update:checked="(checked) => toggleRegisterSelection(change.slug, checked)" />
							<div class="changeTitle">
								<strong>{{ change.title || change.slug }}</strong>
								<span class="changeBadge" :class="'changeBadge-' + change.action">
									{{ change.action }}
								</span>
							</div>
						</div>
						<div v-if="change.current" class="changeDetails">
							<p class="changeDetailRow">
								<strong>Current:</strong> v{{ change.current.version || '1.0.0' }}
							</p>
							<p class="changeDetailRow">
								<strong>Proposed:</strong> v{{ change.proposed.version || '1.0.0' }}
							</p>
							<div v-if="change.changes && change.changes.length > 0" class="changesDiff">
								<strong>Changes ({{ change.changes.length }}):</strong>
								<ul>
									<li v-for="(diff, i) in change.changes.slice(0, 5)" :key="i">
										<code>{{ diff.field }}</code>:
										<span class="diffOld">{{ formatValue(diff.current) }}</span>
										→
										<span class="diffNew">{{ formatValue(diff.proposed) }}</span>
									</li>
									<li v-if="change.changes.length > 5">
										... and {{ change.changes.length - 5 }} more
									</li>
								</ul>
							</div>
						</div>
						<p v-if="change.reason" class="changeReason">
							{{ change.reason }}
						</p>
					</div>
				</div>
			</div>

			<!-- Schemas Section -->
			<div v-if="preview.schemas && preview.schemas.length > 0" class="changeSection">
				<h3>
					<FileDocument :size="20" />
					Schemas ({{ preview.schemas.length }})
				</h3>
				<div class="changeList">
					<div v-for="(change, index) in preview.schemas"
						:key="'schema-' + index"
						class="changeItem"
						:class="{ 'changeItem-selected': isSchemaSelected(change.slug) }">
						<div class="changeHeader">
							<NcCheckboxRadioSwitch
								:checked="isSchemaSelected(change.slug)"
								@update:checked="(checked) => toggleSchemaSelection(change.slug, checked)" />
							<div class="changeTitle">
								<strong>{{ change.title || change.slug }}</strong>
								<span class="changeBadge" :class="'changeBadge-' + change.action">
									{{ change.action }}
								</span>
							</div>
						</div>
						<div v-if="change.current" class="changeDetails">
							<p class="changeDetailRow">
								<strong>Current:</strong> v{{ change.current.version || '1.0.0' }}
							</p>
							<p class="changeDetailRow">
								<strong>Proposed:</strong> v{{ change.proposed.version || '1.0.0' }}
							</p>
							<div v-if="change.changes && change.changes.length > 0" class="changesDiff">
								<strong>Changes ({{ change.changes.length }}):</strong>
								<ul>
									<li v-for="(diff, i) in change.changes.slice(0, 5)" :key="i">
										<code>{{ diff.field }}</code>:
										<span class="diffOld">{{ formatValue(diff.current) }}</span>
										→
										<span class="diffNew">{{ formatValue(diff.proposed) }}</span>
									</li>
									<li v-if="change.changes.length > 5">
										... and {{ change.changes.length - 5 }} more
									</li>
								</ul>
							</div>
						</div>
						<p v-if="change.reason" class="changeReason">
							{{ change.reason }}
						</p>
					</div>
				</div>
			</div>

			<!-- Objects Section -->
			<div v-if="preview.objects && preview.objects.length > 0" class="changeSection">
				<h3>
					<CubeOutline :size="20" />
					Objects ({{ preview.objects.length }})
				</h3>
				<div class="changeList">
					<div v-for="(change, index) in preview.objects"
						:key="'object-' + index"
						class="changeItem"
						:class="{ 'changeItem-selected': isObjectSelected(change) }">
						<div class="changeHeader">
							<NcCheckboxRadioSwitch
								:checked="isObjectSelected(change)"
								@update:checked="(checked) => toggleObjectSelection(change, checked)" />
							<div class="changeTitle">
								<strong>{{ change.title || change.slug }}</strong>
								<span class="changeBadge" :class="'changeBadge-' + change.action">
									{{ change.action }}
								</span>
								<span class="changeContext">
									{{ change.register }}:{{ change.schema }}
								</span>
							</div>
						</div>
						<div v-if="change.current" class="changeDetails">
							<p class="changeDetailRow">
								<strong>Current:</strong> v{{ change.current['@self']?.version || change.current.version || '1.0.0' }}
							</p>
							<p class="changeDetailRow">
								<strong>Proposed:</strong> v{{ change.proposed['@self']?.version || change.proposed.version || '1.0.0' }}
							</p>
							<div v-if="change.changes && change.changes.length > 0" class="changesDiff">
								<strong>Changes ({{ change.changes.length }}):</strong>
								<ul>
									<li v-for="(diff, i) in change.changes.slice(0, 3)" :key="i">
										<code>{{ diff.field }}</code>:
										<span class="diffOld">{{ formatValue(diff.current) }}</span>
										→
										<span class="diffNew">{{ formatValue(diff.proposed) }}</span>
									</li>
									<li v-if="change.changes.length > 3">
										... and {{ change.changes.length - 3 }} more
									</li>
								</ul>
							</div>
						</div>
						<p v-if="change.reason" class="changeReason">
							{{ change.reason }}
						</p>
					</div>
				</div>
			</div>

			<!-- No Changes -->
			<NcEmptyContent v-if="!preview.registers?.length && !preview.schemas?.length && !preview.objects?.length"
				name="No changes to preview"
				description="The remote configuration doesn't have any updates.">
				<template #icon>
					<CheckCircle :size="64" />
				</template>
			</NcEmptyContent>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				v-if="hasSelection"
				:disabled="loading"
				type="primary"
				@click="importSelected">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Download v-else :size="20" />
				</template>
				Import Selected ({{ selectionCount }})
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
} from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Database from 'vue-material-design-icons/Database.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'

export default {
	name: 'PreviewConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		// Icons
		Cancel,
		Download,
		Database,
		FileDocument,
		CubeOutline,
		CheckAll,
		CloseCircle,
		CheckCircle,
	},
	data() {
		return {
			loading: false,
			error: null,
			preview: null,
			selectedRegisters: [],
			selectedSchemas: [],
			selectedObjects: [],
		}
	},
	computed: {
		hasSelection() {
			return this.selectedRegisters.length > 0
				   || this.selectedSchemas.length > 0
				   || this.selectedObjects.length > 0
		},
		selectionCount() {
			return this.selectedRegisters.length
				   + this.selectedSchemas.length
				   + this.selectedObjects.length
		},
	},
	async mounted() {
		await this.loadPreview()
	},
	methods: {
		async loadPreview() {
			const configuration = configurationStore.configurationItem
			if (!configuration || !configuration.id) {
				this.error = 'No configuration selected'
				return
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.get(
					generateUrl(`/apps/openregister/api/configurations/${configuration.id}/preview`),
				)

				this.preview = response.data

				// Auto-select all changes by default
				this.selectAll()
			} catch (error) {
				console.error('Failed to load preview:', error)
				this.error = error.response?.data?.error || 'Failed to load configuration preview'
			} finally {
				this.loading = false
			}
		},
		isRegisterSelected(slug) {
			return this.selectedRegisters.includes(slug)
		},
		isSchemaSelected(slug) {
			return this.selectedSchemas.includes(slug)
		},
		isObjectSelected(change) {
			const objectId = `${change.register}:${change.schema}:${change.slug}`
			return this.selectedObjects.includes(objectId)
		},
		toggleRegisterSelection(slug, checked) {
			if (checked) {
				if (!this.selectedRegisters.includes(slug)) {
					this.selectedRegisters.push(slug)
				}
			} else {
				this.selectedRegisters = this.selectedRegisters.filter(s => s !== slug)
			}
		},
		toggleSchemaSelection(slug, checked) {
			if (checked) {
				if (!this.selectedSchemas.includes(slug)) {
					this.selectedSchemas.push(slug)
				}
			} else {
				this.selectedSchemas = this.selectedSchemas.filter(s => s !== slug)
			}
		},
		toggleObjectSelection(change, checked) {
			const objectId = `${change.register}:${change.schema}:${change.slug}`
			if (checked) {
				if (!this.selectedObjects.includes(objectId)) {
					this.selectedObjects.push(objectId)
				}
			} else {
				this.selectedObjects = this.selectedObjects.filter(id => id !== objectId)
			}
		},
		selectAll() {
			// Select all registers
			if (this.preview.registers) {
				this.selectedRegisters = this.preview.registers
					.filter(r => r.action !== 'skip')
					.map(r => r.slug)
			}
			// Select all schemas
			if (this.preview.schemas) {
				this.selectedSchemas = this.preview.schemas
					.filter(s => s.action !== 'skip')
					.map(s => s.slug)
			}
			// Select all objects
			if (this.preview.objects) {
				this.selectedObjects = this.preview.objects
					.filter(o => o.action !== 'skip')
					.map(o => `${o.register}:${o.schema}:${o.slug}`)
			}
		},
		deselectAll() {
			this.selectedRegisters = []
			this.selectedSchemas = []
			this.selectedObjects = []
		},
		formatValue(value) {
			if (value === null || value === undefined) return '-'
			if (typeof value === 'object') return JSON.stringify(value).substring(0, 50) + '...'
			return String(value).substring(0, 50)
		},
		async importSelected() {
			const configuration = configurationStore.configurationItem
			if (!configuration || !configuration.id) {
				showError('No configuration selected')
				return
			}

			if (!this.hasSelection) {
				showError('Please select at least one item to import')
				return
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.post(
					generateUrl(`/apps/openregister/api/configurations/${configuration.id}/import`),
					{
						selection: {
							registers: this.selectedRegisters,
							schemas: this.selectedSchemas,
							objects: this.selectedObjects,
						},
					},
				)

				showSuccess(
					`Successfully imported: ${response.data.registersCount} registers, `
					+ `${response.data.schemasCount} schemas, ${response.data.objectsCount} objects`,
				)

				// Refresh the configuration list
				await configurationStore.refreshConfigurationList()

				this.closeModal()
			} catch (error) {
				console.error('Failed to import configuration:', error)
				this.error = error.response?.data?.error || 'Failed to import configuration'
				showError(this.error)
			} finally {
				this.loading = false
			}
		},
		handleDialogClose() {
			this.closeModal()
		},
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
			this.preview = null
			this.selectedRegisters = []
			this.selectedSchemas = []
			this.selectedObjects = []
		},
	},
}
</script>

<style scoped>
.previewContainer {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
	max-height: 70vh;
	overflow-y: auto;
	padding: 0.5rem;
}

.selectionControls {
	display: flex;
	gap: 0.5rem;
	justify-content: flex-end;
}

.changeSection {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 1rem;
}

.changeSection h3 {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin: 0 0 1rem 0;
	font-size: 1.1rem;
}

.changeList {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.changeItem {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 0.75rem;
	background-color: var(--color-main-background);
	transition: all 0.2s ease;
}

.changeItem-selected {
	border-color: var(--color-primary);
	background-color: var(--color-primary-light);
}

.changeHeader {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
}

.changeTitle {
	flex: 1;
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.changeBadge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.75rem;
	font-weight: 500;
	text-transform: uppercase;
}

.changeBadge-create {
	background-color: var(--color-success);
	color: white;
}

.changeBadge-update {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

.changeBadge-skip {
	background-color: var(--color-background-dark);
	color: var(--color-text-lighter);
}

.changeContext {
	font-size: 0.85em;
	color: var(--color-text-lighter);
	font-family: monospace;
}

.changeDetails {
	margin-top: 0.75rem;
	margin-left: 2rem;
	padding-top: 0.75rem;
	border-top: 1px solid var(--color-border);
}

.changeDetailRow {
	margin: 0.25rem 0;
	font-size: 0.9em;
}

.changesDiff {
	margin-top: 0.5rem;
}

.changesDiff ul {
	margin: 0.5rem 0;
	padding-left: 1.5rem;
}

.changesDiff li {
	margin: 0.25rem 0;
	font-size: 0.85em;
}

.changesDiff code {
	background-color: var(--color-background-dark);
	padding: 2px 4px;
	border-radius: 3px;
	font-family: monospace;
}

.diffOld {
	color: var(--color-error);
	text-decoration: line-through;
}

.diffNew {
	color: var(--color-success);
	font-weight: 500;
}

.changeReason {
	margin: 0.5rem 0 0 2rem;
	font-size: 0.85em;
	color: var(--color-text-lighter);
	font-style: italic;
}
</style>
