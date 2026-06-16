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

/**
 * Merge an array of incoming menu items into a target array, keyed by `id`.
 * New ids are appended; existing ids are merged in place: the first definition
 * of `label` / `icon` / `route` / `order` (etc.) wins — the base manifest loads
 * first, so its canonical group definitions take precedence — and `children`
 * are unioned recursively by the same rule. A fragment may therefore extend an
 * existing group by re-declaring only its `id` plus its own `children`.
 *
 * @param {Array<object>} target The accumulated menu (mutated in place).
 * @param {Array<object>} incoming Menu items from a fragment.
 * @return {void}
 */
function mergeMenuItems(target, incoming) {
	incoming.forEach((item) => {
		const existing = target.find((t) => t.id === item.id)
		if (!existing) {
			target.push({ ...item, children: Array.isArray(item.children) ? [...item.children] : item.children })
			return
		}
		for (const key of ['label', 'icon', 'route', 'order', 'section', 'featureFlag', 'permission', 'visibleIf', 'href', 'action']) {
			if (existing[key] === undefined && item[key] !== undefined) {
				existing[key] = item[key]
			}
		}
		if (Array.isArray(item.children) && item.children.length > 0) {
			if (!Array.isArray(existing.children)) {
				existing.children = []
			}
			mergeMenuItems(existing.children, item.children)
		}
	})
}

/**
 * Merge fragment pages onto the accumulated page list by `id` — a later
 * declaration REPLACES an earlier one wholesale (overlay semantic per ADR-037).
 *
 * @param {Array<object>} target Accumulated pages (mutated in place).
 * @param {Array<object>} incoming Pages from a fragment.
 * @return {void}
 */
function mergePages(target, incoming) {
	incoming.forEach((page) => {
		const idx = target.findIndex((p) => p.id === page.id)
		if (idx === -1) {
			target.push(page)
		} else {
			target[idx] = page
		}
	})
}

/**
 * Re-home merged menu entries onto the canonical navigation layout declared by
 * `src/menu-layout.json#relocations` (`{ sourceId: targetGroupId }`).
 *
 * Fragments stay the canonical source of WHAT exists in the menu (per ADR-037
 * they drop entries wherever their change authored them); this map is the
 * single place that decides WHERE entries live, so the navigation can be
 * consolidated without rewriting fragments:
 *
 *  - A relocated GROUP dissolves: its children merge (by id) into the target
 *    group and the now-empty shell is dropped.
 *  - A relocated LEAF (top-level or child of any group) moves under the target
 *    group.
 *  - A child group relocated onto its own parent flattens into it.
 *  - Unknown source ids are inert; a missing target group keeps the entry at
 *    the top level so nothing silently disappears.
 *
 * Runs in passes until stable (children freed by a dissolved group can
 * themselves be relocated on the next pass).
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Record<string, string>|undefined} relocations Source-id → target-group-id map.
 * @return {Array<object>} The menu with relocations applied.
 */
function applyMenuRelocations(menu, relocations) {
	if (!relocations || typeof relocations !== 'object') return menu
	for (let pass = 0; pass < 5; pass++) {
		const moves = []
		for (let i = menu.length - 1; i >= 0; i--) {
			const node = menu[i]
			const target = relocations[node.id]
			if (target && target !== node.id) {
				menu.splice(i, 1)
				moves.push({ node, target })
				continue
			}
			if (!Array.isArray(node.children)) continue
			for (let j = node.children.length - 1; j >= 0; j--) {
				const child = node.children[j]
				const childTarget = relocations[child.id]
				if (!childTarget) continue
				if (childTarget === node.id && !Array.isArray(child.children)) continue
				node.children.splice(j, 1)
				moves.push({ node: child, target: childTarget })
			}
		}
		if (moves.length === 0) break
		moves.forEach(({ node, target }) => {
			const group = menu.find((m) => m.id === target)
			if (!group) {
				menu.push(node)
				return
			}
			if (!Array.isArray(group.children)) group.children = []
			if (Array.isArray(node.children)) {
				mergeMenuItems(group.children, node.children)
			} else {
				mergeMenuItems(group.children, [node])
			}
		})
	}
	return menu.filter((m) => m.route || m.href || m.action
		|| (Array.isArray(m.children) && m.children.length > 0))
}

/**
 * Remove individual menu entries by id after relocation — used to retire
 * duplicate navigation entries whose PAGE must stay routable (deep links and
 * e2e specs hit the route directly). Declared in `src/menu-layout.json#removals`.
 * Only leaf entries are removed; group ids are ignored so a removal can never
 * silently hide a whole cluster.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Array<string>|undefined} removals Menu-entry ids to drop.
 * @return {Array<object>} The menu without the removed entries.
 */
function applyMenuRemovals(menu, removals) {
	if (!Array.isArray(removals) || removals.length === 0) return menu
	const drop = new Set(removals)
	const isLeaf = (n) => !Array.isArray(n.children) || n.children.length === 0
	menu.forEach((node) => {
		if (Array.isArray(node.children)) {
			node.children = node.children.filter((c) => !(drop.has(c.id) && isLeaf(c)))
		}
	})
	return menu.filter((node) => !(drop.has(node.id) && isLeaf(node)))
}

/**
 * ADR-037: merge modular manifest fragments from src/manifest.d/*.json onto the
 * bundled base manifest. Each OpenSpec change drops its own fragment (pages/menu)
 * instead of editing the monolith src/manifest.json, so concurrent builds touch
 * disjoint files. `pages` are merged by `id` (later replaces earlier); `menu`
 * items are merged by `id` (top-level and children) so fragments that re-declare
 * an existing group extend it instead of duplicating it. After merging,
 * src/menu-layout.json relocations and removals are applied to consolidate
 * entries into their canonical navigation clusters.
 *
 * @param {object} base The bundled base manifest.
 * @return {object} The manifest with all fragment pages/menu merged in.
 */
function mergeManifestFragments(base) {
	const merged = { ...base, pages: [...(base.pages || [])], menu: [] }
	mergeMenuItems(merged.menu, base.menu || [])
	// require.context is resolved at build time; src/manifest.d/ must exist (it
	// ships with a _placeholder.json). It is a no-op when the directory holds no
	// real fragments.
	const ctx = require.context('./manifest.d/', false, /\.json$/)
	ctx.keys().sort().forEach((key) => {
		const frag = ctx(key)
		if (Array.isArray(frag.pages)) {
			mergePages(merged.pages, frag.pages)
		}
		if (Array.isArray(frag.menu)) {
			mergeMenuItems(merged.menu, frag.menu)
		}
	})
	merged.menu = applyMenuRelocations(merged.menu, menuLayout.relocations)
	merged.menu = applyMenuRemovals(merged.menu, menuLayout.removals)
	return merged
}

const mergedManifest = mergeManifestFragments(bundledManifest)

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
