import { createApp, h } from 'vue'
// eslint-disable-next-line n/no-unpublished-import
import { createRouter, createWebHashHistory } from 'vue-router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import pinia from './pinia.js'
import App from './App.vue'
import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	buildManifest,
	registerBuiltinDashboardWidgets,
} from '@conduction/nextcloud-vue'
import '@conduction/nextcloud-vue/css/index.css'
import 'gridstack/dist/gridstack.min.css'
import { ensureIntegrationRegistry } from './integrations/bootstrap.js'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import registry from './registry.js'
import appIcons from './icons.js'

// Navigation icons — registered by name so CnAppNav (manifest-driven
// MainMenu) can resolve each menu item's `icon` against ICON_MAP.

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

registerIcons(appIcons)

// nc-vue declares `sideEffects: ["**/*.css", …]`, which lets webpack drop the
// bare imports that register the built-in dashboard widgets. Without this call
// the `stat` and `object-table` widgets render "Widget not available" while
// `chart` (registered inline) still works — an asymmetry that took a while to
// identify on larpingapp. Call it explicitly at bootstrap.
registerBuiltinDashboardWidgets()

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records) and vue-router stores the
// component options object it is handed. Cloning gives the router an
// extensible object without altering the lib's internals.
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
 * @return {Array<object>} vue-router 4/5 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to the dashboard, preserving prior router behaviour.
	//
	// ⚠️ vue-router 4 REMOVED the bare `path: '*'` wildcard. It does not error —
	// it simply never matches, so an unknown route renders the shell with an
	// empty <main> and no console output. The named-param form is the
	// replacement.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
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
const router = createRouter({
	history: createWebHashHistory(),
	routes: routesFromManifest(mergedManifest),
})

// Pass shallow copies of the registry maps to App.vue → CnAppRoot. The lib
// exports `defaultPageTypes` (and our `registry`) FROZEN in some bundle shapes,
// and anything that later attaches per-component caches to them throws
// "object is not extensible". Cloning here yields extensible objects without
// changing the values the lib resolves at render time.
const registryProp = { ...registry }
const pageTypesProp = { ...defaultPageTypes }

const app = createApp({
	render: () => h(App, {
		manifest: mergedManifest,
		registry: registryProp,
		pageTypes: pageTypesProp,
	}),
})

app.mixin({ methods: { t, n } })
app.use(pinia)
app.use(router)

// ⚠️ Mount on the app's OWN host element (templates/index.php renders
// `<div id="openregister">`), NOT on `#content`.
//
// Vue 2's `$mount('#content')` REPLACED Nextcloud's own `layout.user.php`
// wrapper. Vue 3's `mount()` renders INSIDE the matched element instead, so
// mounting `#content` would nest the whole app inside core's wrapper and leave
// the template's own container empty. Naming the app's element sidesteps the
// question of which div wins entirely.
app.mount('#openregister')
