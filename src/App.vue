<template>
	<CnAppRoot
		appId="openregister"
		:aiCompanion="true"
		:manifest="manifest"
		:registry="registry"
		:pageTypes="pageTypes"
		:requiresApps="[]"
		:translate="translateForApp">
		<!--
			Keep OpenRegister's own MainMenu as the #menu override. It wraps
			CnAppNav and renders the active-organisation switcher in CnAppNav's
			#primary-action slot — live store state the manifest's static
			nav.primaryAction cannot express.
		-->
		<template #menu>
			<MainMenu />
		</template>
		<!--
			Right-rail sidebars rendered at NcContent level (the only place
			NcAppSidebar slides correctly from the right). SideBars picks a
			route-specific sidebar; CnObjectSidebar opens per-object.

			use-registry=true switches CnObjectSidebar onto the registry-driven
			path: it reads OCA.OpenRegister.integrations and renders one inner
			tab per provider (built-ins + xwiki + the bespoke leaves). See ADR-019.
		-->
		<template #sidebar>
			<SideBars />
			<CnObjectSidebar
				v-if="objectSidebarState.active"
				:useRegistry="true"
				:title="objectSidebarState.title"
				:subtitle="objectSidebarState.subtitle"
				:objectType="objectSidebarState.objectType"
				:objectId="objectSidebarState.objectId"
				:register="objectSidebarState.register"
				:schema="objectSidebarState.schema"
				:hiddenTabs="objectSidebarState.hiddenTabs"
				:open="objectSidebarState.open"
				@update:open="objectSidebarState.open = $event" />
		</template>
		<!-- Global modal and dialog hosts, mounted below the router-view. -->
		<template #footer>
			<Modals />
			<Dialogs />
		</template>
	</CnAppRoot>
</template>

<script>
import { CnAppRoot, CnObjectSidebar } from '@conduction/nextcloud-vue'
import { translate as ncT } from '@nextcloud/l10n'
import { reactive } from 'vue'
import Dialogs from './dialogs/Dialogs.vue'
import Modals from './modals/Modals.vue'
import MainMenu from './navigation/MainMenu.vue'
import SideBars from './sidebars/SideBars.vue'
import { initializeAppData } from './services/AppInitializationService.js'
import { setupDashboardStoreWatchers } from './store/modules/dashboard.js'

export default {
	name: 'App',
	components: {
		CnAppRoot,
		CnObjectSidebar,
		MainMenu,
		Modals,
		Dialogs,
		SideBars,
	},

	/**
	 * Expose the shared object-sidebar state to descendant components.
	 *
	 * @return {object} The provided injectables.
	 */
	provide() {
		return {
			objectSidebarState: this.objectSidebarState,
		}
	},

	props: {
		/**
		 * Bundled manifest — passed from main.js. CnAppRoot reads
		 * `manifest.dependencies` (empty) and `manifest.menu` for the default
		 * CnAppNav; the #menu override uses OpenRegister's own MainMenu.
		 */
		manifest: {
			type: Object,
			required: true,
		},

		/**
		 * V2 kind-tagged registry (ADR-036) — each entry is
		 * `{ kind: "page", component: ... }`. CnPageRenderer resolves
		 * every `type:"custom"` page's `component` string against the
		 * `kind: "page"` entries here. Replaces the deprecated
		 * `customComponents` prop.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Page-type registry (`defaultPageTypes`) wired through to descendant
		 * CnPageRenderer instances via CnAppRoot's provide/inject.
		 */
		pageTypes: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			objectSidebarState: reactive({
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

	/**
	 * On mount, kick off application-data hot-loading and dashboard watchers.
	 *
	 * @return {void}
	 */
	mounted() {
		// Initialize hot-loading of essential application data
		// This loads registers, schemas, organisations, applications, views, agents, sources, and conversations
		initializeAppData()

		// Set up dashboard store watchers to keep dashboard data in sync, after stores are reactive
		setupDashboardStoreWatchers()
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud translate import so the lib
		 * never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 */
		translateForApp(key) {
			return ncT('openregister', key)
		},
	},
}
</script>
