import {
	buildManifest,
	CnPageRenderer,
	defaultPageTypes,
	registerBuiltinDashboardWidgets,
	registerIcons,
} from '@conduction/nextcloud-vue'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp, h } from 'vue'
// eslint-disable-next-line n/no-unpublished-import
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import appIcons from './icons.js'
import { ensureIntegrationRegistry } from './integrations/bootstrap.js'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import pinia from './pinia.js'
import registry from './registry.js'

import '@conduction/nextcloud-vue/css/index.css'
import 'gridstack/dist/gridstack.min.css'

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
// Providers that are advertised in OCS capabilities but are NOT in nc-vue's
// leafIntegrations[], so nothing else registers them JS-side. Each gets a
// generic descriptor here; the tab + widget come from the registry's
// resolveWidget AD-19 fallback to the default CnIntegrationTab /
// CnIntegrationCard set via the leaf descriptor.
//
// The OCS side MUST be a subset of the JS side — a provider the backend
// advertises but the frontend cannot render is an integration a user is
// offered and then finds does nothing. `kvk` and `opencorporates` were added
// PHP-side (CompanyLookupController + KvkProvider / OpenCorporatesProvider)
// without descriptors, and decidesk's integration-registry e2e has been red on
// `development` ever since, naming exactly those two.
//
// A list rather than three copied blocks: the next provider added PHP-side
// needs one line here, and the omission that caused this drift is harder to
// repeat.
const GENERIC_INTEGRATION_DESCRIPTORS = [
	{
		// Ships a richer dedicated tab (CnXwikiTab) that consumer apps register
		// separately, which is why it is absent from leafIntegrations[].
		id: 'xwiki',
		label: t('openregister', 'Articles'),
		icon: 'FileDocumentMultiple',
		order: 31,
		referenceType: 'xwiki',
	},
	{
		id: 'kvk',
		label: t('openregister', 'KvK Company Register'),
		icon: 'OfficeBuilding',
		order: 32,
		referenceType: 'kvk',
	},
	{
		id: 'opencorporates',
		// Not wrapped in t(): the label is the bare product name. No locale has ever
		// carried a value differing from it — the app's own backend catalogue leaves
		// it English in all 37 — whereas "KvK Company Register" above is real prose
		// and is translated in twelve of them.
		label: 'OpenCorporates',
		icon: 'OfficeBuildingOutline',
		order: 33,
		referenceType: 'opencorporates',
	},
]

try {
	const registry = window?.OCA?.OpenRegister?.integrations
	const pending = registry?.register
		? GENERIC_INTEGRATION_DESCRIPTORS.filter((d) => !registry.has?.(d.id))
		: []
	if (pending.length > 0) {
		import('@conduction/nextcloud-vue')
			.then(({ CnIntegrationTab, CnIntegrationCard }) => {
				pending.forEach((descriptor) => {
					registry.register({
						...descriptor,
						requiredApp: 'openconnector',
						group: 'external',
						tab: CnIntegrationTab,
						widget: CnIntegrationCard,
						defaultSize: { w: 4, h: 3 },
					})
				})
			})
			.catch((e) =>
				console.error(
					'[main] failed to register generic integration descriptors',
					e,
				),
			)
	}
} catch (e) {
	console.error('[main] integration registry guard failed', e)
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
// `require.context` is a WEBPACK build-time API, not CommonJS `require`: the
// bundler rewrites this call at compile time and no `require` exists at
// runtime. eslint's browser globals therefore report `no-undef` correctly —
// the code is right and the linter is right. Scoped to this one identifier so
// a genuinely undefined name elsewhere in the file still fails.
/* global require */
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx
	.keys()
	.sort()
	.map((key) => fragmentCtx(key))
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

/**
 * The router base for THIS page load.
 *
 * ⚠️ `generateUrl('/apps/openregister')` alone is not enough. Nextcloud serves
 * the app under BOTH `/apps/openregister/...` and
 * `/index.php/apps/openregister/...`, but `generateUrl()` returns only the form
 * the instance is configured for. A visitor arriving on the other form falls
 * outside the router base, vue-router cannot resolve the path, and the
 * catch-all above redirects to `/`: they land on the Dashboard with no error
 * and the deep link is silently swallowed. This app's e2e harness deep-links
 * via the `/index.php` form, so pinning the other one would break every one of
 * those.
 *
 * @return {string} The base path vue-router should strip from the URL.
 */
function routerBase() {
	const match = window.location.pathname.match(/^(.*\/apps\/openregister)(?:\/|$)/)
	return match ? match[1] : generateUrl('/apps/openregister')
}

// History mode. This app ran on HASH mode as a workaround for #133: the PHP
// backend registered exactly one frontend route — `dashboard#page` at `/` — and
// no catch-all serving the SPA shell for deep sub-paths like `/registers` or
// `/schemas`, so a bookmark or full-page load never reached the SPA and the
// grouped index pages rendered empty (no Add button, no list).
//
// `dashboard#catchAll` in appinfo/routes.php now serves the shell on any
// sub-path, which removes the cause rather than working around it. Verified by
// requesting a NONSENSE path: /apps/openregister/zzz-nonsense answered 404
// before and 401 after — an enumerated path would have proved nothing.
const router = createRouter({
	history: createWebHistory(routerBase()),
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
	render: () =>
		h(App, {
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
