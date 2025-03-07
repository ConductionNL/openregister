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
				v-model="searchStore.searchObjects_register"
				input-label="Registratie"
				:loading="registerLoading"
				:disabled="registerLoading" />
			<NcSelect v-bind="schemaOptions"
				v-model="searchStore.searchObjects_schema"
				input-label="Schema"
				:loading="schemaLoading"
				:disabled="!selectedRegister?.id || schemaLoading" />

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
			schemaLoading: false,
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
			/**
			 * This is used to prevent another search from being triggered when schema is changed.
			 * This is needed because the pagination is set to 1 when switching schema.
			 * Which will trigger another search.
			 */
			ignoreNextPageWatch: false,
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
		selectedRegister: () => searchStore.searchObjects_register,
		selectedSchema: () => searchStore.searchObjects_schema,
		page: () => searchStore.searchObjects_pagination,
	},
	watch: {
		// when the selected register changes clear the selected schema
		selectedRegister(newValue) {
			searchStore.searchObjects_schema = null
		},
		// when selectedSchema changes, search for objects with the selected register and schema as filters
		selectedSchema(newValue) {
			if (newValue?.id) {
				searchStore.searchObjects_pagination = 1
				this.ignoreNextPageWatch = true

				this.searchObjects()

				// wait for loading to finish, then allow watching on page to continue
				const unwatch = this.$watch(
					() => searchStore.searchObjectsLoading,
					(newVal) => {
						if (newVal === false) {
							this.ignoreNextPageWatch = false
							unwatch() // Remove the watcher once we're done
						}
					},
				)
			}
		},
		page() {
			if (this.ignoreNextPageWatch) {
				return
			}
			this.searchObjects()
		},
	},
	mounted() {
		this.registerLoading = true
		this.schemaLoading = true
		registerStore.refreshRegisterList().finally(() => (this.registerLoading = false))
		schemaStore.refreshSchemaList().finally(() => (this.schemaLoading = false))

		this.initAppInstallService()
	},
	methods: {
		searchObjects() {
			searchStore.searchObjects({
				register: searchStore.searchObjects_register?.id,
				schema: searchStore.searchObjects_schema?.id,
				_limit: searchStore.searchObjects_limit,
				_page: searchStore.searchObjects_pagination,
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
