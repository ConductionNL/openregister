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
		</NcAppNavigationList>
		<NcAppNavigationSettings>
			<NcAppNavigationItem :active="navigationStore.selected === 'dashboard'" name="Dashboard" @click="navigationStore.setSelected('dashboard')">
				<template #icon>
					<Finance :size="20" />
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

export default {
	name: 'MainMenu',
	components: {
		// components
		NcAppNavigation,
		NcAppNavigationList,
		NcAppNavigationItem,
		// icons
		Finance,
	},
	methods: {
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>
