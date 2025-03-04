<script setup>
import { searchStore, registerStore, schemaStore } from '../../store/store.js'
import { EventBus } from '../../eventBus.js'
</script>

<template>
	<NcAppSidebar
		name="Zoek opdracht"
		subtitle="baldie"
		subname="Binnen het federatieve netwerk">
		<NcAppSidebarTab id="search-tab" name="Zoeken" :order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>
			<NcSelect v-bind="registerOptions"
				v-model="selectedRegister"
				input-label="Registratie"
				:loading="registerLoading" />
			<NcSelect v-bind="schemaOptions"
				v-model="selectedSchema"
				input-label="Schema"
				:loading="schemaLoading"
				:disabled="!selectedRegister?.id" />

			<div>
				<NcCheckboxRadioSwitch :checked.sync="columnFilter.objectId"
					@update:checked="(status) => emitUpdatedColumnFilter(status, 'objectId')">
					ObjectID
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="columnFilter.created"
					@update:checked="(status) => emitUpdatedColumnFilter(status, 'created')">
					Created
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="columnFilter.updated"
					@update:checked="(status) => emitUpdatedColumnFilter(status, 'updated')">
					Updated
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="columnFilter.files"
					@update:checked="(status) => emitUpdatedColumnFilter(status, 'files')">
					Files
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="columnFilter.schemaProperties"
					@update:checked="(status) => emitUpdatedColumnFilter(status, 'schemaProperties')">
					Schema properties
				</NcCheckboxRadioSwitch>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="upload-tab" name="Upload" :order="2">
			<template #icon>
				<Upload :size="20" />
			</template>
			<NcButton type="primary">
				Upload
			</NcButton>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="download-tab" name="Download" :order="3">
			<template #icon>
				<Download :size="20" />
			</template>
			<NcButton type="primary">
				Download
			</NcButton>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>
<script>

import { NcAppSidebar, NcAppSidebarTab, NcSelect, NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Download from 'vue-material-design-icons/Download.vue'

export default {
	name: 'SearchSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcButton,
	},
	data() {
		return {
			registerLoading: false,
			selectedRegister: null,
			schemaLoading: false,
			selectedSchema: null,
			columnFilter: {
				objectId: true,
				created: true,
				updated: true,
				files: true,
				schemaProperties: true,
			},
		}
	},
	computed: {
		// when registerList is filled, make a options object for NcSelect
		registerOptions() {
			return {
				options: registerStore.registerList.map(register => ({
					label: register.title,
					id: register.id,
				})),
			}
		},
		// when schemaList is filled, make a options object for NcSelect based on the selected register
		schemaOptions() {
			const fullSelectedRegister = registerStore.registerList.find(register => register.id === (this.selectedRegister?.id || Symbol('no selected register')))
			if (!fullSelectedRegister) return []

			return {
				options: schemaStore.schemaList
					.filter(schema => fullSelectedRegister.schemas.includes(schema.id))
					.map(schema => ({
						label: schema.title,
						id: schema.id,
					})),
			}
		},
	},
	watch: {
		// when the selected register changes clear the selected schema
		selectedRegister(newValue) {
			this.selectedSchema = null
		},
		// when selectedSchema changes, search for objects with the selected register and schema as filters
		selectedSchema(newValue) {
			if (newValue?.id) {
				searchStore.searchObjects({
					register: this.selectedRegister?.id,
					schema: this.selectedSchema?.id,
				})
			}
		},
	},
	mounted() {
		this.registerLoading = true
		this.schemaLoading = true
		registerStore.refreshRegisterList().finally(() => (this.registerLoading = false))
		schemaStore.refreshSchemaList().finally(() => (this.schemaLoading = false))
	},
	methods: {
		emitUpdatedColumnFilter(status, id) {
			EventBus.$emit('object-search-set-column-filter', {
				id,
				enabled: status,
			})
		},
	},
}
</script>
