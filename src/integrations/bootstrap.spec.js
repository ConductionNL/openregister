/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The integration registry bootstrap: populated once per page.
 *
 * @spec exclude Unit test of the client-side integration bootstrap.
 */

const mockRegistry = { name: 'shared' }
const mockCalls = []
jest.mock('@conduction/nextcloud-vue', () => ({
	getSharedRegistry: (win) => {
		mockCalls.push(['shared', win === globalThis.window])
		return mockRegistry
	},
	registerBuiltinIntegrations: (r) => mockCalls.push(['builtin', r]),
	registerLeafIntegrations: (r) => mockCalls.push(['leaf', r]),
}))
jest.mock('./builtin/bookmarks.js', () => ({
	registerBookmarksIntegration: (r) => mockCalls.push(['bookmarks', r]),
}))

describe('ensureIntegrationRegistry', () => {
	it('fills the window registry in order, and only the first time', () => {
		jest.isolateModules(() => {
			const { ensureIntegrationRegistry } = require('./bootstrap.js')
			ensureIntegrationRegistry()
			ensureIntegrationRegistry()
		})
		expect(mockCalls).toEqual([
			['shared', true],
			['builtin', mockRegistry],
			['leaf', mockRegistry],
			['bookmarks', mockRegistry],
		])
	})
})
