/**
 * Files Sidebar Tab Entry Point
 *
 * Registers OpenRegister sidebar tabs in the Nextcloud Files app sidebar.
 * This script is loaded only when the Files app is active, via the
 * FilesSidebarListener event listener.
 *
 * @license EUPL-1.2
 */

import Vue from 'vue'
import { translate as t } from '@nextcloud/l10n'

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
		name: t('openregister', 'Register Objects'),
		icon: databaseOutlineIcon,

		async mount(el, fileInfo, _context) {
			if (el._registerObjectsVm) {
				el._registerObjectsVm.$destroy()
			}

			const { default: RegisterObjectsTab } = await import(
				/* webpackChunkName: "files-sidebar-objects-tab" */
				'./components/files-sidebar/RegisterObjectsTab.vue'
			)

			const View = Vue.extend(RegisterObjectsTab)
			el._registerObjectsVm = new View({
				propsData: {
					fileId: fileInfo.id,
				},
			})
			el._registerObjectsVm.$mount(el)
		},

		async update(el, fileInfo) {
			if (el._registerObjectsVm) {
				el._registerObjectsVm.fileId = fileInfo.id
			}
		},

		destroy(el) {
			if (el._registerObjectsVm) {
				el._registerObjectsVm.$destroy()
				el._registerObjectsVm = null
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

		async mount(el, fileInfo, _context) {
			if (el._extractionVm) {
				el._extractionVm.$destroy()
			}

			const { default: ExtractionTab } = await import(
				/* webpackChunkName: "files-sidebar-extraction-tab" */
				'./components/files-sidebar/ExtractionTab.vue'
			)

			const View = Vue.extend(ExtractionTab)
			el._extractionVm = new View({
				propsData: {
					fileId: fileInfo.id,
				},
			})
			el._extractionVm.$mount(el)
		},

		async update(el, fileInfo) {
			if (el._extractionVm) {
				el._extractionVm.fileId = fileInfo.id
			}
		},

		destroy(el) {
			if (el._extractionVm) {
				el._extractionVm.$destroy()
				el._extractionVm = null
			}
		},

		enabled(fileInfo) {
			return !!fileInfo
		},
	}))
})
