/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Files sidebar entry: the three tabs it registers and their lifecycle.
 *
 * @spec exclude Unit test of the client-side Files sidebar entry point.
 */
import { isReactive } from 'vue'

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
const mockRegisterAttach = jest.fn()
jest.mock('./files/attachAction.js', () => ({
	registerAttachAction: () => mockRegisterAttach(),
}))
jest.mock('./components/files-sidebar/MailThreadTab.vue', () => ({
	name: 'MailThreadTab',
	props: ['fileId'],
	template: '<p class="thread">{{ fileId }}</p>',
}))
const mockEnsure = jest.fn()
jest.mock('./integrations/bootstrap.js', () => ({
	ensureIntegrationRegistry: () => mockEnsure(),
}))
const mockRegister = jest.fn()
jest.mock('./services/libraryTranslations.js', () => ({
	registerLibraryTranslations: () => mockRegister(),
}))

/**
 * Let the lazily loaded tab components resolve and render.
 *
 * @return {Promise<void>}
 */
async function settle() {
	for (let i = 0; i < 10; i++) {
		await Promise.resolve()
	}
	await new Promise((resolve) => setTimeout(resolve, 0))
}

let tabs

/**
 * Load the entry afresh and fire DOMContentLoaded.
 *
 * @return {void}
 */
function load() {
	jest.isolateModules(() => {
		require('./files-sidebar.js')
	})
	document.dispatchEvent(new Event('DOMContentLoaded'))
}

beforeEach(() => {
	jest.clearAllMocks()
	tabs = []
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
})
afterEach(() => {
	delete global.OCA
})

describe('files-sidebar entry', () => {
	it('registers the three tabs and loads none of their code until one renders', async () => {
		load()
		await settle()
		expect(mockRegister).not.toHaveBeenCalled()
		expect(mockEnsure).not.toHaveBeenCalled()
		expect(mockRegisterAttach).toHaveBeenCalledTimes(1)
		expect(tabs.map((t) => [t.id, t.name])).toEqual([
			['openregister-objects', 'Register objects'],
			['openregister-extraction', 'Extraction'],
			['openregister-conversation', 'Conversation'],
		])
		for (const tab of tabs) {
			expect(tab.icon).toMatch(/^<svg /)
			expect(tab.enabled({ id: 1, name: 'reply.eml' })).toBe(true)
			expect(tab.enabled(null)).toBe(false)
		}
	})

	it('registers translations and the integration registry once, when the first tab renders', async () => {
		load()
		tabs[0].mount(document.createElement('div'), { id: 1 })
		tabs[1].mount(document.createElement('div'), { id: 1 })
		await settle()
		expect(mockRegister).toHaveBeenCalledTimes(1)
		expect(mockEnsure).toHaveBeenCalledTimes(1)
	})

	it('offers the conversation tab for mail files only', () => {
		load()
		const [objects, extraction, conversation] = tabs
		expect(objects.enabled({ id: 1, name: 'report.pdf' })).toBe(true)
		expect(extraction.enabled({ id: 1, name: 'report.pdf' })).toBe(true)
		expect(conversation.enabled({ id: 1, name: 'report.pdf' })).toBe(false)
		expect(conversation.enabled({ id: 1, name: 'Answer.EML' })).toBe(true)
		expect(conversation.enabled({ id: 1 })).toBe(false)
	})

	it('registers nothing where the Files sidebar is missing', () => {
		delete global.OCA.Files
		load()
		expect(tabs).toEqual([])
	})

	it.each([
		[0, 'objects', '_registerObjectsApp', '_registerObjectsProps'],
		[1, 'extraction', '_extractionApp', '_extractionProps'],
		[2, 'thread', '_mailThreadApp', '_mailThreadProps'],
	])('tab %i mounts, follows the file and unmounts', async (index, cls, appKey, propsKey) => {
		load()
		const tab = tabs[index]
		const el = document.createElement('div')

		tab.mount(el, { id: 5 })
		await settle()
		expect(el.querySelector(`.${cls}`).textContent).toBe('5')
		expect(isReactive(el[propsKey])).toBe(true)
		const first = el[appKey]
		expect(first.config.globalProperties.t).toBeUndefined()
		expect(first._instance.proxy.t('openregister', 'x')).toBe('x')

		await tab.update(el, { id: 6 })
		expect(el[propsKey].fileId).toBe(6)

		const unmount = jest.spyOn(first, 'unmount')
		tab.mount(el, { id: 7 })
		await settle()
		expect(unmount).toHaveBeenCalledTimes(1)
		expect(el.querySelectorAll(`.${cls}`)).toHaveLength(1)

		tab.destroy(el)
		expect(el[appKey]).toBeNull()
		expect(el[propsKey]).toBeNull()
		expect(el.innerHTML).toBe('')

		// Updating or destroying a tab that is not mounted does nothing.
		await tab.update(el, { id: 8 })
		tab.destroy(el)
		expect(el[propsKey]).toBeNull()
	})
})
