import Vue from 'vue'
// eslint-disable-next-line n/no-unpublished-import
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import pinia from './pinia.js'
import App from './App.vue'
import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	buildManifest,
} from '@conduction/nextcloud-vue'
import '@conduction/nextcloud-vue/css/index.css'
import { Fragment } from 'vue-frag'
import { ensureIntegrationRegistry } from './integrations/bootstrap.js'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import registry from './registry.js'

import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'

// Navigation icons — registered by name so CnAppNav (manifest-driven
// MainMenu) can resolve each menu item's `icon` against ICON_MAP.
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import RobotOutline from 'vue-material-design-icons/RobotOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import DeleteRestore from 'vue-material-design-icons/DeleteRestore.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'
import ShieldLockOutline from 'vue-material-design-icons/ShieldLockOutline.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import Api from 'vue-material-design-icons/Api.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import ContentDuplicate from 'vue-material-design-icons/ContentDuplicate.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import Merge from 'vue-material-design-icons/Merge.vue'

// Install the in-page integration registry on window.OCA.OpenRegister and
// pre-register the 5 always-on built-ins (files/notes/tags/tasks/audit) plus
// the 18 leaf descriptors. OR is the producer of this registry — every
// consumer app's bootstrap reaches into the same singleton — so registering
// the defaults here means object-detail surfaces inside OR itself exercise
// the full set without depending on a consumer app's wiring.
//
// Idempotent — the same helper is also invoked from settings.js,
// files-sidebar.js and mail-sidebar.js so the registry is consistently
// populated regardless of which entry bundle loaded first on the page.
ensureIntegrationRegistry()
// xwiki is intentionally NOT in nc-vue's leafIntegrations[] — it ships with
// a richer dedicated tab (CnXwikiTab) that consumer apps register separately.
// For the per-leaf verification harness we want all 24 advertised providers
// in the JS registry, so register a generic descriptor here. The tab + widget
// components come from the registry's resolveWidget AD-19 fallback to the
// default CnIntegrationTab / CnIntegrationCard set via the leaf descriptor.
try {
	const xwikiAlreadyRegistered = window?.OCA?.OpenRegister?.integrations?.has?.('xwiki')
	if (window?.OCA?.OpenRegister?.integrations?.register && !xwikiAlreadyRegistered) {
		import('@conduction/nextcloud-vue').then(({ CnIntegrationTab, CnIntegrationCard }) => {
			window.OCA.OpenRegister.integrations.register({
				id: 'xwiki',
				label: t('openregister', 'Articles'),
				icon: 'FileDocumentMultiple',
				requiredApp: 'openconnector',
				order: 31,
				group: 'external',
				referenceType: 'xwiki',
				tab: CnIntegrationTab,
				widget: CnIntegrationCard,
				defaultSize: { w: 4, h: 3 },
			})
		}).catch(e => console.error('[main] failed to register xwiki descriptor', e))
	}
} catch (e) {
	console.error('[main] xwiki registry guard failed', e)
}

registerIcons({
	AccountGroupOutline,
	FileDocumentOutline,
	Cog,
	CogOutline,
	// Navigation icons (manifest menu items resolve these by name)
	MessageTextOutline,
	DatabaseOutline,
	FileTreeOutline,
	FileOutline,
	Magnify,
	FileDocumentMultipleOutline,
	RobotOutline,
	InformationOutline,
	MapMarkerPath,
	OfficeBuildingOutline,
	ApplicationOutline,
	DatabaseArrowRightOutline,
	AccountOutline,
	DeleteRestore,
	TextBoxOutline,
	MagnifyPlus,
	Webhook,
	ShieldLockOutline,
	ChartBoxOutline,
	Api,
	ViewDashboardOutline,
	ContentDuplicate,
	AccountMultipleOutline,
	Merge,
})

Vue.mixin({ methods: { t, n } })

Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)
Vue.directive('tooltip', Tooltip)

Vue.component('Fragment', Fragment)

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records). Vue 2's `Vue.extend()` adds an
// internal `_Ctor` cache to the component definition; mutating a non-extensible
// export throws "Cannot add property _Ctor, object is not extensible". Cloning
// gives Vue Router an extensible component-options object without altering the
// lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

// The manifest→menu pipeline (fragment merge, canonical relocations, duplicate
// removals, and promotion of config/integration entries into the settings
// foldout) lives in @conduction/nextcloud-vue's buildManifest(), so every
// manifest-v2 app shares one implementation. See src/menu-layout.json for this
// app's relocations / removals / settingsSection.

// Collect the app's manifest.d/*.json fragments — require.context is resolved
// by this app's own webpack build, so it stays app-local — then hand the base
// manifest, fragments, and menu-layout to the shared pipeline.
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

/**
 * Build the vue-router config from the manifest. Each manifest page becomes one
 * route whose `name` IS `page.id` (the lib's contract — CnPageRenderer matches
 * `$route.name === page.id`). Pages whose `route` declares a `:` parameter get
 * `props: true` so route params reach the dispatched component.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to the dashboard, preserving prior router behaviour.
	routes.push({ path: '*', redirect: '/' })
	return routes
}

// Hash mode (not history): the PHP backend registers exactly one frontend
// route — `dashboard#page` at `/` (appinfo/routes.php) — and no catch-all that
// serves the SPA shell for deep sub-paths like `/registers` or `/schemas`. In
// history mode a full-page load or bookmark to `#/registers` drops the fragment
// and resolves the base path `/` → the Dashboard surface, so the relocated /
// grouped index pages render empty (no Add button, no list) on deep-link — the
// #133 regression. Hash mode keeps every route under the single `/` server
// route, so `#/registers` etc. resolve client-side to their correct index
// surface. This also matches the e2e harness contract (tests deep-link via
// `/index.php/apps/openregister/#/<route>`).
const router = new VueRouter({
	mode: 'hash',
	routes: routesFromManifest(mergedManifest),
})

// Pass shallow copies of the registry maps to App.vue → CnAppRoot. The lib
// exports `defaultPageTypes` (and our `registry`) as frozen module objects in
// some bundle shapes — Vue 2's `Vue.extend()` mutates component definitions to
// attach an internal `_Ctor` cache, which throws "Cannot add property _Ctor,
// object is not extensible" against a frozen source map. Cloning here yields
// extensible objects without changing the values the lib resolves at render
// time.
const registryProp = { ...registry }
const pageTypesProp = { ...defaultPageTypes }

new Vue(
	{
		pinia,
		router,
		render: h => h(App, {
			props: {
				manifest: mergedManifest,
				registry: registryProp,
				pageTypes: pageTypesProp,
			},
		}),
	},
).$mount('#content')
