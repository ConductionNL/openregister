<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<NcAppNavigationItem :active="navigationStore.selected === 'dashboard'" :name="t('openregister', 'Dashboard')" @click="navigationStore.setSelected('dashboard')">
				<template #icon>
					<Finance :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'registers'" :name="t('openregister', 'Registers')" @click="navigationStore.setSelected('registers')">
				<template #icon>
					<DatabaseOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'schemas'" :name="t('openregister', 'Schemas')" @click="navigationStore.setSelected('schemas')">
				<template #icon>
					<FileTreeOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'tableSearch'" :name="t('openregister', 'Tables')" @click="navigationStore.setSelected('tableSearch')">
				<template #icon>
					<TableMultiple :size="20" />
				</template>
			</NcAppNavigationItem>
			<!-- <NcAppNavigationItem :active="navigationStore.selected === 'objects'" name="Search" @click="navigationStore.setSelected('search')">
				<template #icon>
					<LayersSearchOutline :size="20" />
				</template>
			</NcAppNavigationItem> -->
		</NcAppNavigationList>

		<NcAppNavigationSettings>
			<NcAppNavigationItem :active="navigationStore.selected === 'sources'" :name="t('openregister', 'Data sources')" @click="navigationStore.setSelected('sources')">
				<template #icon>
					<DatabaseArrowRightOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'configurations'" :name="t('openregister', 'Configurations')" @click="navigationStore.setSelected('configurations')">
				<template #icon>
					<CogOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'deleted'" :name="t('openregister', 'Deleted')" @click="navigationStore.setSelected('deleted')">
				<template #icon>
					<DeleteRestore :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'auditTrails'" :name="t('openregister', 'Audit Trails')" @click="navigationStore.setSelected('auditTrails')">
				<template #icon>
					<TextBoxOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationSettings>
	</NcAppNavigation>
</template>
<script>

import {
	NcAppNavigation,
	NcAppNavigationList,
	NcAppNavigationSettings,
	NcAppNavigationItem,
} from '@nextcloud/vue'

// Icons
import Finance from 'vue-material-design-icons/Finance.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
// import LayersSearchOutline from 'vue-material-design-icons/LayersSearchOutline.vue'
import TableMultiple from 'vue-material-design-icons/TableMultiple.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DeleteRestore from 'vue-material-design-icons/DeleteRestore.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'

export default {
	name: 'MainMenu',
	components: {
		// components
		NcAppNavigation,
		NcAppNavigationList,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		// icons
		Finance,
		DatabaseOutline,
		DatabaseArrowRightOutline,
		FileTreeOutline,
		TableMultiple,
		// LayersSearchOutline,
		CogOutline,
		DeleteRestore,
		TextBoxOutline,
	},
	methods: {
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>
