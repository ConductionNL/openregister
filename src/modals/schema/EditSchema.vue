<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="schemaStore.schemaItem?.id && !createAnother ? 'Edit Schema' : 'Add Schema'"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>Schema successfully {{ schemaStore.schemaItem?.id && !createAnother ? 'updated' : 'created' }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<div v-if="createAnother || !success">
			<!-- Metadata Display -->
			<div class="detail-grid">
				<div v-if="schemaItem.id" class="detail-item id-card">
					<div class="id-card-header">
						<span class="detail-label">ID / UUID:</span>
						<NcButton class="copy-button" @click="copyToClipboard(schemaItem.uuid || schemaItem.id)">
							<template #icon>
								<Check v-if="isCopied" :size="20" />
								<ContentCopy v-else :size="20" />
							</template>
							{{ isCopied ? 'Copied' : 'Copy' }}
						</NcButton>
					</div>
					<span class="detail-value">{{ schemaItem.id }}</span>
					<span v-if="schemaItem.uuid && schemaItem.uuid !== schemaItem.id" class="detail-value uuid-value">{{ schemaItem.uuid }}</span>
				</div>
				<div class="detail-item">
					<NcTextField :disabled="loading"
						label="Title *"
						:value.sync="schemaItem.title" />
				</div>
				<div v-if="schemaItem.created" class="detail-item">
					<span class="detail-label">Created:</span>
					<span class="detail-value">{{ new Date(schemaItem.created).toLocaleString() }}</span>
				</div>
				<div v-if="schemaItem.updated" class="detail-item">
					<span class="detail-label">Updated:</span>
					<span class="detail-value">{{ new Date(schemaItem.updated).toLocaleString() }}</span>
				</div>
				<div class="detail-item">
					<span class="detail-label">Version:</span>
					<span class="detail-value">{{ schemaItem.version || 'Not set' }}</span>
				</div>
				<div class="detail-item">
					<span class="detail-label">Owner:</span>
					<span class="detail-value">{{ schemaItem.owner || 'Not set' }}</span>
				</div>
			</div>

			<div class="tabContainer">
				<BTabs v-model="activeTab" content-class="mt-3" justified>
					<BTab title="Properties" active>
						<div class="viewTableContainer">
							<table class="viewTable">
								<thead>
									<tr>
										<th>{{ t('openregister', 'Name') }}</th>
										<th>{{ t('openregister', 'Type') }}</th>
										<th>
											<NcButton
												type="primary"
												:disabled="loading"
												@click="addProperty">
												<template #icon>
													<Plus :size="20" />
												</template>
												{{ t('openregister', 'Add property') }}
											</NcButton>
										</th>
									</tr>
								</thead>
								<tbody>
									<template v-for="(property, key) in sortedProperties(schemaItem)">
										<tr :key="`property-${getStablePropertyId(key)}`"
											:class="{ 'selected-row': selectedProperty === key, 'modified-row': isPropertyModified(key) }"
											@click="handleRowClick(key, $event)">
											<td>
												<div v-if="selectedProperty === key" class="name-input-container" @click.stop>
													<AlertOutline v-if="isPropertyModified(key)"
														:size="16"
														class="warning-icon"
														:title="'Property has been modified. Changes will only take effect after the schema is saved.'" />
													<NcTextField
														ref="propertyNameInput"
														:value="key"
														label="Property Name"
														@update:value="updatePropertyKey(key, $event)"
														@click.stop />
												</div>
												<div v-else class="name-display-container">
													<AlertOutline v-if="isPropertyModified(key)"
														:size="16"
														class="warning-icon"
														:title="'Property has been modified. Changes will only take effect after the schema is saved.'" />
													<div class="name-with-chips">
														<span class="property-name">{{ key }}</span>
														<div class="inline-chips">
															<span v-if="isPropertyRequired(schemaItem, key)"
																class="property-chip chip-primary">Required</span>
															<span v-if="property.immutable"
																class="property-chip chip-secondary">Immutable</span>
															<span v-if="property.deprecated"
																class="property-chip chip-warning">Deprecated</span>
															<span v-if="property.visible === false"
																class="property-chip chip-secondary">Hidden in view</span>
															<span v-if="property.hideOnCollection"
																class="property-chip chip-secondary">Hidden in Collection</span>
															<span v-if="property.const !== undefined"
																class="property-chip chip-success">Constant</span>
															<span v-if="property.enum && property.enum.length > 0"
																class="property-chip chip-success">Enumeration</span>
														</div>
													</div>
												</div>
											</td>
											<td>
												<NcSelect
													v-if="selectedProperty === key"
													v-model="schemaItem.properties[key].type"
													:options="typeOptionsForSelect"
													input-label="Property Type"
													@click.stop />
												<span v-else>{{ property.type }}</span>
											</td>
											<td class="tableColumnActions">
												<NcActions>
													<NcActionCaption name="General" />
													<NcActionCheckbox
														:checked="isPropertyRequired(schemaItem, key)"
														@update:checked="updatePropertyRequired(key, $event)">
														Required
													</NcActionCheckbox>
													<NcActionCheckbox
														:checked="property.immutable || false"
														@update:checked="updatePropertySetting(key, 'immutable', $event)">
														Immutable
													</NcActionCheckbox>
													<NcActionCheckbox
														:checked="property.deprecated || false"
														@update:checked="updatePropertySetting(key, 'deprecated', $event)">
														Deprecated
													</NcActionCheckbox>
													<NcActionCheckbox
														:checked="property.visible !== false"
														@update:checked="updatePropertySetting(key, 'visible', $event)">
														Visible to end users
													</NcActionCheckbox>
													<NcActionCheckbox
														:checked="property.hideOnCollection || false"
														@update:checked="updatePropertySetting(key, 'hideOnCollection', $event)">
														Hide in collection view
													</NcActionCheckbox>

													<NcActionSeparator />
													<NcActionCaption name="Properties" />
													<NcActionInput
														v-if="getFormatOptionsForType(property.type).length > 0"
														v-model="schemaItem.properties[key].format"
														type="multiselect"
														:options="getFormatOptionsForType(property.type)"
														label="Format" />
													<NcActionInput
														:value="property.description || ''"
														label="Description"
														@update:value="updatePropertySetting(key, 'description', $event)" />
													<NcActionInput
														:value="property.example || ''"
														label="Example"
														@update:value="updatePropertySetting(key, 'example', $event)" />
													<NcActionInput
														:value="property.order || 0"
														type="number"
														label="Order"
														@update:value="updatePropertySetting(key, 'order', Number($event))" />

													<!-- Const and Enum Configuration -->
													<NcActionSeparator />
													<NcActionCaption name="Value Constraints" />
													<NcActionInput
														:value="property.const || ''"
														label="Constant"
														@update:value="updatePropertySetting(key, 'const', $event === '' ? undefined : $event)" />
													<div v-if="property.enum && property.enum.length > 0" class="enum-section">
														<div class="enum-values">
															<span
																v-for="(enumValue, index) in property.enum"
																:key="index"
																class="enum-chip">
																{{ String(enumValue) }}
																<button class="enum-remove" @click="removeEnumValue(key, index)">×</button>
															</span>
														</div>
													</div>
													<NcActionInput
														:value="''"
														label="Enumeration"
														placeholder="Type value and press Enter"
														@keydown.enter="addEnumValue(key, $event.target.value); $event.target.value = ''" />

													<!-- Type-specific configurations -->
													<template v-if="property.type === 'string'">
														<NcActionSeparator />
														<NcActionCaption name="String Configuration" />
														<NcActionInput
															:value="property.minLength || 0"
															type="number"
															label="Minimum Length"
															@update:value="updatePropertySetting(key, 'minLength', Number($event))" />
														<NcActionInput
															:value="property.maxLength || 0"
															type="number"
															label="Maximum Length"
															@update:value="updatePropertySetting(key, 'maxLength', Number($event))" />
														<NcActionInput
															:value="property.pattern || ''"
															label="Pattern (regex)"
															@update:value="updatePropertySetting(key, 'pattern', $event)" />
													</template>

													<template v-if="property.type === 'number' || property.type === 'integer'">
														<NcActionSeparator />
														<NcActionCaption name="Number Configuration" />
														<NcActionInput
															:value="property.minimum || 0"
															type="number"
															label="Minimum Value"
															@update:value="updatePropertySetting(key, 'minimum', Number($event))" />
														<NcActionInput
															:value="property.maximum || 0"
															type="number"
															label="Maximum Value"
															@update:value="updatePropertySetting(key, 'maximum', Number($event))" />
														<NcActionInput
															:value="property.multipleOf || 0"
															type="number"
															label="Multiple Of"
															@update:value="updatePropertySetting(key, 'multipleOf', Number($event))" />
														<NcActionCheckbox
															:checked="property.exclusiveMin || false"
															@update:checked="updatePropertySetting(key, 'exclusiveMin', $event)">
															Exclusive Minimum
														</NcActionCheckbox>
														<NcActionCheckbox
															:checked="property.exclusiveMax || false"
															@update:checked="updatePropertySetting(key, 'exclusiveMax', $event)">
															Exclusive Maximum
														</NcActionCheckbox>
													</template>

													<template v-if="property.type === 'array'">
														<NcActionSeparator />
														<NcActionCaption name="Array Configuration" />
														<NcActionInput
															v-model="schemaItem.properties[key].items.type"
															type="multiselect"
															:options="[
																{ id: 'string', label: 'String' },
																{ id: 'number', label: 'Number' },
																{ id: 'integer', label: 'Integer' },
																{ id: 'object', label: 'Object' },
																{ id: 'boolean', label: 'Boolean' }
															]"
															label="Array Item Type" />
														<NcActionInput
															:value="property.minItems || 0"
															type="number"
															label="Minimum Items"
															@update:value="updatePropertySetting(key, 'minItems', Number($event))" />
														<NcActionInput
															:value="property.maxItems || 0"
															type="number"
															label="Maximum Items"
															@update:value="updatePropertySetting(key, 'maxItems', Number($event))" />
													</template>

													<template v-if="property.type === 'object'">
														<NcActionSeparator />
														<NcActionCaption name="Object Configuration" />
														<NcActionInput
															v-model="schemaItem.properties[key].objectConfiguration.handling"
															type="multiselect"
															:options="[
																{ id: 'nested-object', label: 'Nested Object' },
																{ id: 'nested-schema', label: 'Nested Schema' },
																{ id: 'related-schema', label: 'Related Schema' },
																{ id: 'uri', label: 'URI' }
															]"
															label="Object Handling" />
														<NcActionInput
															:value="property.$ref || ''"
															label="Schema Reference"
															@update:value="updatePropertySetting(key, '$ref', $event)" />
														<NcActionInput
															:value="property.inversedBy || ''"
															label="Inversed By"
															@update:value="updatePropertySetting(key, 'inversedBy', $event)" />
														<NcActionCheckbox
															:checked="property.cascadeDelete || false"
															@update:checked="updatePropertySetting(key, 'cascadeDelete', $event)">
															Cascade Delete
														</NcActionCheckbox>
													</template>

													<NcActionSeparator />
													<NcActionCaption name="Actions" />
													<NcActionButton :aria-label="'Copy ' + key" @click="copyProperty(key)">
														<template #icon>
															<ContentCopy :size="16" />
														</template>
														Copy Property
													</NcActionButton>
													<NcActionButton :aria-label="'Delete ' + key" @click="deleteProperty(key)">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														Delete Property
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
									</template>
									<tr v-if="!Object.keys(schemaItem.properties || {}).length">
										<td colspan="3">
											No properties found. Click "Add property" to create one.
										</td>
									</tr>
								</tbody>
							</table>
						</div>
						<NcNoteCard v-if="propertiesModified && !loading" type="warning" class="properties-warning">
							<p>Properties have been modified. Changes will only take effect after the schema is saved.</p>
						</NcNoteCard>
					</BTab>
					<BTab title="Configuration">
						<div class="form-editor">
							<NcTextArea :disabled="loading"
								label="Description"
								:value.sync="schemaItem.description" />
							<NcTextArea :disabled="loading"
								label="Summary"
								:value.sync="schemaItem.summary" />
							<NcTextField :disabled="loading"
								label="Slug"
								:value.sync="schemaItem.slug" />
							<NcSelect
								v-model="schemaItem.configuration.objectNameField"
								:disabled="loading"
								:options="propertyOptions"
								label="Object Name Field"
								placeholder="Select a property to use as object name" />
							<NcSelect
								v-model="schemaItem.configuration.objectDescriptionField"
								:disabled="loading"
								:options="propertyOptions"
								label="Object Description Field"
								placeholder="Select a property to use as object description" />
							<NcCheckboxRadioSwitch
								:disabled="loading"
								:checked.sync="schemaItem.hardValidation">
								Hard Validation
							</NcCheckboxRadioSwitch>
							<NcTextField :disabled="loading"
								label="Max Depth"
								type="number"
								:value.sync="schemaItem.maxDepth" />
							<NcCheckboxRadioSwitch
								:disabled="loading"
								:checked.sync="schemaItem.immutable">
								Immutable
							</NcCheckboxRadioSwitch>
						</div>
					</BTab>
					<BTab title="Security">
						<NcNoteCard type="info">
							<p>Security options for schemas are not yet implemented.</p>
						</NcNoteCard>
					</BTab>
				</BTabs>
			</div>
		</div>

		<template #actions>
			<NcCheckboxRadioSwitch
				v-if="!schemaStore.schemaItem?.id"
				class="create-another-checkbox"
				:disabled="loading"
				:checked.sync="createAnother">
				Create another
			</NcCheckboxRadioSwitch>
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
	NcActions,
	NcActionButton,
	NcActionCheckbox,
	NcActionInput,
	NcActionCaption,
	NcActionSeparator,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'

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
		NcActions,
		NcActionButton,
		NcActionCheckbox,
		NcActionInput,
		NcActionCaption,
		NcActionSeparator,
		BTabs,
		BTab,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
		ContentCopy,
		Check,
		TrashCanOutline,
		AlertOutline,
	},
	data() {
		return {
			activeTab: 0,
			isCopied: false,
			selectedProperty: null,
			propertiesModified: false,
			originalProperties: null,
			propertyStableIds: {}, // Map property names to stable IDs
			nextPropertyId: 1, // Counter for generating unique IDs
			schemaItem: {
				title: '',
				version: '0.0.0',
				description: '',
				summary: '',
				slug: '',
				properties: {},
				configuration: {
					objectNameField: '',
					objectDescriptionField: '',
				},
				hardValidation: false,
				immutable: false,
				maxDepth: 0,
			},
			createAnother: false,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
			typeOptions: [
				{ label: 'String', value: 'string' },
				{ label: 'Number', value: 'number' },
				{ label: 'Integer', value: 'integer' },
				{ label: 'Boolean', value: 'boolean' },
				{ label: 'Array', value: 'array' },
				{ label: 'Object', value: 'object' },
			],
		}
	},
	computed: {
		sortedProperties() {
			return (schema) => {
				const properties = schema.properties || {}
				return Object.entries(properties)
					.sort(([keyA, propA], [keyB, propB]) => {
						const orderA = propA.order || 0
						const orderB = propB.order || 0
						if (orderA > 0 && orderB > 0) {
							return orderA - orderB
						}
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
			}
		},
		typeOptionsForSelect() {
			return [
				{ id: 'string', label: 'String' },
				{ id: 'number', label: 'Number' },
				{ id: 'integer', label: 'Integer' },
				{ id: 'boolean', label: 'Boolean' },
				{ id: 'array', label: 'Array' },
				{ id: 'object', label: 'Object' },
				{ id: 'dictionary', label: 'Dictionary' },
				{ id: 'file', label: 'File' },
				{ id: 'oneOf', label: 'One Of' },
			]
		},
		propertyOptions() {
			const properties = this.schemaItem.properties || {}
			const options = Object.keys(properties).map(key => ({
				id: key,
				label: key,
			}))
			// Add empty option at the beginning
			return [{ id: '', label: 'None' }, ...options]
		},

	},
	watch: {
		'schemaItem.properties': {
			handler(newProperties) {
				// Convert any object values back to strings for multiselect fields
				if (newProperties) {
					Object.keys(newProperties).forEach(key => {
						const property = newProperties[key]
						if (property) {
							// Initialize nested objects if they don't exist
							if (property.type === 'array' && !property.items) {
								this.$set(this.schemaItem.properties[key], 'items', { type: 'string' })
							}
							if (property.type === 'object' && !property.objectConfiguration) {
								this.$set(this.schemaItem.properties[key], 'objectConfiguration', { handling: 'nested-object' })
							}

							// Convert property type from object to string
							if (property.type && typeof property.type === 'object' && property.type.id) {
								this.$set(this.schemaItem.properties[key], 'type', property.type.id)
							}

							// Convert property format from object to string
							if (property.format && typeof property.format === 'object' && property.format.id) {
								this.$set(this.schemaItem.properties[key], 'format', property.format.id)
							}

							// Convert array item type from object to string
							if (property.items && property.items.type && typeof property.items.type === 'object' && property.items.type.id) {
								this.$set(this.schemaItem.properties[key].items, 'type', property.items.type.id)
							}

							// Convert object handling from object to string
							if (property.objectConfiguration && property.objectConfiguration.handling
								&& typeof property.objectConfiguration.handling === 'object' && property.objectConfiguration.handling.id) {
								this.$set(this.schemaItem.properties[key].objectConfiguration, 'handling', property.objectConfiguration.handling.id)
							}
						}
					})
				}
				this.checkPropertiesModified()
			},
			deep: true,
		},
		// Convert objectNameField and objectDescriptionField from object to string
		'schemaItem.configuration.objectNameField': {
			handler(newValue) {
				if (newValue && typeof newValue === 'object' && newValue.id) {
					this.schemaItem.configuration.objectNameField = newValue.id
				}
			},
		},
		'schemaItem.configuration.objectDescriptionField': {
			handler(newValue) {
				if (newValue && typeof newValue === 'object' && newValue.id) {
					this.schemaItem.configuration.objectDescriptionField = newValue.id
				}
			},
		},
	},
	mounted() {
		this.initializeSchemaItem()
	},
	methods: {
		// Generate or get stable ID for a property
		getStablePropertyId(propertyName) {
			if (!this.propertyStableIds[propertyName]) {
				this.propertyStableIds[propertyName] = this.nextPropertyId++
			}
			return this.propertyStableIds[propertyName]
		},

		isPropertyRequired(schema, key) {
			return schema.required && schema.required.includes(key)
		},
		initializeSchemaItem() {
			if (schemaStore.schemaItem?.id) {
				this.schemaItem = {
					...this.schemaItem, // Keep default structure
					...schemaStore.schemaItem,
				}

				// Ensure configuration object exists and has the required structure
				if (!this.schemaItem.configuration) {
					this.schemaItem.configuration = {
						objectNameField: '',
						objectDescriptionField: '',
					}
				} else {
					// Ensure all configuration fields exist
					if (!this.schemaItem.configuration.objectNameField) {
						this.schemaItem.configuration.objectNameField = ''
					}
					if (!this.schemaItem.configuration.objectDescriptionField) {
						this.schemaItem.configuration.objectDescriptionField = ''
					}
				}

				// Store original properties for comparison
				this.originalProperties = JSON.parse(JSON.stringify(this.schemaItem.properties || {}))
			} else {
				this.originalProperties = {}
			}
			this.propertiesModified = false
		},
		checkPropertiesModified() {
			if (!this.originalProperties) return false

			const currentProperties = JSON.stringify(this.schemaItem.properties || {})
			const originalProperties = JSON.stringify(this.originalProperties)

			this.propertiesModified = currentProperties !== originalProperties
		},
		isPropertyModified(key) {
			if (!this.originalProperties) return false

			const currentProperty = JSON.stringify(this.schemaItem.properties[key] || {})
			const originalProperty = JSON.stringify(this.originalProperties[key] || {})

			return currentProperty !== originalProperty
		},
		async copyToClipboard(text) {
			try {
				await navigator.clipboard.writeText(text)
				this.isCopied = true
				setTimeout(() => { this.isCopied = false }, 2000)
			} catch (err) {
				console.error('Failed to copy text:', err)
			}
		},
		addProperty() {
			// Generate a unique property name
			let newPropertyName = 'new'
			let counter = 1

			while (this.schemaItem.properties[newPropertyName]) {
				counter++
				newPropertyName = `new_${counter}`
			}

			// Add the new property with default values
			this.$set(this.schemaItem.properties, newPropertyName, {
				type: 'string',
				format: '',
				title: newPropertyName,
				description: '',
			})

			// Ensure stable ID is created for the new property
			this.getStablePropertyId(newPropertyName)

			// Check if properties have been modified
			this.checkPropertiesModified()

			// Select the new property for editing
			this.selectedProperty = newPropertyName

			// Focus the input field after Vue updates the DOM
			this.$nextTick(() => {
				if (this.$refs.propertyNameInput && this.$refs.propertyNameInput[0]) {
					this.$refs.propertyNameInput[0].$el.querySelector('input').focus()
					this.$refs.propertyNameInput[0].$el.querySelector('input').select()
				}
			})
		},
		handleRowClick(key, event) {
			// Don't select if clicking on an input or button
			if (event.target.tagName === 'INPUT' || event.target.tagName === 'BUTTON' || event.target.closest('.name-input-container')) {
				return
			}

			// Don't deselect if already selected
			if (this.selectedProperty === key) {
				return
			}

			this.selectProperty(key)
		},
		selectProperty(key) {
			this.selectedProperty = key

			// Focus the input field if selecting a property for editing
			if (key) {
				this.$nextTick(() => {
					if (this.$refs.propertyNameInput && this.$refs.propertyNameInput[0]) {
						this.$refs.propertyNameInput[0].$el.querySelector('input').focus()
						this.$refs.propertyNameInput[0].$el.querySelector('input').select()
					}
				})
			}
		},
		updatePropertyKey(oldKey, newKey) {
			// Don't update if the key hasn't changed or is empty
			if (!newKey || newKey === oldKey) {
				return
			}

			// Don't allow duplicate keys
			if (this.schemaItem.properties[newKey] && newKey !== oldKey) {
				return
			}

			// Get the property data first
			const propertyData = {
				...this.schemaItem.properties[oldKey],
				title: newKey, // Update title to match new key
			}

			// Transfer the stable ID from old key to new key
			if (this.propertyStableIds[oldKey]) {
				this.propertyStableIds[newKey] = this.propertyStableIds[oldKey]
				delete this.propertyStableIds[oldKey]
			}

			// Use Vue.set to add the new property and Vue.delete to remove the old one
			// This maintains reactivity without recreating the entire object
			this.$set(this.schemaItem.properties, newKey, propertyData)
			this.$delete(this.schemaItem.properties, oldKey)

			this.selectedProperty = newKey // Update selected property to new key

			// Check if properties have been modified
			this.checkPropertiesModified()

			// Ensure the input field stays focused after the update
			this.$nextTick(() => {
				if (this.$refs.propertyNameInput && this.$refs.propertyNameInput[0]) {
					const input = this.$refs.propertyNameInput[0].$el.querySelector('input')
					if (input) {
						input.focus()
						// Set cursor to end of text
						input.setSelectionRange(input.value.length, input.value.length)
					}
				}
			})
		},
		updatePropertyType(key, newType) {
			if (this.schemaItem.properties[key]) {
				// Handle both string values and objects with id property
				const typeValue = typeof newType === 'object' && newType?.id ? newType.id : newType

				this.$set(this.schemaItem.properties[key], 'type', typeValue)
				this.checkPropertiesModified()
			}
		},
		updatePropertyFormat(key, newFormat) {
			if (this.schemaItem.properties[key]) {
				this.$set(this.schemaItem.properties[key], 'format', newFormat)
				this.checkPropertiesModified()
			}
		},
		deleteProperty(key) {
			// Remove the property from the schema
			this.$delete(this.schemaItem.properties, key)

			// Clear selection if deleted property was selected
			if (this.selectedProperty === key) {
				this.selectedProperty = null
			}

			// Check if properties have been modified
			this.checkPropertiesModified()
		},
		closeModal() {
			this.success = false
			this.error = null
			this.createAnother = false
			navigationStore.setModal(false)
			navigationStore.setDialog(false)
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
							properties: {},
							configuration: {
								objectNameField: '',
								objectDescriptionField: '',
							},
							hardValidation: false,
							immutable: false,
							maxDepth: 0,
						}
						this.originalProperties = {}
						this.propertiesModified = false
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

					if (response.ok) {
						// Reset properties tracking after successful save
						this.originalProperties = JSON.parse(JSON.stringify(this.schemaItem.properties || {}))
						this.propertiesModified = false
						this.closeModalTimeout = setTimeout(this.closeModal, 2000)
					}
				}

			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while saving the schema'
			}).finally(() => {
				this.loading = false
			})
		},
		handleDialogClose() {
			this.closeModal()
		},
		// New methods for the action menu functionality
		getFormatOptionsForType(type) {
			const formatMap = {
				string: [
					{ id: 'date', label: 'Date' },
					{ id: 'time', label: 'Time' },
					{ id: 'duration', label: 'Duration' },
					{ id: 'date-time', label: 'Date Time' },
					{ id: 'url', label: 'URL' },
					{ id: 'uri', label: 'URI' },
					{ id: 'uuid', label: 'UUID' },
					{ id: 'email', label: 'Email' },
					{ id: 'idn-email', label: 'IDN Email' },
					{ id: 'hostname', label: 'Hostname' },
					{ id: 'idn-hostname', label: 'IDN Hostname' },
					{ id: 'ipv4', label: 'IPv4' },
					{ id: 'ipv6', label: 'IPv6' },
					{ id: 'uri-reference', label: 'URI Reference' },
					{ id: 'iri', label: 'IRI' },
					{ id: 'iri-reference', label: 'IRI Reference' },
					{ id: 'uri-template', label: 'URI Template' },
					{ id: 'json-pointer', label: 'JSON Pointer' },
					{ id: 'regex', label: 'Regex' },
					{ id: 'binary', label: 'Binary' },
					{ id: 'byte', label: 'Byte' },
					{ id: 'password', label: 'Password' },
					{ id: 'rsin', label: 'RSIN' },
					{ id: 'kvk', label: 'KVK' },
					{ id: 'bsn', label: 'BSN' },
					{ id: 'oidn', label: 'OIDN' },
					{ id: 'telephone', label: 'Telephone' },
					{ id: 'accessUrl', label: 'Access URL' },
					{ id: 'shareUrl', label: 'Share URL' },
					{ id: 'downloadUrl', label: 'Download URL' },
					{ id: 'extension', label: 'Extension' },
					{ id: 'filename', label: 'Filename' },
				],
				number: [],
				integer: [],
				boolean: [],
				array: [],
				object: [],
			}
			return formatMap[type] || []
		},
		updatePropertySetting(key, setting, value) {
			if (this.schemaItem.properties[key]) {
				// Handle both string values and objects with id property
				const settingValue = typeof value === 'object' && value?.id ? value.id : value
				this.$set(this.schemaItem.properties[key], setting, settingValue)
				this.checkPropertiesModified()
			}
		},
		updatePropertyRequired(key, isRequired) {
			if (!this.schemaItem.required) {
				this.$set(this.schemaItem, 'required', [])
			}

			const currentRequired = [...this.schemaItem.required]
			if (isRequired && !currentRequired.includes(key)) {
				currentRequired.push(key)
			} else if (!isRequired && currentRequired.includes(key)) {
				const index = currentRequired.indexOf(key)
				currentRequired.splice(index, 1)
			}

			this.schemaItem.required = currentRequired
			this.checkPropertiesModified()
		},
		updateArrayItemType(key, itemType) {
			if (this.schemaItem.properties[key]) {
				if (!this.schemaItem.properties[key].items) {
					this.$set(this.schemaItem.properties[key], 'items', {})
				}
				// Handle both string values and objects with id property
				const typeValue = typeof itemType === 'object' && itemType?.id ? itemType.id : itemType
				this.$set(this.schemaItem.properties[key].items, 'type', typeValue)
				this.checkPropertiesModified()
			}
		},
		updateObjectConfiguration(key, setting, value) {
			if (this.schemaItem.properties[key]) {
				if (!this.schemaItem.properties[key].objectConfiguration) {
					this.$set(this.schemaItem.properties[key], 'objectConfiguration', {})
				}
				// Handle both string values and objects with id property
				const settingValue = typeof value === 'object' && value?.id ? value.id : value
				this.$set(this.schemaItem.properties[key].objectConfiguration, setting, settingValue)
				this.checkPropertiesModified()
			}
		},
		copyProperty(key) {
			if (this.schemaItem.properties[key]) {
				// Create a deep copy of the property
				const originalProperty = JSON.parse(JSON.stringify(this.schemaItem.properties[key]))

				// Generate a unique property name for the copy
				let newPropertyName = `${key}_copy`
				let counter = 1

				while (this.schemaItem.properties[newPropertyName]) {
					counter++
					newPropertyName = `${key}_copy_${counter}`
				}

				// Add the copied property with the new name
				this.$set(this.schemaItem.properties, newPropertyName, {
					...originalProperty,
					title: newPropertyName,
				})

				// Check if properties have been modified
				this.checkPropertiesModified()

				// Select the new property for editing
				this.selectedProperty = newPropertyName

				// Focus the input field after Vue updates the DOM
				this.$nextTick(() => {
					if (this.$refs.propertyNameInput && this.$refs.propertyNameInput[0]) {
						this.$refs.propertyNameInput[0].$el.querySelector('input').focus()
						this.$refs.propertyNameInput[0].$el.querySelector('input').select()
					}
				})
			}
		},
		addEnumValue(key, value) {
			if (!value || !value.trim()) return

			const trimmedValue = value.trim()

			if (this.schemaItem.properties[key]) {
				if (!this.schemaItem.properties[key].enum) {
					this.$set(this.schemaItem.properties[key], 'enum', [])
				}

				// Don't add duplicate values
				if (!this.schemaItem.properties[key].enum.includes(trimmedValue)) {
					this.schemaItem.properties[key].enum.push(trimmedValue)
					this.checkPropertiesModified()
				}
			}
		},
		removeEnumValue(key, index) {
			if (this.schemaItem.properties[key] && this.schemaItem.properties[key].enum) {
				this.schemaItem.properties[key].enum.splice(index, 1)

				// Remove the enum array if it's empty
				if (this.schemaItem.properties[key].enum.length === 0) {
					this.$delete(this.schemaItem.properties[key], 'enum')
				}

				this.checkPropertiesModified()
			}
		},
	},
}
</script>

<style scoped>
.detail-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
	gap: 20px;
	margin-bottom: 20px;
}

.detail-item {
	background-color: var(--color-background-soft);
	padding: 15px;
	border-radius: var(--border-radius);
	display: flex;
	flex-direction: column;
}

.detail-label {
	font-weight: bold;
	margin-bottom: 5px;
	color: var(--color-text-maxcontrast);
}

.detail-value {
	color: var(--color-text-default);
}

.id-card {
	background-color: var(--color-background-hover);
}

.id-card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	width: 100%;
}

.copy-button {
	height: 30px;
}

.uuid-value {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	margin-top: 4px;
	display: block;
}

.tabContainer {
	margin-top: 20px;
}

.viewTableContainer {
	max-height: 400px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.viewTable th {
	background-color: var(--color-background-soft);
}

.tableColumnActions {
	width: 150px;
	text-align: right;
}

/* Tab styling */
.tabContainer {
	margin-top: 20px;
}

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

.form-editor {
	display: grid;
	grid-template-columns: 1fr;
	gap: 15px;
	padding: 20px;
}

/* Table actions button */
.table-actions {
	margin-bottom: 15px;
	display: flex;
	justify-content: flex-end;
}

/* Row selection styling */
.viewTable tbody tr {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTable tbody tr:hover {
	background-color: var(--color-background-hover);
}

.viewTable tbody tr.selected-row {
	background-color: var(--color-primary-light);
	border-left: 3px solid var(--color-primary);
}

.viewTable tbody tr.selected-row:hover {
	background-color: var(--color-primary-light);
}

.viewTable tbody tr.modified-row {
	background-color: var(--color-warning-light);
	border-left: 3px solid var(--color-warning);
}

.viewTable tbody tr.modified-row:hover {
	background-color: var(--color-warning-light);
}

.viewTable tbody tr.modified-row.selected-row {
	background-color: var(--color-primary-light);
	border-left: 3px solid var(--color-primary);
}

:deep(.modal-container .modal-actions) {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 10px;
	width: 100%;
}

.create-another-checkbox {
	margin-right: auto;
}

/* Inline editing styles */
.viewTable td {
	vertical-align: middle;
}

.viewTable td span {
	cursor: pointer;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	transition: background-color 0.2s ease;
}

.viewTable td span:hover {
	background-color: var(--color-background-hover);
}

.viewTable .nc-text-field,
.viewTable .nc-select {
	width: 100%;
}

/* Make small form controls even smaller for inline editing */
.viewTable :deep(.nc-text-field--small input) {
	padding: 4px 8px;
	font-size: 0.9em;
}

.viewTable :deep(.nc-select--small .vs__dropdown-toggle) {
	padding: 2px 4px;
	min-height: 32px;
}

/* Warning icon and name container styles */
.name-input-container,
.name-display-container {
	display: flex;
	align-items: center;
	gap: 8px;
}

.warning-icon {
	color: var(--color-warning);
	flex-shrink: 0;
}

.properties-warning {
	margin-top: 15px;
}

/* Name with inline chips */
.name-with-chips {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.property-name {
	font-weight: 500;
}

.inline-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	align-items: center;
}

/* Nextcloud-style chips */
.property-chip {
	display: inline-block;
	padding: 2px 6px;
	border-radius: var(--border-radius-pill);
	font-size: 0.7em;
	font-weight: 500;
	line-height: 1.2;
	white-space: nowrap;
}

.chip-primary {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

.chip-secondary {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
}

.chip-warning {
	background-color: var(--color-warning);
	color: var(--color-main-background);
}

.chip-success {
	background-color: var(--color-success);
	color: var(--color-main-background);
}

/* Enum section in action menu */
.enum-section {
	padding: 8px 16px;
}

.enum-values {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 8px;
	max-width: 250px;
}

.enum-chip {
	display: inline-flex;
	align-items: center;
	padding: 4px 8px;
	background-color: var(--color-primary-light);
	color: var(--color-primary-text);
	border-radius: 12px;
	font-size: 0.8em;
	gap: 4px;
}

.enum-remove {
	background: none;
	border: none;
	color: var(--color-primary-text);
	cursor: pointer;
	font-size: 1.2em;
	line-height: 1;
	padding: 0;
	width: 16px;
	height: 16px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
}

.enum-remove:hover {
	background-color: rgba(255, 255, 255, 0.2);
}

/* Hide arrow icons and fix spacing in NcActionInput */
:deep(.action-input__icon-wrapper) {
	display: none !important;
}

/* Adjust input padding when icon wrapper is hidden */
:deep(.action-input .action-input__text-label) {
	padding-left: 16px !important;
}

:deep(.action-input input) {
	padding-left: 16px !important;
}

:deep(.action-input .vs__dropdown-toggle) {
	padding-left: 16px !important;
}

</style>
