<script setup>
import { navigationStore, schemaStore, registerStore } from '../../store/store.js'
</script>
<template>
	<NcDialog :name="schemaStore.schemaPropertyKey
			? `Edit Property '${schemaStore.schemaPropertyKey}' of '${schemaStore.schemaItem.title}'`
			: `Add Property to '${schemaStore.schemaItem?.title}'`"
		size="normal"
		:can-close="false">
		<div v-if="success !== null" class="form-group">
			<NcNoteCard v-if="success" type="success">
				<p>Property successfully {{ schemaStore.schemaPropertyKey ? 'updated' : 'added' }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="!success" type="error">
				<p>Property could not be {{ schemaStore.schemaPropertyKey ? 'updated' : 'added' }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>

		<div v-if="success === null" class="form-group">
			<NcTextField :disabled="loading"
				label="Title*"
				:error="keyExists()"
				:helper-text="keyExists() ? 'This key already exists on this schema' : ''"
				:value.sync="propertyTitle" />

			<NcTextField :disabled="loading"
				label="Description"
				:value.sync="properties.description" />

			<NcTextField :disabled="loading"
				label="Title"
				:value.sync="properties.title" />

			<div class="ASP-selectContainer">
				<NcSelect v-bind="typeOptions"
					v-model="properties.type" />

				<NcSelect
					v-bind="formatOptions"
					v-model="properties.format"
					:disabled="properties.type !== 'string'" />
			</div>
			<!-- TYPE : OBJECT -->
			<div v-if="properties.type === 'object'" class="objectConfigurationContainer">
				<div class="objectConfigurationTitle">
					Object Configuration:
				</div>
				<NcSelect
					v-model="properties.objectConfiguration.handling"
					v-bind="objectConfiguration.handling" />
				<NcSelect
					:disabled="loading"
					input-label="Register"
					label="Register"
					placeholder="Select a register..."
					:options="availableRegisters"
					:value="properties.register"
					@update:value="handleRegisterChange($event)" />
				<NcSelect
					:disabled="loading || !properties.register"
					input-label="Schema reference ($ref)"
					label="Schema reference ($ref)"
					placeholder="Select a schema..."
					:options="availableSchemas"
					:value="properties.$ref"
					@update:value="handleSchemaChange($event)" />
				<NcTextField
					:disabled="loading || !properties.$ref"
					label="Extra Query Parameters"
					:value.sync="properties.objectConfiguration.queryParams"
					placeholder="key1=value1&key2=value2"
					helper-text="Optional: Add query parameters to filter the referenced schema (e.g., status=active&type=public)" />
				<NcSelect
					:disabled="loading || !properties.$ref"
					v-bind="inversedByOptions"
					input-label="Property name of inversed relation"
					label="Property name of inversed relation"
					:model-value="properties.inversedBy"
					@update:model-value="handleInversedByChange" />
				<NcCheckboxRadioSwitch
					v-if="properties.inversedBy"
					:disabled="loading"
					:checked.sync="properties.writeBack">
					Enable write-back to target objects
				</NcCheckboxRadioSwitch>
				<div v-if="properties.inversedBy && !properties.writeBack" class="helper-text">
					When enabled, saving this object will also update the target objects to include a reference back to this object.
				</div>
				<NcCheckboxRadioSwitch
					v-if="properties.inversedBy && properties.writeBack"
					:disabled="loading"
					:checked.sync="properties.removeAfterWriteBack">
					Remove property after write-back
				</NcCheckboxRadioSwitch>
				<div v-if="properties.inversedBy && properties.writeBack && !properties.removeAfterWriteBack" class="helper-text">
					When enabled, this property will be removed from the source object after updating the target objects.
				</div>
			</div>

			<!-- File configuration -->
			<div v-if="properties.type === 'file'" class="ASP-selectContainer">
				<NcSelect
					v-bind="fileConfiguration.handling"
					v-model="properties.fileConfiguration.handling"
					label="File Handling" />
				<NcSelect
					v-model="properties.fileConfiguration.allowedMimeTypes"
					:options="mimeTypes"
					input-label="Allowed MIME Types"
					label="Allowed MIME Types"
					multiple />
				<NcTextField :disabled="loading"
					label="File Location"
					:value.sync="properties.fileConfiguration.location" />
				<NcInputField :disabled="loading"
					type="number"
					label="Maximum File Size (MB)"
					:value.sync="properties.fileConfiguration.maxSize" />
			</div>

			<template v-if="properties.type !== 'object' && properties.type !== 'file'">
				<NcTextField :disabled="loading"
					label="Pattern (regex)"
					:value.sync="properties.pattern" />

				<NcTextField :disabled="loading"
					label="Behavior"
					:value.sync="properties.behavior" />
				<template v-if="properties.type !== 'array'">
					<NcInputField :disabled="loading"
						type="number"
						label="Minimum length"
						:value.sync="properties.minLength" />

					<NcInputField :disabled="loading"
						type="number"
						label="Maximum length"
						:value.sync="properties.maxLength" />
				</template>
			</template>

			<!-- TYPE : STRING -->
			<div v-if="properties.type === 'string'">
				<NcDateTimePicker v-if="properties.format === 'date'"
					v-model="properties.default"
					type="date"
					label="Default value"
					:disabled="loading"
					:loading="loading" />

				<NcDateTimePicker v-else-if="properties.format === 'time'"
					v-model="properties.default"
					type="time"
					label="Default value"
					:disabled="loading"
					:loading="loading" />

				<NcDateTimePicker v-else-if="properties.format === 'date-time'"
					v-model="properties.default"
					type="datetime"
					label="Default value"
					:disabled="loading"
					:loading="loading" />

				<NcInputField v-else-if="properties.format === 'email'"
					:value.sync="properties.default"
					type="email"
					label="Default value (Email)"
					:disabled="loading"
					:loading="loading" />

				<NcInputField v-else-if="properties.format === 'idn-email'"
					:value.sync="properties.default"
					type="email"
					label="Default value (Email)"
					helper-text="email"
					:disabled="loading"
					:loading="loading" />

				<NcTextField v-else-if="properties.format === 'regex'"
					:value.sync="properties.default"
					label="Default value (Regex)"
					:disabled="loading"
					:loading="loading" />

				<NcInputField v-else-if="properties.format === 'password'"
					:value.sync="properties.default"
					type="password"
					label="Default value (Password)"
					:disabled="loading"
					:loading="loading" />

				<NcInputField v-else-if="properties.format === 'telephone'"
					:value.sync="properties.default"
					type="tel"
					label="Default value (Phone number)"
					:disabled="loading"
					:loading="loading" />

				<NcTextField v-else
					:value.sync="properties.default"
					label="Default value"
					:disabled="loading"
					:loading="loading" />
			</div>

			<!-- TYPE : NUMBER -->
			<NcInputField v-else-if="properties.type === 'number'"
				:disabled="loading"
				type="number"
				step="any"
				label="Default value"
				:value.sync="properties.default"
				:loading="loading" />
			<!-- TYPE : INTEGER -->
			<NcInputField v-else-if="properties.type === 'integer'"
				:disabled="loading"
				type="number"
				step="1"
				label="Default value"
				:value.sync="properties.default"
				:loading="loading" />
			<!-- TYPE : OBJECT -->
			<div v-else-if="properties.type === 'object'">
				<NcTextArea
					:disabled="loading"
					label="Default value"
					:value.sync="properties.default"
					:loading="loading"
					:error="!verifyJsonValidity(properties.default)"
					:helper-text="!verifyJsonValidity(properties.default) ? 'This is not valid JSON' : ''" />

				<NcCheckboxRadioSwitch
					:disabled="loading"
					:checked.sync="properties.cascadeDelete">
					Cascade delete
				</NcCheckboxRadioSwitch>
			</div>

			<!-- TYPE : ARRAY -->
			<NcTextArea v-else-if="properties.type === 'array'"
				:disabled="loading"
				label="Value list (split on ,)"
				:value.sync="properties.default"
				:loading="loading" />
			<!-- TYPE : BOOLEAN -->
			<NcCheckboxRadioSwitch v-else-if="properties.type === 'boolean'"
				:disabled="loading"
				:checked.sync="properties.default"
				:loading="loading">
				Default value
			</NcCheckboxRadioSwitch>
			<!-- TYPE : dictionary -->
			<NcTextField v-else-if="properties.type === 'dictionary'"
				:disabled="loading"
				label="Default value"
				:value.sync="properties.default" />

			<NcInputField :disabled="loading"
				type="number"
				label="Order"
				:value.sync="properties.order" />

			<NcCheckboxRadioSwitch
				:disabled="loading"
				:checked.sync="properties.required">
				Required
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				:disabled="loading"
				:checked.sync="properties.immutable">
				Immutable
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				:disabled="loading"
				:checked.sync="properties.deprecated">
				Deprecated
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				:disabled="loading"
				:checked.sync="properties.visible">
				Visible to end users
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				:disabled="loading"
				:checked.sync="properties.hideOnCollection">
				Hide in collection view
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				:disabled="loading"
				:checked.sync="properties.facetable">
				Facetable
			</NcCheckboxRadioSwitch>

			<NcTextField :disabled="loading"
				label="Example"
				:value.sync="properties.example" />

			<!-- type integer and number only -->
			<div v-if="properties.type === 'integer' || properties.type === 'number'">
				<h5 class="weightNormal">
					type: number
				</h5>

				<NcInputField :disabled="loading"
					type="number"
					label="Minimum value"
					:value.sync="properties.minimum" />

				<NcInputField :disabled="loading"
					type="number"
					label="Maximum value"
					:value.sync="properties.maximum" />

				<NcInputField :disabled="loading"
					type="number"
					label="Multiple of"
					:value.sync="properties.multipleOf" />

				<NcCheckboxRadioSwitch
					:disabled="loading"
					:checked.sync="properties.exclusiveMin">
					Exclusive minimum
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:disabled="loading"
					:checked.sync="properties.exclusiveMax">
					Exclusive maximum
				</NcCheckboxRadioSwitch>
			</div>

			<!-- type array only -->
			<div v-if="properties.type === 'array'">
				<h5 class="weightNormal">
					type: array
				</h5>

				<div class="ASP-selectContainer">
					<NcSelect v-bind="itemsTypeOptions"
						v-model="properties.items.type" />
				</div>

				<!-- type array and sub type object only -->
				<div v-if="properties.items.type === 'object'">
					<div class="objectConfigurationTitle">
						Array Object Configuration:
					</div>
					<NcSelect
						v-model="properties.objectConfiguration.handling"
						v-bind="objectConfiguration.handling" />
					<NcSelect
						:disabled="loading || !properties.items.register"
						input-label="Register"
						label="Register"
						placeholder="Select a register..."
						:options="availableRegisters"
						:value="properties.items.register"
						@update:value="handleRegisterChange($event)" />
					<NcSelect
						:disabled="loading || !properties.items.register"
						input-label="Schema reference ($ref)"
						label="Schema reference ($ref)"
						placeholder="Select a schema..."
						:options="availableSchemas"
						:value="properties.items.$ref"
						@update:value="handleSchemaChange($event)" />
					<NcTextField
						:disabled="loading || !properties.items.$ref"
						label="Extra Query Parameters"
						:value.sync="properties.items.objectConfiguration.queryParams"
						placeholder="key1=value1&key2=value2"
						helper-text="Optional: Add query parameters to filter the referenced schema (e.g., status=active&type=public)" />
					<NcSelect
						:disabled="loading || !properties.items.$ref"
						v-bind="inversedByOptions"
						input-label="Property name of inversed relation"
						label="Property name of inversed relation"
						:model-value="properties.items.inversedBy"
						@update:model-value="handleInversedByChange" />
					<NcCheckboxRadioSwitch
						v-if="properties.items.inversedBy"
						:disabled="loading"
						:checked.sync="properties.items.writeBack">
						Enable write-back to target objects
					</NcCheckboxRadioSwitch>
					<div v-if="properties.items.inversedBy && !properties.items.writeBack" class="helper-text">
						When enabled, saving this object will also update the target objects to include a reference back to this object.
					</div>
					<NcCheckboxRadioSwitch
						v-if="properties.items.inversedBy && properties.items.writeBack"
						:disabled="loading"
						:checked.sync="properties.items.removeAfterWriteBack">
						Remove property after write-back
					</NcCheckboxRadioSwitch>
					<div v-if="properties.items.inversedBy && properties.items.writeBack && !properties.items.removeAfterWriteBack" class="helper-text">
						When enabled, this property will be removed from the source object after updating the target objects.
					</div>
					<NcCheckboxRadioSwitch
						:disabled="loading"
						:checked.sync="properties.items.cascadeDelete">
						Cascade delete
					</NcCheckboxRadioSwitch>
				</div>

				<NcInputField :disabled="loading"
					type="number"
					label="Minimum number of items"
					:value.sync="properties.minItems" />

				<NcInputField :disabled="loading"
					type="number"
					label="Maximum number of items"
					:value.sync="properties.maxItems" />
			</div>

			<!-- type oneOf only -->
			<div v-if="properties.type === 'oneOf'">
				<h5 class="weightNormal">
					type: oneOf
				</h5>

				<div v-for="(oneOfItem, index) in properties.oneOf" :key="index" class="ASP-oneOfItem">
					<h6>oneOf entry {{ index + 1 }}</h6>

					<div class="ASP-selectContainer">
						<NcSelect
							v-bind="itemsTypeOptions"
							v-model="oneOfItem.type"
							:input-label="'Type'" />
					</div>

					<div class="ASP-selectContainer">
						<NcSelect
							v-bind="formatOptions"
							v-model="oneOfItem.format"
							:input-label="'Format'" />
					</div>

					<NcButton
						variant="danger"
						@click="removeOneOfEntry(index)">
						Remove oneOf entry
					</NcButton>
				</div>

				<NcButton
					variant="primary"
					@click="addOneOfEntry">
					Add oneOf entry
				</NcButton>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success !== null ? 'Close' : 'Cancel' }}
			</NcButton>

			<NcButton v-if="success === null"
				:disabled="!propertyTitle || !properties.type || loading || keyExists()"
				type="primary"
				@click="addSchemaProperty()">
				<template #icon>
					<span>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSaveOutline v-if="!loading && schemaStore.schemaPropertyKey" :size="20" />
						<Plus v-if="!loading && !schemaStore.schemaPropertyKey" :size="20" />
					</span>
				</template>
				{{ schemaStore.schemaPropertyKey ? 'Save' : 'Add' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcButton,
	NcTextField,
	NcSelect,
	NcCheckboxRadioSwitch,
	NcInputField,
	NcNoteCard,
	NcLoadingIcon,
	NcDateTimePicker,
	NcTextArea,
} from '@nextcloud/vue'

// icons
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'

export default {
	name: 'EditSchemaProperty',
	components: {
		NcDialog,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcInputField,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		NcDateTimePicker,
		NcTextArea,
	},
	data() {
		return {
			propertyTitle: '',
			properties: {
				description: '',
				title: '',
				type: 'string',
				format: '',
				pattern: '',
				default: '',
				behavior: '',
				required: false,
				deprecated: false,
				visible: true,
				hideOnCollection: false,
				facetable: true,
				order: 0,
				minLength: 0,
				maxLength: 0,
				example: '',
				immutable: false,
				minimum: 0,
				maximum: 0,
				multipleOf: 0,
				exclusiveMin: false,
				exclusiveMax: false,
				minItems: 0,
				maxItems: 0,
				cascadeDelete: false,
				inversedBy: '',
				$ref: '',
				register: '',
				writeBack: false,
				removeAfterWriteBack: false,
				items: {
					cascadeDelete: false,
					$ref: '',
					type: '',
					inversedBy: '',
					register: '',
					writeBack: false,
					removeAfterWriteBack: false,
					objectConfiguration: {
						handling: 'nested-object',
						schema: '',
						queryParams: '', // Extra query parameters for items.$ref
					},
				},
				objectConfiguration: {
					handling: 'nested-object',
					schema: '',
					queryParams: '', // Extra query parameters for $ref
				},
				fileConfiguration: {
					handling: 'ignore',
					allowedMimeTypes: [],
					location: '', // Initialize with empty string
					maxSize: 0, // Initialize with 0
				},
				oneOf: [],
			},
			typeOptions: {
				inputLabel: 'Type*',
				multiple: false,
				options: ['string', 'number', 'integer', 'object', 'array', 'boolean', 'dictionary', 'file', 'oneOf'],
			},
			itemsTypeOptions: {
				inputLabel: 'Sub type',
				multiple: false,
				options: ['string', 'number', 'integer', 'object', 'boolean', 'dictionary', 'file'],
			},
			formatOptions: {
				inputLabel: 'Format',
				multiple: false,
				options: ['text', 'markdown', 'html', 'date-time', 'date', 'time', 'duration', 'email', 'idn-email', 'hostname', 'idn-hostname', 'ipv4', 'ipv6', 'uri', 'uri-reference', 'iri', 'iri-reference', 'uuid', 'uri-template', 'json-pointer', 'relative-json-pointer', 'regex', 'url', 'color', 'color-hex', 'color-hex-alpha', 'color-rgb', 'color-rgba', 'color-hsl', 'color-hsla'],
			},
			loading: false,
			success: null,
			error: false,
			closeModalTimeout: null,
			registerLoading: false,
			schemaLoading: false,
			selectedRegister: null,
			selectedSchema: null,
		}
	},
	computed: {
		objectConfiguration() {
			return {
				handling: {
					inputLabel: 'Object Handling',
					multiple: false,
					options: [
						{
							value: 'nested-object',
							label: 'Nested Object',
							description: 'Store object data directly within the parent object (embedded)',
						},
						{
							value: 'nested-schema',
							label: 'Nested Schema',
							description: 'Store object as separate entity but nest the data in response',
						},
						{
							value: 'related-schema',
							label: 'Related Schema',
							description: 'Store object as separate entity and reference by UUID/ID',
						},
						{
							value: 'uri',
							label: 'URI Reference',
							description: 'Reference external objects by URI/URL',
						},
					],
					reduce: option => option.value,
					label: 'label',
					getOptionLabel: option => option.label || option.value || '',
				},
			}
		},
		availableSchemas() {
			return schemaStore.schemaList.map(schema => ({
				id: schema.id,
				label: schema.title || schema.name || schema.id,
			}))
		},
		availableRegisters() {
			return registerStore.registerList.map(register => ({
				id: register.id,
				label: register.title || register.name || register.id,
			}))
		},
		mimeTypes() {
			return ['image/jpeg', 'image/png', 'application/pdf', 'text/plain'] // Add more MIME types as needed
		},
		fileConfiguration() {
			return {
				handling: {
					inputLabel: 'File Configuration',
					multiple: false,
					options: ['ignore', 'transform'],
				},
			}
		},

		// Dynamic inversedBy options based on selected schema
		inversedByOptions() {
			const schema = this.selectedSchema || this.getSchemaFromRef(this.properties.$ref) || this.getSchemaFromRef(this.properties.items.$ref)
			if (!schema || !schema.properties) {
				return { inputLabel: 'Select Property', multiple: false, options: [], disabled: true }
			}

			const properties = Object.keys(schema.properties).map(key => ({
				value: key,
				label: schema.properties[key].title || key,
				title: schema.properties[key].description || schema.properties[key].title || key,
			}))

			return {
				inputLabel: 'Select Property',
				multiple: false,
				options: properties,
				reduce: option => option.value,
				label: 'label',
				getOptionLabel: option => option.label || option.value || '',
				disabled: !schema,
			}
		},
	},
	watch: {
		schemaProperty: {
			deep: true,
			handler(newVal, oldVal) {
				if (newVal.type !== oldVal.type) {
					// switch types between boolean and non boolean, as boolean type expects a boolean, but others expect a string
					if (newVal.type === 'boolean') this.properties.default = false
					if (newVal.type !== 'boolean' && oldVal.type === 'boolean') this.properties.default = ''

					// when number and integer are not selected anymore, set number and integer specific properties to 0
					if (newVal.type !== 'number' && newVal.type === 'integer') this.properties.minimum = 0
					if (newVal.type !== 'number' && newVal.type === 'integer') this.properties.maximum = 0
					if (newVal.type !== 'number' && newVal.type === 'integer') this.properties.multipleOf = 0
					if (newVal.type !== 'number' && newVal.type === 'integer') this.properties.exclusiveMin = 0
					if (newVal.type !== 'number' && newVal.type === 'integer') this.properties.exclusiveMax = 0

					// when array is not selected anymore, set array specific properties to 0
					if (newVal.type !== 'array') this.properties.minItems = 0
					if (newVal.type !== 'array') this.properties.maxItems = 0
				}
			},
		},
	},
	mounted() {
		this.initializeSchemaItem()
		this.loadRegistersAndSchemas()
	},
	methods: {
		addOneOfEntry() {
			// Push a new default object into the oneOf array
			this.properties.oneOf.push({ type: '', format: '' })
		},
		removeOneOfEntry(index) {
			// Remove the entry at the specified index
			this.properties.oneOf.splice(index, 1)
		},
		initializeSchemaItem() {
			if (schemaStore.schemaPropertyKey) {
				const schemaProperty = schemaStore.schemaItem.properties[schemaStore.schemaPropertyKey]

				this.propertyTitle = schemaStore.schemaPropertyKey
				this.properties = {
					...this.properties, // Preserve default structure
					...schemaProperty, // Override with existing values
					order: schemaProperty.order ?? 0,
					minLength: schemaProperty.minLength ?? 0,
					maxLength: schemaProperty.maxLength ?? 0,
					minimum: schemaProperty.minimum ?? 0,
					maximum: schemaProperty.maximum ?? 0,
					multipleOf: schemaProperty.multipleOf ?? 0,
					minItems: schemaProperty.minItems ?? 0,
					maxItems: schemaProperty.maxItems ?? 0,
					oneOf: schemaProperty.oneOf ?? [],
					// Preserve nested configurations with existing values or defaults
					objectConfiguration: {
						...this.properties.objectConfiguration,
						...(schemaProperty.objectConfiguration || {}),
						queryParams: (schemaProperty.objectConfiguration && schemaProperty.objectConfiguration.queryParams) ? schemaProperty.objectConfiguration.queryParams : '',
					},
					fileConfiguration: {
						...this.properties.fileConfiguration,
						...(schemaProperty.fileConfiguration || {}),
					},
					// Ensure items configuration is properly loaded
					items: {
						...this.properties.items,
						...(schemaProperty.items || {}),
						objectConfiguration: {
							...this.properties.items.objectConfiguration,
							...((schemaProperty.items && schemaProperty.items.objectConfiguration) || {}),
							queryParams: (schemaProperty.items && schemaProperty.items.objectConfiguration && schemaProperty.items.objectConfiguration.queryParams) ? schemaProperty.items.objectConfiguration.queryParams : '',
						},
					},
				}
			}
		},
		/**
		 * check if the title already exists on properties as a key.
		 * returns true if it exists, false if it doesn't.
		 *
		 * When dealing with a key which is the same key as you are editing return false
		 */
		keyExists() {
			if (this.propertyTitle === schemaStore.schemaPropertyKey) return false
			return Object.keys(schemaStore.schemaItem.properties).includes(this.propertyTitle)
		},
		closeModal() {
			navigationStore.setModal(null)
			schemaStore.setSchemaPropertyKey(null)
			clearTimeout(this.closeModalTimeout)
		},
		addSchemaProperty() {
			this.loading = true

			// delete the key when its an edit modal (the item will be re-created later, so don't worry about it)
			// this is done incase you are also editing the title which acts as a key
			if (schemaStore.schemaPropertyKey) {
				delete schemaStore.schemaItem.properties[schemaStore.schemaPropertyKey]
			}

			const newSchemaItem = {
				...schemaStore.schemaItem,
				properties: {
					...schemaStore.schemaItem.properties,
					[this.propertyTitle]: { // create the new property with title as key
						...this.properties,
						// due to bad (no) support for number fields inside nextcloud/vue, parse the text to a number
						order: parseFloat(this.properties.order) || null,
						minLength: parseFloat(this.properties.minLength) || null,
						maxLength: parseFloat(this.properties.maxLength) || null,
						minimum: parseFloat(this.properties.minimum) || null,
						maximum: parseFloat(this.properties.maximum) || null,
						multipleOf: parseFloat(this.properties.multipleOf) || null,
						minItems: parseFloat(this.properties.minItems) || null,
						maxItems: parseFloat(this.properties.maxItems) || null,
					},
				},
			}

			if (!newSchemaItem.properties[this.propertyTitle].items.$ref && !newSchemaItem.properties[this.propertyTitle].items.type) {
				delete newSchemaItem.properties[this.propertyTitle].items
			}

			if (this.properties.required === false) {
				if (newSchemaItem.required && Array.isArray(newSchemaItem.required)) {
					newSchemaItem.required = newSchemaItem.required.filter(
						requiredProp => requiredProp !== this.propertyTitle
						&& (schemaStore.schemaPropertyKey ? requiredProp !== schemaStore.schemaPropertyKey : true),
					)
				}
			}

			if (!newSchemaItem?.id) {
				this.success = false
				this.error = 'Schema item could not be created, missing schema id'
				this.loading = false
				return
			}

			schemaStore.saveSchema(newSchemaItem)
				.then(({ response }) => {
					this.success = response.ok

					this.closeModalTimeout = setTimeout(this.closeModal, 2000)
				}).catch((err) => {
					this.success = false
					this.error = err
				}).finally(() => {
					this.loading = false
				})
		},
		verifyJsonValidity(jsonInput) {
			if (jsonInput === '') return true
			try {
				JSON.parse(jsonInput)
				return true
			} catch (e) {
				return false
			}
		},
		async loadRegistersAndSchemas() {
			this.registerLoading = true
			this.schemaLoading = true

			try {
				// Load registers if not already loaded
				if (!registerStore.registerList.length) {
					await registerStore.refreshRegisterList()
				}

				// Load schemas if not already loaded
				if (!schemaStore.schemaList.length) {
					await schemaStore.refreshSchemaList()
				}
			} catch (error) {
				console.error('Error loading registers and schemas:', error)
			} finally {
				this.registerLoading = false
				this.schemaLoading = false
			}
		},

		getSchemaFromRef(ref) {
			if (!ref) return null

			// Extract schema ID from $ref (handle both simple IDs and paths)
			const schemaId = ref.includes('/') ? ref.split('/').pop() : ref

			// Find schema in store
			return schemaStore.schemaList.find(schema =>
				schema.id === schemaId || schema.title === schemaId || schema.slug === schemaId,
			)
		},

		handleRegisterChange(register) {
			// Store the register ID, not the whole object
			const registerId = typeof register === 'object' ? register.id : register
			this.selectedRegister = register
			this.properties.register = registerId
			this.properties.items.register = registerId
			// Clear schema, inversedBy and query parameters when register changes
			this.properties.$ref = ''
			this.properties.inversedBy = ''
			if (this.properties.objectConfiguration) {
				this.properties.objectConfiguration.queryParams = ''
			}
			this.properties.items.$ref = ''
			this.properties.items.inversedBy = ''
			if (this.properties.items.objectConfiguration) {
				this.properties.items.objectConfiguration.queryParams = ''
			}
		},

		handleSchemaChange(schema) {
			// Store the schema ID, not the whole object
			const schemaId = typeof schema === 'object' ? schema.id : schema
			this.selectedSchema = schema
			this.properties.$ref = schemaId
			this.properties.items.$ref = schemaId
			// Clear inversedBy and query parameters when schema changes
			this.properties.inversedBy = ''
			this.properties.items.inversedBy = ''
			if (this.properties.objectConfiguration) {
				this.properties.objectConfiguration.queryParams = ''
			}
			if (this.properties.items.objectConfiguration) {
				this.properties.items.objectConfiguration.queryParams = ''
			}
		},

		handleInversedByChange(property) {
			const propertyName = typeof property === 'object' ? property.value || property.id : property
			this.properties.inversedBy = propertyName
			this.properties.items.inversedBy = propertyName
			// Clear query parameters when inversedBy changes
			if (this.properties.objectConfiguration) {
				this.properties.objectConfiguration.queryParams = ''
			}
			if (this.properties.items.objectConfiguration) {
				this.properties.items.objectConfiguration.queryParams = ''
			}
		},
	},
}
</script>

<style>
.modal__content {
  margin: var(--OR-margin-50);
  text-align: center;
}

.form-group .group {
    margin-block-end: 2rem;
}

.zaakDetailsContainer {
  margin-block-start: var(--OR-margin-20);
  margin-inline-start: var(--OR-margin-20);
  margin-inline-end: var(--OR-margin-20);
}

.success {
  color: green;
}

.weightNormal {
    font-weight: normal;
}

.ASP-selectContainer {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.objectConfigurationContainer {
	margin-block-end: 15px;
}

.objectConfigurationTitle {
	margin-block-end: 5px;
	font-weight: bold;
}

.helper-text {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin-top: -8px;
	margin-bottom: 8px;
	margin-left: 8px;
	font-style: italic;
}
</style>
