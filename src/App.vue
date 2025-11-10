<template>
	<NcContent app-name="openregister">
		<MainMenu />
		<Views />
		<SideBars />
		<Modals />
		<Dialogs />
	</NcContent>
</template>

<script>

import { NcContent } from '@nextcloud/vue'
import MainMenu from './navigation/MainMenu.vue'
import Modals from './modals/Modals.vue'
import Dialogs from './dialogs/Dialogs.vue'
import Views from './views/Views.vue'
import SideBars from './sidebars/SideBars.vue'
import { setupDashboardStoreWatchers } from './store/modules/dashboard.js'
import { initializeAppData } from './services/AppInitializationService.js'

export default {
	name: 'App',
	components: {
		NcContent,
		MainMenu,
		Modals,
		Dialogs,
		Views,
		SideBars,
	},
	mounted() {
		// Initialize hot-loading of essential application data
		// This loads registers, schemas, organisations, applications, views, agents, sources, and conversations
		initializeAppData()
		
		// Set up dashboard store watchers to keep dashboard data in sync, after stores are reactive
		setupDashboardStoreWatchers()
	},
}
</script>
