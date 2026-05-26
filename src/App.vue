<template>
	<NcContent app-name="openregister">
		<MainMenu />
		<Views />
		<SideBars />
		<!--
			use-registry=true switches CnObjectSidebar off the legacy
			hardcoded-5-tabs branch onto the registry-driven path: it
			reads OCA.OpenRegister.integrations and renders one inner
			tab per provider (built-ins + xwiki + the 11 bespoke leaves
			from feature/integration-leaves-consolidated). Without this
			flag the 18 leaf descriptors are filtered out at line ~480
			of CnObjectSidebar.vue and never reach the template.
			See ADR-019.
		-->
		<CnObjectSidebar
			v-if="objectSidebarState.active"
			:use-registry="true"
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
		<!--
			First-open support note. OpenRegister mounts its own root
			(not CnAppRoot yet), so the dialog is wired manually here:
			useSupportDialog resolves the per-user "seen" flag from
			/api/preferences/support-dialog-seen (localStorage fallback).
			Swap to CnAppRoot's built-in auto-mount once the shell
			migration lands and drop this block.
		-->
		<CnSupportDialog
			v-if="supportVisible"
			app-name="OpenRegister"
			app-slug="openregister"
			app-store-url="https://apps.nextcloud.com/apps/openregister"
			feature-request-url="https://github.com/ConductionNL/openregister/issues/new"
			@close="supportHide" />
	</NcContent>
</template>

<script>

import Vue from 'vue'
import { NcContent } from '@nextcloud/vue'
import { CnObjectSidebar, CnSupportDialog, useSupportDialog } from '@conduction/nextcloud-vue'
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
		CnSupportDialog,
		MainMenu,
		Modals,
		Dialogs,
		Views,
		SideBars,
	},
	/**
	 * Wire the first-open support note's per-user persistence (server
	 * preferences endpoint, localStorage fallback). Manual mount because
	 * OpenRegister does not use CnAppRoot yet.
	 *
	 * @return {object} `{ supportVisible, supportHide }`.
	 */
	setup() {
		const { visible, hide } = useSupportDialog('openregister', { persistence: 'server' })
		return { supportVisible: visible, supportHide: hide }
	},
	/**
	 * Expose the shared object-sidebar state to descendant components.
	 *
	 * @return {object} The provided injectables.
	 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-8
	 */
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
	/**
	 * On mount, kick off application-data hot-loading and dashboard watchers.
	 *
	 * @return {void}
	 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-8
	 */
	mounted() {
		// Initialize hot-loading of essential application data
		// This loads registers, schemas, organisations, applications, views, agents, sources, and conversations
		initializeAppData()

		// Set up dashboard store watchers to keep dashboard data in sync, after stores are reactive
		setupDashboardStoreWatchers()
	},
}
</script>
