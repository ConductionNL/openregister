/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The external register integration: what it registers and when it does not.
 *
 * @spec exclude Unit test of the client-side external register integration.
 */
jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => `${app}:${text}` }))
jest.mock('./ExternalRegisterCard.vue', () => ({ name: 'ExternalRegisterCard' }))
jest.mock('@conduction/nextcloud-vue/dist/esm/utils/lazyComponent.js', () => ({
	lazyComponent: (loader, name) => ({ name: `Lazy${name}`, loader }),
}))

let mod

beforeEach(() => {
	jest.resetModules()
	mod = require('./externalRegister.js')
})

describe('registerExternalRegisterIntegration', () => {
	it('registers its own card on both surfaces at once, without loading the library', () => {
		const registry = { register: jest.fn(), has: jest.fn(() => false) }
		mod.registerExternalRegisterIntegration(registry)
		expect(registry.has).toHaveBeenCalledWith('external-register')
		const descriptor = registry.register.mock.calls[0][0]
		expect(descriptor).toEqual({
			id: 'external-register',
			label: 'openregister:External register',
			icon: 'DatabaseOutline',
			group: 'data',
			tab: expect.objectContaining({ name: 'LazyExternalRegisterCard' }),
			widget: expect.objectContaining({ name: 'LazyExternalRegisterCard' }),
			defaultSize: { w: 4, h: 3 },
		})
		expect(descriptor.widget).toBe(descriptor.tab)
	})

	it('loads the card on its first render, not with the every-page script', async () => {
		const descriptor = mod.externalRegisterDescriptor()
		const loaded = await descriptor.tab.loader()
		expect(loaded.default ?? loaded).toEqual(expect.objectContaining({ name: 'ExternalRegisterCard' }))
	})

	it('prefers the library card when it ships one', () => {
		const card = { name: 'CnExternalRegisterCard' }
		const descriptor = mod.externalRegisterDescriptor({ CnExternalRegisterCard: card })
		expect(descriptor.widget).toBe(card)
		expect(descriptor.tab).toBe(card)
	})

	it('does nothing when already registered or without a registry', () => {
		const registry = { register: jest.fn(), has: jest.fn(() => true) }
		mod.registerExternalRegisterIntegration(registry)
		mod.registerExternalRegisterIntegration(null)
		mod.registerExternalRegisterIntegration({})
		expect(registry.register).not.toHaveBeenCalled()
	})
})
