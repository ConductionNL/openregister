<script setup>
import { schemaStore, navigationStore, groupStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="schemaStore.schemaItem?.id && !createAnother ? 'Edit Schema' : 'Add Schema'"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Schema successfully {{ schemaStore.schemaItem?.id && !createAnother ? 'updated' : 'created' }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="createAnother || !success" class="formContainer">
			<BTabs v-model="activeTab" content-class="mt-3" justified>
				<BTab title="Properties" active>
					<div class="form-editor">
						<NcTextField :disabled="loading"
							label="Title *"
							:value.sync="schemaItem.title" />
						<NcTextArea :disabled="loading"
							label="Description"
							:value.sync="schemaItem.description" />
						<NcTextArea :disabled="loading"
							label="Summary"
							:value.sync="schemaItem.summary" />
						<NcTextField :disabled="loading"
							label="Slug"
							:value.sync="schemaItem.slug" />
						<NcCheckboxRadioSwitch
							:disabled="loading"
							:checked.sync="schemaItem.hardValidation">
							Hard Validation
						</NcCheckboxRadioSwitch>
						<NcTextField :disabled="loading"
							label="Max Depth"
							type="number"
							:value.sync="schemaItem.maxDepth" />
					</div>
				</BTab>
				<BTab title="Configuration">
					<div class="form-editor">
						<NcCheckboxRadioSwitch
							:disabled="loading"
							:checked.sync="schemaItem.immutable">
							Immutable
						</NcCheckboxRadioSwitch>
						<NcSelect :disabled="loading"
							label="Object Name Field"
							:value.sync="schemaItem.configuration.objectNameField"
							:options="propertyOptions"
							placeholder="Select a property" />
						<NcSelect :disabled="loading"
							label="Object Description Field"
							:value.sync="schemaItem.configuration.objectDescriptionField"
							:options="propertyOptions"
							placeholder="Select a property" />
					</div>
				</BTab>
				<BTab title="Security">
					<div class="form-editor">
						<p>Define group-based permissions for this schema. If no group is selected for an action, all users will have permission.</p>
						<NcSelectTags
							label="Create"
							:value.sync="schemaItem.groups.create"
							:options="groupOptions"
							placeholder="Select groups for create access" />
						<NcSelectTags
							label="Read"
							:value.sync="schemaItem.groups.read"
							:options="groupOptions"
							placeholder="Select groups for read access" />
						<NcSelectTags
							label="Update"
							:value.sync="schemaItem.groups.update"
							:options="groupOptions"
							placeholder="Select groups for update access" />
						<NcSelectTags
							label="Delete"
							:value.sync="schemaItem.groups.delete"
							:options="groupOptions"
							placeholder="Select groups for delete access" />
					</div>
				</BTab>
			</BTabs>
			<NcCheckboxRadioSwitch
				v-if="!schemaStore.schemaItem?.id"
				:disabled="loading"
				:checked.sync="createAnother">
				Create another
			</NcCheckboxRadioSwitch>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="createAnother ||!success"
				:disabled="loading || !schemaItem.title"
				type="primary"
				@click="editSchema()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && schemaStore.schemaItem?.id" :size="20" />
					<Plus v-if="!loading && !schemaStore.schemaItem?.id" :size="20" />
				</template>
				{{ schemaStore.schemaItem?.id && !createAnother ? 'Save' : 'Create' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcTextArea,
	NcLoadingIcon,
	NcNoteCard,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcSelectTags,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'EditSchema',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcSelectTags,
		BTabs,
		BTab,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
	},
	data() {
		return {
			activeTab: 0,
			schemaItem: {
				title: '',
				version: '0.0.0',
				description: '',
				summary: '',
				slug: '',
				hardValidation: false,
				immutable: false,
				maxDepth: 0,
				configuration: {
					objectNameField: '',
					objectDescriptionField: '',
				},
				groups: {
					create: [],
					read: [],
					update: [],
					delete: [],
				},
			},
			createAnother: false,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	mounted() {
		this.initializeSchemaItem()
		groupStore.fetchGroups()
	},
	computed: {
		propertyOptions() {
			if (!schemaStore.schemaItem?.properties) {
				return []
			}
			return Object.keys(schemaStore.schemaItem.properties).map(prop => ({
				value: prop,
				label: schemaStore.schemaItem.properties[prop].title || prop,
			}))
		},
		groupOptions() {
			return groupStore.groups.map(group => ({
				value: group.id,
				label: group.name,
			}))
		},
	},
	methods: {
		initializeSchemaItem() {
			if (schemaStore.schemaItem?.id) {
				this.schemaItem = {
					...schemaStore.schemaItem,
					title: schemaStore.schemaItem.title || '',
					description: schemaStore.schemaItem.description || '',
					summary: schemaStore.schemaItem.summary || '',
					slug: schemaStore.schemaItem.slug || '',
					hardValidation: schemaStore.schemaItem.hardValidation || false,
					immutable: schemaStore.schemaItem.immutable || false,
					maxDepth: schemaStore.schemaItem.maxDepth || 0,
					configuration: {
						objectNameField: schemaStore.schemaItem.configuration?.objectNameField || '',
						objectDescriptionField: schemaStore.schemaItem.configuration?.objectDescriptionField || '',
					},
					groups: {
						create: schemaStore.schemaItem.groups?.create || [],
						read: schemaStore.schemaItem.groups?.read || [],
						update: schemaStore.schemaItem.groups?.update || [],
						delete: schemaStore.schemaItem.groups?.delete || [],
					},
				}
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
		},
		async editSchema() {
			this.loading = true

			schemaStore.saveSchema({
				...this.schemaItem,
			}).then(({ response }) => {

				if (this.createAnother) {
					// since saveSchema populates the schema item, we need to clear it
					schemaStore.setSchemaItem(null)

					// clear the form after 0.5s
					setTimeout(() => {
						this.schemaItem = {
							title: '',
							version: '0.0.0',
							description: '',
							summary: '',
							slug: '',
							hardValidation: false,
							immutable: false,
							maxDepth: 0,
							configuration: {
								objectNameField: '',
								objectDescriptionField: '',
							},
							groups: {
								create: [],
								read: [],
								update: [],
								delete: [],
							},
						}
					}, 500)

					this.success = response.ok
					this.error = false

					// clear the success message after 2s
					setTimeout(() => {
						this.success = null
					}, 2000)
				} else {
					this.success = response.ok
					this.error = false
					response.ok && (this.closeModalTimeout = setTimeout(this.closeModal, 2000))
				}

			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while saving the schema'
			}).finally(() => {
				this.loading = false
			})
		},
	},
}
</script>

<style scoped>
/* Add tab container styles */
.tabContainer {
	margin-top: 20px;
}

/* Style the tabs to match ViewObject */
:deep(.nav-tabs) {
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 15px;
}

:deep(.nav-tabs .nav-link) {
	border: none;
	border-bottom: 2px solid transparent;
	color: var(--color-text-maxcontrast);
	padding: 8px 16px;
}

:deep(.nav-tabs .nav-link.active) {
	color: var(--color-main-text);
	border-bottom: 2px solid var(--color-primary);
	background-color: transparent;
}

:deep(.nav-tabs .nav-link:hover) {
	border-bottom: 2px solid var(--color-border);
}

:deep(.tab-content) {
	padding-top: 16px;
}

/* Form editor specific styles */
.form-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
}
</style>
