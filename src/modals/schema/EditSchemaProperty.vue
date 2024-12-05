<script setup>
import { navigationStore, schemaStore } from '../../store/store.js'
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

			<div class="ASP-selectContainer">
				<NcSelect v-bind="typeOptions"
					v-model="properties.type" />

				<NcSelect v-bind="formatOptions"
					v-model="properties.format"
					:disabled="properties.type !== 'string'" />
			</div>

			<NcTextField :disabled="loading"
				label="Pattern (regex)"
				:value.sync="properties.pattern" />

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
			<NcTextArea v-else-if="properties.type === 'object'"
				:disabled="loading"
				label="Default value"
				:value.sync="properties.default"
				:loading="loading"
				:error="!verifyJsonValidity(properties.default)"
				:helper-text="!verifyJsonValidity(properties.default) ? 'This is not valid JSON' : ''" />
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

			<NcTextField :disabled="loading"
				label="Behavior"
				:value.sync="properties.behavior" />

			<NcCheckboxRadioSwitch
				:disabled="loading"
				:checked.sync="properties.required">
				Required
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				:disabled="loading"
				:checked.sync="properties.deprecated">
				Deprecated
			</NcCheckboxRadioSwitch>

			<NcInputField :disabled="loading"
				type="number"
				label="Minimum length"
				:value.sync="properties.minLength" />

			<NcInputField :disabled="loading"
				type="number"
				label="Maximum length"
				:value.sync="properties.maxLength" />

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
					<NcInputField :disabled="loading"
						type="string"
						label="Schema reference of object ($ref)"
						:value.sync="properties.items.$ref" />
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
				type: 'string',
				format: '',
				pattern: '',
				default: '',
				behavior: '',
				required: false,
				deprecated: false,
				minLength: 0,
				maxLength: 0,
				example: '',
				minimum: 0,
				maximum: 0,
				multipleOf: 0,
				exclusiveMin: false,
				exclusiveMax: false,
				minItems: 0,
				maxItems: 0,
				$ref: '',
				items: {
					$ref: '',
					type: '',
				},
			},
			typeOptions: {
				inputLabel: 'Type*',
				multiple: false,
				options: ['string', 'number', 'integer', 'object', 'array', 'boolean', 'dictionary', 'file'],
			},
			itemsTypeOptions: {
				inputLabel: 'Sub type',
				multiple: false,
				options: ['string', 'number', 'integer', 'object', 'boolean', 'dictionary', 'file'],
			},
			formatOptions: {
				inputLabel: 'Format',
				multiple: false,
				options: ['date', 'time', 'duration', 'date-time', 'url', 'uri', 'uuid', 'email', 'idn-email', 'hostname', 'idn-hostname', 'ipv4', 'ipv6', 'uri-reference', 'iri', 'iri-reference', 'uri-template', 'json-pointer', 'regex', 'binary', 'byte', 'password', 'rsin', 'kvk', 'bsn', 'oidn', 'telephone'],
			},
			loading: false,
			success: null,
			error: false,
			closeModalTimeout: null,
		}
	},
	computed: {
		schemaProperty() {
			return this.properties
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
	},
	methods: {
		initializeSchemaItem() {
			if (schemaStore.schemaPropertyKey) {
				const schemaProperty = schemaStore.schemaItem.properties[schemaStore.schemaPropertyKey]

				this.propertyTitle = schemaStore.schemaPropertyKey
				this.properties = {
					...schemaProperty,
					minLength: schemaProperty.minLength ?? 0,
					maxLength: schemaProperty.maxLength ?? 0,
					minimum: schemaProperty.minimum ?? 0,
					maximum: schemaProperty.maximum ?? 0,
					multipleOf: schemaProperty.multipleOf ?? 0,
					minItems: schemaProperty.minItems ?? 0,
					maxItems: schemaProperty.maxItems ?? 0,
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

			if (!newSchemaItem.properties[this.propertyTitle].items.$ref && !newSchemaItem[this.propertyTitle].items.type) {
				delete newSchemaItem.properties[[this.propertyTitle]].items
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
	},
}
</script>

<style>
.modal__content {
  margin: var(--OC-margin-50);
  text-align: center;
}

.form-group .group {
    margin-block-end: 2rem;
}

.zaakDetailsContainer {
  margin-block-start: var(--OC-margin-20);
  margin-inline-start: var(--OC-margin-20);
  margin-inline-end: var(--OC-margin-20);
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
</style>
