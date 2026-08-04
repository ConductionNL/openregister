/**
 * Files Sidebar Tab Entry Point
 *
 * Registers OpenRegister sidebar tabs in the Nextcloud Files app sidebar.
 * This script is loaded only when the Files app is active, via the
 * FilesSidebarListener event listener.
 *
 * @license EUPL-1.2
 */

import { createApp, reactive } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { ensureIntegrationRegistry } from './integrations/bootstrap.js'
import RegisterObjectsTab from './components/files-sidebar/RegisterObjectsTab.vue'
import ExtractionTab from './components/files-sidebar/ExtractionTab.vue'

// Bootstrap the integration registry on the files-sidebar bundle so any
// tab component that uses useIntegrationRegistry() sees the same populated
// singleton main.js produced on a non-Files page. Idempotent. See ADR-019.
ensureIntegrationRegistry()

// MDI icon SVG paths (inline to avoid icon library dependency).
// database-outline
const databaseOutlineIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 3C7.58 3 4 4.79 4 7V17C4 19.21 7.59 21 12 21S20 19.21 20 17V7C20 4.79 16.42 3 12 3M18 17C18 17.5 15.87 19 12 19S6 17.5 6 17V14.77C7.61 15.55 9.72 16 12 16S16.39 15.55 18 14.77V17M18 12.45C16.7 13.4 14.42 14 12 14C9.58 14 7.3 13.4 6 12.45V9.64C7.47 10.47 9.61 11 12 11C14.39 11 16.53 10.47 18 9.64V12.45M12 9C8.13 9 6 7.5 6 7S8.13 5 12 5C15.87 5 18 6.5 18 7S15.87 9 12 9Z" /></svg>'

// text-box-search-outline
const textBoxSearchOutlineIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M15.5 12C18 12 20 14 20 16.5C20 17.38 19.75 18.21 19.31 18.9L22.39 22L21 23.39L17.88 20.32C17.19 20.75 16.37 21 15.5 21C13 21 11 19 11 16.5C11 14 13 12 15.5 12M15.5 14C14.12 14 13 15.12 13 16.5C13 17.88 14.12 19 15.5 19C16.88 19 18 17.88 18 16.5C18 15.12 16.88 14 15.5 14M5 3H19C20.11 3 21 3.89 21 5V13.03C20.5 12.23 19.81 11.54 19 11V5H5V19H9.5C9.81 19.75 10.26 20.42 10.81 21H5C3.89 21 3 20.11 3 19V5C3 3.89 3.89 3 5 3M7 7H17V9H7V7M7 11H12.03C11.23 11.5 10.54 12.19 10 13H7V11M7 15H9.17C9.06 15.5 9 16 9 16.5V17H7V15Z" /></svg>'

/**
 * Register the OpenRegister sidebar tabs in the Files app.
 *
 * Uses the OCA.Files.Sidebar.registerTab() API following the mount/update/destroy
 * lifecycle pattern used by core Nextcloud tabs (comments, versions).
 */
document.addEventListener('DOMContentLoaded', () => {
	// Guard: exit gracefully if the Files sidebar API is unavailable
	// (e.g. public share pages without sidebar).
	if (!OCA?.Files?.Sidebar) {
		return
	}

	// Register Objects Tab
	OCA.Files.Sidebar.registerTab(new OCA.Files.Sidebar.Tab({
		id: 'openregister-objects',
		name: t('openregister', 'Register objects'),
		icon: databaseOutlineIcon,

		// Vue 3: `createApp(Component, rootProps).mount(el)` replaces
		// `Vue.extend()` + `new View({ propsData }).$mount(el)`.
		//
		// The root props object is kept `reactive` and stashed on the element so
		// `update()` can still push a new fileId into the live instance. Vue 2
		// allowed `vm.fileId = …` directly on the instance; Vue 3 root props are
		// read-only on the component, so the mutation has to happen on the
		// reactive object the app was created with.
		//
		// ⚠️ `$mount(el)` REPLACED `el`; Vue 3's `mount(el)` renders INSIDE it.
		// That is the correct behaviour here — the Files sidebar owns `el`.
		mount(el, fileInfo, _context) {
			if (el._registerObjectsApp) {
				el._registerObjectsApp.unmount()
			}

			el._registerObjectsProps = reactive({ fileId: fileInfo.id })
			el._registerObjectsApp = createApp(RegisterObjectsTab, el._registerObjectsProps)
			// ⚠️ Vue 2's `Vue.mixin()` was GLOBAL, so main.js's single call
			// reached every instance on the page. Vue 3's `app.mixin()` is
			// per-app — each entry bundle must install `t`/`n` itself.
			el._registerObjectsApp.mixin({ methods: { t, n } })
			el._registerObjectsApp.mount(el)
		},

		async update(el, fileInfo) {
			if (el._registerObjectsProps) {
				el._registerObjectsProps.fileId = fileInfo.id
			}
		},

		destroy(el) {
			if (el._registerObjectsApp) {
				el._registerObjectsApp.unmount()
				el._registerObjectsApp = null
				el._registerObjectsProps = null
			}
		},

		enabled(fileInfo) {
			return !!fileInfo
		},
	}))

	// Extraction & Metadata Tab
	OCA.Files.Sidebar.registerTab(new OCA.Files.Sidebar.Tab({
		id: 'openregister-extraction',
		name: t('openregister', 'Extraction'),
		icon: textBoxSearchOutlineIcon,

		mount(el, fileInfo, _context) {
			if (el._extractionApp) {
				el._extractionApp.unmount()
			}

			el._extractionProps = reactive({ fileId: fileInfo.id })
			el._extractionApp = createApp(ExtractionTab, el._extractionProps)
			el._extractionApp.mixin({ methods: { t, n } })
			el._extractionApp.mount(el)
		},

		async update(el, fileInfo) {
			if (el._extractionProps) {
				el._extractionProps.fileId = fileInfo.id
			}
		},

		destroy(el) {
			if (el._extractionApp) {
				el._extractionApp.unmount()
				el._extractionApp = null
				el._extractionProps = null
			}
		},

		enabled(fileInfo) {
			return !!fileInfo
		},
	}))
})
