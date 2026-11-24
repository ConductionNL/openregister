/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The external register integration: what it registers and when it does not.
 *
 * @spec exclude Unit test of the client-side external register integration.
 */
const mockLibrary = {}
jest.mock('@conduction/nextcloud-vue', () => mockLibrary)
jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => `${app}:${text}` }))
jest.mock('./ExternalRegisterCard.vue', () => ({ name: 'ExternalRegisterCard' }))

const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

let mod

beforeEach(() => {
	for (const key of Object.keys(mockLibrary)) delete mockLibrary[key]
	jest.resetModules()
	mod = require('./externalRegister.js')
})

describe('registerExternalRegisterIntegration', () => {
	it('registers its own card on both surfaces while the library has none', async () => {
		const registry = { register: jest.fn(), has: jest.fn(() => false) }
		mod.registerExternalRegisterIntegration(registry)
		await settle()
		expect(registry.has).toHaveBeenCalledWith('external-register')
		expect(registry.register).toHaveBeenCalledWith({
			id: 'external-register',
			label: 'openregister:External register',
			icon: 'DatabaseOutline',
			group: 'data',
			tab: { name: 'ExternalRegisterCard' },
			widget: { name: 'ExternalRegisterCard' },
			defaultSize: { w: 4, h: 3 },
		})
	})

	it('prefers the library card when it ships one', () => {
		const card = { name: 'CnExternalRegisterCard' }
		const descriptor = mod.externalRegisterDescriptor({ CnExternalRegisterCard: card })
		expect(descriptor.widget).toBe(card)
		expect(descriptor.tab).toBe(card)
	})

	it('does nothing when already registered or without a registry', async () => {
		const registry = { register: jest.fn(), has: jest.fn(() => true) }
		mod.registerExternalRegisterIntegration(registry)
		mod.registerExternalRegisterIntegration(null)
		mod.registerExternalRegisterIntegration({})
		await settle()
		expect(registry.register).not.toHaveBeenCalled()
	})
})
