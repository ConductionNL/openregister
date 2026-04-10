<template>
	<NcContent app-name="openregister">
		<MainMenu />
		<Views />
		<SideBars />
		<CnObjectSidebar
			v-if="objectSidebarState.active"
			:title="objectSidebarState.title"
			:subtitle="objectSidebarState.subtitle"
			:object-type="objectSidebarState.objectType"
			:object-id="objectSidebarState.objectId"
			:register="objectSidebarState.register"
			:schema="objectSidebarState.schema"
			:hidden-tabs="objectSidebarState.hiddenTabs"
			:open="objectSidebarState.open"
			@update:open="objectSidebarState.open = $event" />
		<Modals />
		<Dialogs />
	</NcContent>
</template>

<script>

import Vue from 'vue'
import { NcContent } from '@nextcloud/vue'
import { CnObjectSidebar } from '@conduction/nextcloud-vue'
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
		CnObjectSidebar,
		MainMenu,
		Modals,
		Dialogs,
		Views,
		SideBars,
	},
	provide() {
		return {
			objectSidebarState: this.objectSidebarState,
		}
	},
	data() {
		return {
			objectSidebarState: Vue.observable({
				active: false,
				open: true,
				objectType: '',
				objectId: '',
				title: '',
				subtitle: '',
				register: '',
				schema: '',
				hiddenTabs: [],
			}),
		}
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
