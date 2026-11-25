/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Bookmarks integration: what it registers and when it does not.
 *
 * @spec exclude Unit test of the client-side Bookmarks integration.
 */
const mockTab = { name: 'CnBookmarksTab' }
const mockCard = { name: 'CnBookmarksCard' }
jest.mock('@conduction/nextcloud-vue/dist/esm/integrations/builtin/bookmarks.js', () => ({
	bookmarksIntegration: { tab: mockTab, widget: mockCard },
}))
jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => `${app}:${text}` }))

/**
 * A registry stub.
 *
 * @param {boolean} hasBookmarks Whether bookmarks is already registered.
 * @return {object} The stub.
 */
function registry(hasBookmarks = false) {
	return { register: jest.fn(), has: jest.fn(() => hasBookmarks) }
}

const { bookmarksDescriptor, registerBookmarksIntegration } = require('./bookmarks.js')

describe('registerBookmarksIntegration', () => {
	it('registers the dedicated bookmarks tab and card at once', () => {
		const r = registry()
		registerBookmarksIntegration(r)
		expect(r.has).toHaveBeenCalledWith('bookmarks')
		expect(r.register).toHaveBeenCalledWith({
			id: 'bookmarks',
			label: 'openregister:Bookmarks',
			icon: 'Bookmark',
			group: 'docs',
			requiredApp: 'bookmarks',
			storage: 'link-table',
			referenceType: 'bookmarks',
			tab: mockTab,
			widget: mockCard,
			defaultSize: { w: 4, h: 3 },
		})
	})

	it('takes other components when given', () => {
		const tab = { name: 'Tab' }
		const widget = { name: 'Card' }
		expect(bookmarksDescriptor({ tab, widget })).toEqual(expect.objectContaining({ tab, widget }))
	})

	it('registers nothing twice, or into something that is not a registry', () => {
		const r = registry(true)
		registerBookmarksIntegration(r)
		registerBookmarksIntegration(null)
		registerBookmarksIntegration({ register: 'no' })
		expect(r.register).not.toHaveBeenCalled()
	})

	it('logs a registration that fails', () => {
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		const r = { register: jest.fn(() => { throw new Error('full') }) }
		registerBookmarksIntegration(r)
		expect(error).toHaveBeenCalledWith(
			'[bookmarks] failed to register bookmarks integration',
			expect.any(Error),
		)
		error.mockRestore()
	})
})
