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

/**
 * App Root Component
 *
 * @category Component
 * @package OpenRegister
 * @author Ruben Linde
 * @copyright 2024 Ruben Linde
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://github.com/your-repo/openregister
 */
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
		// Set up dashboard store watchers after stores are reactive
		setupDashboardStoreWatchers()
	},
}
</script>
