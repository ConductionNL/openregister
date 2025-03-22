<script setup>
import { objectStore, registerStore, schemaStore } from '../../store/store.js'
import { AppInstallService } from '../../services/appInstallService.js'
import { EventBus } from '../../eventBus.js'
import { computed } from 'vue'

// Computed properties to handle the false values
const selectedRegisterValue = computed({
	get: () => registerStore.registerItem || null,
	set: (value) => registerStore.setRegisterItem(value)
})

const selectedSchemaValue = computed({
	get: () => schemaStore.schemaItem || null,
	set: (value) => schemaStore.setSchemaItem(value)
})
</script>

<template>
	<NcAppSidebar
		name="Object selection"
		subtitle="Select register and schema"
		subname="Within the federative network">
		<NcAppSidebarTab id="search-tab" name="Selection" :order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>
			<NcSelect v-bind="registerOptions"
				:model-value="selectedRegisterValue"
				@update:model-value="selectedRegisterValue = $event"
				input-label="Register"
				:loading="registerLoading"
				:disabled="registerLoading" />
			<NcSelect v-bind="schemaOptions"
				:model-value="selectedSchemaValue"
				@update:model-value="selectedSchemaValue = $event"
				input-label="Schema"
				:loading="schemaLoading"
				:disabled="!selectedRegister || schemaLoading" />

			<div v-if="objectStore.objectList?.results?.length">
				<NcCheckboxRadioSwitch 
					v-for="(enabled, id) in objectStore.columnFilters" 
					:key="id"
					:checked="enabled"
					@update:checked="(status) => objectStore.updateColumnFilter(id, status)">
					{{ getColumnLabel(id) }}
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
		NcCheckboxRadioSwitch,
		Magnify,
		Upload,
		Download,
	},
	data() {
		return {
			registerLoading: false,
			schemaLoading: false,
			appInstallService: new AppInstallService(),
			openConnectorInstalled: true,
			openConnectorInstallError: false,
		}
	},
	computed: {
		registerOptions() {
			return {
				options: registerStore.registerList.map(register => ({
					label: register.title,
					id: register.id,
				})),
			}
		},
		schemaOptions() {
			const fullSelectedRegister = registerStore.registerList.find(
				register => register.id === (this.selectedRegister?.id || Symbol('no selected register'))
			)
			if (!fullSelectedRegister) return { options: [] }

			return {
				options: schemaStore.schemaList
					.filter(schema => fullSelectedRegister.schemas.includes(schema.id))
					.map(schema => ({
						label: schema.title,
						id: schema.id,
					})),
			}
		},
		selectedRegister() {
			return registerStore.registerItem || false
		},
		selectedSchema() {
			return schemaStore.schemaItem || false
		},
	},
	watch: {
		selectedRegister(newValue) {
			if (!newValue) {
				schemaStore.setSchemaItem(false)
			}
		},
		selectedSchema(newValue) {
			if (newValue) {
				objectStore.setPagination(1)
				this.ignoreNextPageWatch = true

				objectStore.refreshObjectList({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id
				})

				const unwatch = this.$watch(
					() => objectStore.loading,
					(newVal) => {
						if (newVal === false) {
							this.ignoreNextPageWatch = false
							unwatch()
						}
					},
				)
			}
		},
	},
	mounted() {
		this.registerLoading = true
		this.schemaLoading = true
		
		registerStore.refreshRegisterList()
			.finally(() => (this.registerLoading = false))
		
		schemaStore.refreshSchemaList()
			.finally(() => (this.schemaLoading = false))

		this.initAppInstallService()
	},
	methods: {
		async initAppInstallService() {
			await this.appInstallService.init()
			this.openConnectorInstalled = await this.appInstallService.isAppInstalled('openconnector')
		},
		async installApp(appId) {
			try {
				await this.appInstallService.forceInstallApp(appId)
				this.openConnectorInstalled = true
			} catch (error) {
				if (error.status === 403 && error.data?.message === 'Password confirmation is required') {
					console.error('Password confirmation needed before installing apps')
				} else {
					console.error('Failed to install app:', error)
				}
				this.openConnectorInstallError = true
			}
		},
		getColumnLabel(id) {
			const labels = {
				objectId: 'ObjectID',
				created: 'Created',
				updated: 'Updated',
				files: 'Files',
			}
			return labels[id] || id
		},
	},
}
</script>
