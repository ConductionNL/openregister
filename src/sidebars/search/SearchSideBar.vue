<script setup>
import { searchStore, metadataStore, catalogiStore } from '../../store/store.js'
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
			Zoek snel in het voor uw beschikbare federatieve netwerk<br>
			<NcTextField class="searchField"
				:value.sync="searchStore.search"
				label="Zoeken" />
			<NcNoteCard v-if="searchStore.searchError" type="error">
				<p>{{ searchStore.searchError }}</p>
			</NcNoteCard>
		</NcAppSidebarTab>
		<NcAppSidebarTab id="settings-tab" name="Catalogi" :order="2">
			<template #icon>
				<DatabaseOutline :size="20" />
			</template>
			<NcCheckboxRadioSwitch v-for="(catalogiItem, i) in catalogiStore.catalogiList"
				:key="`${catalogiItem}${i}`"
				type="switch"
				:checked.sync="searchStore.catalogi[catalogiItem.id]">
				{{ catalogiItem.title || 'Geen titel' }}
			</NcCheckboxRadioSwitch>
		</NcAppSidebarTab>
		<NcAppSidebarTab id="share-tab" name="Publicatie typen" :order="3">
			<template #icon>
				<FileTreeOutline :size="20" />
			</template>
			<NcCheckboxRadioSwitch v-for="(metaData, i) in metadataStore.metaDataList"
				:key="`${metaData}${i}`"
				type="switch"
				:checked.sync="searchStore.metadata[metaData.id]">
				{{ metaData.title || 'Geen titel' }}
			</NcCheckboxRadioSwitch>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>
<script>

import { NcAppSidebar, NcAppSidebarTab, NcTextField, NcNoteCard, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import { debounce } from 'lodash'

export default {
	name: 'SearchSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcTextField,
		NcCheckboxRadioSwitch,
		// Icons
		Magnify,
		DatabaseOutline,
		FileTreeOutline,
	},
	props: {
		search: {
			type: String,
			required: true,
		},
		metadata: {
			type: Object,
			required: true,
		},
		catalogi: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			starred: false,
		}
	},
	watch: {
		search: 'debouncedSearch',
		metadata: {
			handler() {
				this.debouncedSearch()
			},
			deep: true,
		},
		catalogi: {
			handler() {
				this.debouncedSearch()
			},
			deep: true,
		},
	},
	mounted() {
		metadataStore.refreshMetaDataList()
		catalogiStore.refreshCatalogiList()
	},
	methods: {
		debouncedSearch: debounce(function() {
			searchStore.getSearchResults()
		}, 500),
	},
}
</script>
