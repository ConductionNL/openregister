/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A Files sidebar tab that is already open follows the file the user selects.
 *
 * @spec exclude Unit test of the client-side Files sidebar entry point.
 */
import { nextTick } from 'vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: jest.fn(),
}))
jest.mock('./components/files-sidebar/RegisterObjectsTab.vue', () => ({
	name: 'RegisterObjectsTab',
	props: ['fileId'],
	template: '<p class="objects">{{ fileId }}</p>',
}))
jest.mock('./components/files-sidebar/ExtractionTab.vue', () => ({
	name: 'ExtractionTab',
	props: ['fileId'],
	template: '<p class="extraction">{{ fileId }}</p>',
}))
jest.mock('./integrations/bootstrap.js', () => ({
	ensureIntegrationRegistry: jest.fn(),
}))
jest.mock('./services/libraryTranslations.js', () => ({
	registerLibraryTranslations: jest.fn(),
}))

const tabs = []

beforeAll(() => {
	global.OCA = {
		Files: {
			Sidebar: {
				Tab: function Tab(options) {
					Object.assign(this, options)
				},
				registerTab: (tab) => tabs.push(tab),
			},
		},
	}
	require('./files-sidebar.js')
	document.dispatchEvent(new Event('DOMContentLoaded'))
})
afterAll(() => {
	delete global.OCA
})

describe('files-sidebar tab update', () => {
	it.each([
		[0, 'objects'],
		[1, 'extraction'],
	])('tab %i re-renders for the newly selected file', async (index, cls) => {
		const el = document.createElement('div')
		tabs[index].mount(el, { id: 5 })
		expect(el.querySelector(`.${cls}`).textContent).toBe('5')

		await tabs[index].update(el, { id: 6 })
		await nextTick()

		expect(el.querySelector(`.${cls}`).textContent).toBe('6')
		expect(el.querySelector(`.${cls}`).parentNode).toBe(el)
		tabs[index].destroy(el)
	})
})
