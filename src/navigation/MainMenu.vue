<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<NcAppNavigationItem :active="navigationStore.selected === 'dashboard'" name="Dashboard" @click="navigationStore.setSelected('dashboard')">
				<template #icon>
					<Finance :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'registers'" name="Registers" @click="navigationStore.setSelected('registers')">
				<template #icon>
					<DatabaseOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'schemas'" name="Schemas" @click="navigationStore.setSelected('schemas')">
				<template #icon>
					<FileTreeOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>
		<NcAppNavigationSettings>
			<NcAppNavigationItem :active="navigationStore.selected === 'databases'" name="Data sources" @click="navigationStore.setSelected('databases')">
				<template #icon>
					<DatabaseArrowRightOutline :size="20" />
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

export default {
	name: 'MainMenu',
	components: {
		// components
		NcAppNavigation,
		NcAppNavigationList,
		NcAppNavigationItem,
		// icons
		Finance,
		DatabaseOutline,
		DatabaseArrowRightOutline,
		FileTreeOutline,
	},
	methods: {
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>
