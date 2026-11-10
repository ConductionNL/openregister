import { fileAsBase64, mintPayload, publicLinkUrl } from './accessLinks.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

describe('access links helpers', () => {
	it('mintPayload carries the label only when one is given', () => {
		const base = { subjectType: 'object', subjectId: 7, capabilities: ['read'], expiresOn: '2026-12-31' }
		expect(mintPayload({ ...base, label: 'Voor de notaris' }).label).toBe('Voor de notaris')
		expect(mintPayload(base)).not.toHaveProperty('label')
	})

	it('publicLinkUrl builds the holder URL for the anchor with an optional suffix', () => {
		expect(publicLinkUrl('abc')).toBe('/index.php/apps/openregister/api/public/links/abc')
		expect(publicLinkUrl('abc', '/files')).toBe('/index.php/apps/openregister/api/public/links/abc/files')
	})

	describe('fileAsBase64', () => {
		it('resolves with the content without the data URL prefix', async () => {
			const file = new File(['hello'], 'h.txt', { type: 'text/plain' })
			await expect(fileAsBase64(file)).resolves.toBe(btoa('hello'))
		})

		it('rejects with the reader error when reading fails', async () => {
			const original = global.FileReader
			const failure = new Error('unreadable')
			global.FileReader = class {
				readAsDataURL() {
					this.error = failure
					this.onerror()
				}
			}
			try {
				await expect(fileAsBase64(new File(['x'], 'x'))).rejects.toBe(failure)
			} finally {
				global.FileReader = original
			}
		})
	})
})
