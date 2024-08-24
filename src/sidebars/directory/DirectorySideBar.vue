<script setup>
import { navigationStore, directoryStore, metadataStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		:name="directoryStore.listingItem?.title || 'Geen listing' "
		:subname="directoryStore.listingItem?.organisation?.title">
		<NcEmptyContent v-if="!directoryStore.listingItem.id || navigationStore.selected != 'directory'"
			class="detailContainer"
			name="Geen listing"
			description="Nog geen listing geselecteerd, listings kan je ontdekken via (externe) directories.">
			<template #icon>
				<LayersOutline />
			</template>
			<template #action>
				<NcButton type="primary" @click="navigationStore.setModal('addDirectory')">
					<template #icon>
						<Plus :size="20" />
					</template>
					Directory inlezen
				</NcButton>
				<NcButton @click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/directory', '_blank')">
					<template #icon>
						<HelpCircleOutline :size="20" />
					</template>
					Meer informatie over de directory
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcAppSidebarTab v-if="directoryStore.listingItem.id && navigationStore.selected === 'directory'"
			id="detail-tab"
			name="Details"
			:order="1">
			<template #icon>
				<InformationSlabSymbol :size="20" />
			</template>
			<div class="container">
				<div>
					<b>Samenvatting:</b>
					<span>{{ directoryStore.listingItem?.summery }}</span>
				</div>
				<div>
					<b>Status:</b>
					<span>{{ directoryStore.listingItem?.status }}</span>
				</div>
				<div>
					<b>Last synchronysation:</b>
					<span>{{ directoryStore.listingItem?.lastSync }}</span>
				</div>
				<div>
					<b>Directory:</b>
					<span>{{ directoryStore.listingItem?.directory }}</span>
				</div>
				<div>
					<b>Zoeken:</b>
					<span>{{ directoryStore.listingItem?.search }}</span>
				</div>
				<div>
					<b>Beschrijving:</b>
					<span>{{ directoryStore.listingItem?.description }}</span>
				</div>
			</div>
		</NcAppSidebarTab>
		<NcAppSidebarTab v-if="directoryStore.listingItem.id && navigationStore.selected === 'directory'"
			id="settings-tab"
			name="Configuratie"
			:order="2">
			<template #icon>
				<CogOutline :size="20" />
			</template>
			<NcCheckboxRadioSwitch :checked.sync="directoryStore.listingItem.available" type="switch">
				Beschickbaar maken voor mijn zoek opdrachten
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch :checked.sync="directoryStore.listingItem.default" type="switch">
				Standaard mee nemen in de beantwoording van mijn zoekopdrachten
			</NcCheckboxRadioSwitch>

			<NcButton
				:disabled="syncLoading"
				type="primary"
				class="syncButton"
				@click="synDirectroy">
				<template #icon>
					<NcLoadingIcon v-if="syncLoading" :size="20" />

					<DatabaseSyncOutline v-if="!syncLoading" :size="20" />
				</template>
				Synchroniseren
			</NcButton>
		</NcAppSidebarTab>
		<NcAppSidebarTab v-if="directoryStore.listingItem.id && navigationStore.selected === 'directory'"
			id="metdata-tab"
			name="Publicatie typen"
			:order="3">
			<template #icon>
				<FileTreeOutline :size="20" />
			</template>
			Welke meta data typen zou u uit deze catalogus willen overnemen?
			<div v-if="!loading">
				<NcCheckboxRadioSwitch v-for="(metadataSingular, i) in directoryStore.listingItem.metadata"
					:key="`${metadataSingular}${i}`"
					:checked.sync="checkedMetadata[metadataSingular]"
					type="switch">
					{{ metadataSingular }}
				</NcCheckboxRadioSwitch>
			</div>
			<NcLoadingIcon v-if="loading" :size="20" />
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>
<script>

import { NcAppSidebar, NcEmptyContent, NcButton, NcAppSidebarTab, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import LayersOutline from 'vue-material-design-icons/LayersOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import DatabaseSyncOutline from 'vue-material-design-icons/DatabaseSyncOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import InformationSlabSymbol from 'vue-material-design-icons/InformationSlabSymbol.vue'

export default {
	name: 'DirectorySideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcEmptyContent,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
	},
	data() {
		return {
			checkedMetadata: {},
			listing: '',
			loading: false,
			syncLoading: false,
		}
	},
	computed: {
		listingItem() {
			return directoryStore.listingItem
		},
	},
	watch: {
		checkedMetadata: {
			handler(newValue, oldValue) {
				const metadataUrl = Object.entries(newValue)[0][0]
				const shouldCopyMetadata = Object.entries(newValue)[0][1]
				if (shouldCopyMetadata === true) {
					this.copyMetadata(metadataUrl)
				} else if (shouldCopyMetadata === false && metadataUrl) {
					this.deleteMetadata(metadataUrl)
				}
			},
			deep: true,
		},
		listingItem: {
			handler(newValue, oldValue) {
				if (newValue !== false && metadataStore?.metaDataList) {
					this.loading = true
					this.checkMetadataSwitches()
				}
			},
			deep: true, // Track changes in nested objects
			immediate: true, // Run the handler immediately on initialization
		},
	},
	created() {
		metadataStore.refreshMetaDataList()
		this.checkMetadataSwitches()
	},
	methods: {
		openLink(url, type = '') {
			window.open(url, type)
		},
		getMetadataId(metadataUrl) {
			let metadataId
			metadataStore.metaDataList.forEach((metadataItem) => {
				if (metadataUrl === metadataItem.source) {
					metadataId = metadataItem.id
				}
			})
			return metadataId
		},
		checkMetadataSwitches() {
			if (Array.isArray(directoryStore?.listingItem?.metadata)) {
				directoryStore.listingItem.metadata.forEach((metadataUrl) => {
					// Check if the metadata URL exists in the metadataStore.metaDataList
					const exists = metadataStore.metaDataList.some(metaData => metaData.source === metadataUrl)
					// Update the checkedMetadata reactive state
					this.$set(this.checkedMetadata, metadataUrl, exists)
				})
			}
			this.loading = false
		},
		copyMetadata(metadataUrl) {
			this.loading = true
			fetch(
				metadataUrl,
				{
					method: 'GET',
				},
			)
				.then((response) => {
					metadataStore.refreshMetaDataList()
					response.json().then((data) => {
						const metaDataSources = metadataStore.metaDataList.map((metaData) => metaData.source)
						if (!metaDataSources.includes(data.source)) this.createMetadata(data)
					})
					this.loading = false
				})
				.catch((err) => {
					this.error = err
					this.loading = false
				})
		},
		createMetadata(data) {
			this.loading = true
			data.title = 'KOPIE: ' + data.title

			if (Object.keys(data.properties).length === 0) {
				delete data.properties
			}

			delete data.id
			delete data._id

			fetch(
				'/index.php/apps/opencatalogi/api/metadata',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(data),
				},
			)
				.then((response) => {
					this.loading = false
				})
				.catch((err) => {
					this.error = err
					this.loading = false
				})
		},
		deleteMetadata(metadataUrl) {
			this.loading = true
			const metadataId = this.getMetadataId(metadataUrl)

			fetch(
				`/index.php/apps/opencatalogi/api/metadata/${metadataId}`,
				{
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
					},
				},
			)
				.then(() => {
					this.loading = false
				})
				.catch((err) => {
					this.error = err
					this.loading = false
				})
		},
		synDirectroy() {
			this.syncLoading = true
			fetch(
				`/index.php/apps/opencatalogi/api/directory/${directoryStore.listingItem.id}/sync`,
				{
					method: 'GET',
				},
			)
				.then(() => {
					this.syncLoading = false
				})
				.catch((err) => {
					this.error = err
					this.syncLoading = false
				})
		},
	},
}
</script>

<style>
.syncButton {
	margin-block-start: 15px;
	width: 100% !important;
}
</style>
