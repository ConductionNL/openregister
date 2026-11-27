/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A consuming app's `fileActions.attachTargets`, read from its manifest.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-files-action-attaches-a-file-to-a-register-object
 */

const mockGet = jest.fn()
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: (...args) => mockGet(...args) },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace(/{(\w+)}/g, (m, k) => params[k]),
}))

const { declaredAttachTargets, loadDeclaredAttachTargets } = require('./attachTargets.js')

beforeEach(() => {
	jest.clearAllMocks()
})

describe('declaredAttachTargets', () => {
	it('reads schema slugs and register/schema pairs, once each', () => {
		const manifest = { fileActions: { attachTargets: ['case', { register: 'cases', schema: 'permit' }, ' case '] } }

		expect(declaredAttachTargets(manifest)).toEqual(['case', 'permit'])
	})

	it('drops entries that name no schema', () => {
		const manifest = { fileActions: { attachTargets: ['', 7, null, { register: 'cases' }, 'case'] } }

		expect(declaredAttachTargets(manifest)).toEqual(['case'])
	})

	it('pins nothing without a declaration, or with one that names nothing', () => {
		expect(declaredAttachTargets(null)).toBeNull()
		expect(declaredAttachTargets({})).toBeNull()
		expect(declaredAttachTargets({ fileActions: { attachTargets: 'case' } })).toBeNull()
		expect(declaredAttachTargets({ fileActions: { attachTargets: [] } })).toBeNull()
	})
})

describe('loadDeclaredAttachTargets', () => {
	it('reads the declaration from the app\'s manifest', async () => {
		mockGet.mockResolvedValue({ data: { fileActions: { attachTargets: ['case'] } } })

		await expect(loadDeclaredAttachTargets('dossiq')).resolves.toEqual(['case'])

		expect(mockGet).toHaveBeenCalledWith('/apps/openregister/api/manifest/dossiq')
	})

	it('pins nothing when the manifest cannot be read', async () => {
		mockGet.mockRejectedValue(new Error('404'))

		await expect(loadDeclaredAttachTargets('dossiq')).resolves.toBeNull()
	})

	it('does not ask for an app id that is not one', async () => {
		await expect(loadDeclaredAttachTargets('../x')).resolves.toBeNull()
		await expect(loadDeclaredAttachTargets(null)).resolves.toBeNull()

		expect(mockGet).not.toHaveBeenCalled()
	})
})
