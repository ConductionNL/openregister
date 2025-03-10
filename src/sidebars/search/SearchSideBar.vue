<script setup>
import { searchStore, registerStore, schemaStore } from '../../store/store.js'
import { AppInstallService } from '../../services/appInstallService.js'
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

			<div v-if="searchStore.searchObjectsResult?.results?.length">
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

			<NcNoteCard type="info">
				OpenConnector is required for this feature.
				<NcButton v-if="!openConnectorInstalled && !openConnectorInstallError" type="secondary" @click="installApp('openconnector')">
					Install OpenConnector
				</NcButton>
			</NcNoteCard>
			<NcNoteCard v-if="openConnectorInstallError" type="error">
				Failed to install OpenConnector. Check console for more details.
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="download-tab" name="Download" :order="3">
			<template #icon>
				<Download :size="20" />
			</template>

			<NcNoteCard type="info">
				OpenConnector is required for this feature.
				<NcButton v-if="!openConnectorInstalled && !openConnectorInstallError" type="secondary" @click="installApp('openconnector')">
					Install OpenConnector
				</NcButton>
			</NcNoteCard>
			<NcNoteCard v-if="openConnectorInstallError" type="error">
				Failed to install OpenConnector. Check console for more details.
			</NcNoteCard>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcSelect, NcButton, NcNoteCard, NcCheckboxRadioSwitch } from '@nextcloud/vue'
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
		NcNoteCard,
	},
	data() {
		return {
			registerLoading: false,
			selectedRegister: null,
			schemaLoading: false,
			selectedSchema: null,
			appInstallService: new AppInstallService(),
			openConnectorInstalled: true,
			openConnectorInstallError: false,
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
				this.searchObjects()
				EventBus.$emit('reset-page')
			}
		},
	},
	created() {
		EventBus.$on('page-change', (page) => {
			this.searchObjects(page)
		})
	},
	beforeDestroy() {
		// Clean up the event listener
		EventBus.$off('page-change')
	},
	mounted() {
		this.registerLoading = true
		this.schemaLoading = true
		registerStore.refreshRegisterList().finally(() => (this.registerLoading = false))
		schemaStore.refreshSchemaList().finally(() => (this.schemaLoading = false))

		this.initAppInstallService()
	},
	methods: {
		searchObjects(page = 1) {
			searchStore.searchObjects({
				register: this.selectedRegister?.id,
				schema: this.selectedSchema?.id,
				_limit: 14,
				_page: page,
			})
		},
		async initAppInstallService() {
			await this.appInstallService.init()

			this.openConnectorInstalled = await this.appInstallService.isAppInstalled('openconnector')
		},
		async installApp(appId) {
			try {
				await this.appInstallService.forceInstallApp(appId)
				this.openConnectorInstalled = true
			} catch (error) {
				// gracefully show error to user and remove the button
				if (error.status === 403 && error.data?.message === 'Password confirmation is required') {
					console.error('Password confirmation needed before installing apps')
				} else {
					console.error('Failed to install app:', error)
				}
				this.openConnectorInstallError = true
			}
		},
		emitUpdatedColumnFilter(status, id) {
			EventBus.$emit('object-search-set-column-filter', {
				id,
				enabled: status,
			})
		},
	},
}
</script>
