/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The flow integration is picked out of the library's built-ins.
 *
 * @spec exclude Unit test of the client-side flow integration lookup.
 */

const mockLibrary = {}
jest.mock('@conduction/nextcloud-vue', () => mockLibrary)

/**
 * Load the module afresh.
 *
 * @return {object} The module
 */
function load() {
	let mod
	jest.isolateModules(() => {
		mod = require('./flow.js')
	})
	return mod
}

describe('flowIntegration', () => {
	it('is the built-in with id flow', () => {
		const flow = { id: 'flow' }
		mockLibrary.builtinIntegrations = [null, { id: 'files' }, flow]
		const mod = load()
		expect(mod.flowIntegration).toBe(flow)
		expect(mod.default).toBe(flow)
	})

	it('is undefined when the library has no list of built-ins', () => {
		mockLibrary.builtinIntegrations = undefined
		expect(load().flowIntegration).toBeUndefined()
	})
})
