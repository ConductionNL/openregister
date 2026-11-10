/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The global integration entry: library translations first, then the registry.
 *
 * @spec exclude Unit test of the client-side global integration entry.
 */

const mockOrder = []
jest.mock('./integrations/bootstrap.js', () => ({
	ensureIntegrationRegistry: () => mockOrder.push('registry'),
}))
jest.mock('./services/libraryTranslations.js', () => ({
	registerLibraryTranslations: () => mockOrder.push('translations'),
}))

describe('integration-global entry', () => {
	it('registers the library translations, then the integration registry', () => {
		require('./integration-global.js')
		expect(mockOrder).toEqual(['translations', 'registry'])
	})
})
