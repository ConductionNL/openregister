/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Bookmarks integration: what it registers and when it does not.
 *
 * @spec exclude Unit test of the client-side Bookmarks integration.
 */
const mockLibrary = {}
jest.mock('@conduction/nextcloud-vue', () => mockLibrary)
jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => `${app}:${text}` }))

/**
 * A registry that records registrations.
 *
 * @param {boolean} hasBookmarks Whether bookmarks is already registered
 * @return {object} The registry
 */
function registry(hasBookmarks = false) {
	return { register: jest.fn(), has: jest.fn(() => hasBookmarks) }
}

/**
 * Let the dynamic import settle.
 *
 * @return {Promise<void>}
 */
function settle() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

let registerBookmarksIntegration

beforeEach(() => {
	for (const key of Object.keys(mockLibrary)) delete mockLibrary[key]
	// Fresh per test: the import() interop caches the namespace it saw first.
	jest.resetModules()
	registerBookmarksIntegration = require('./bookmarks.js').registerBookmarksIntegration
})

describe('registerBookmarksIntegration', () => {
	it('registers the dedicated bookmarks tab and card', async () => {
		mockLibrary.CnBookmarksTab = { name: 'Tab' }
		mockLibrary.CnBookmarksCard = { name: 'Card' }
		mockLibrary.CnIntegrationTab = { name: 'GenericTab' }
		const r = registry()
		registerBookmarksIntegration(r)
		await settle()
		expect(r.has).toHaveBeenCalledWith('bookmarks')
		expect(r.register).toHaveBeenCalledWith({
			id: 'bookmarks',
			label: 'openregister:Bookmarks',
			icon: 'Bookmark',
			group: 'docs',
			requiredApp: 'bookmarks',
			storage: 'link-table',
			referenceType: 'bookmarks',
			tab: mockLibrary.CnBookmarksTab,
			widget: mockLibrary.CnBookmarksCard,
			defaultSize: { w: 4, h: 3 },
		})
	})

	it('falls back to the generic integration tab and card', async () => {
		mockLibrary.CnIntegrationTab = { name: 'GenericTab' }
		mockLibrary.CnIntegrationCard = { name: 'GenericCard' }
		const r = { register: jest.fn() }
		registerBookmarksIntegration(r)
		await settle()
		expect(r.register.mock.calls[0][0]).toEqual(
			expect.objectContaining({
				tab: mockLibrary.CnIntegrationTab,
				widget: mockLibrary.CnIntegrationCard,
			}),
		)
	})

	it('registers nothing twice, or into something that is not a registry', async () => {
		const r = registry(true)
		registerBookmarksIntegration(r)
		registerBookmarksIntegration(null)
		registerBookmarksIntegration({ register: 'no' })
		await settle()
		expect(r.register).not.toHaveBeenCalled()
	})

	it('logs a registration that fails', async () => {
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		const r = { register: jest.fn(() => { throw new Error('full') }) }
		registerBookmarksIntegration(r)
		await settle()
		expect(error).toHaveBeenCalledWith(
			'[bookmarks] failed to register bookmarks integration',
			expect.any(Error),
		)
		error.mockRestore()
	})
})
