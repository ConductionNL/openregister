/**
 * Unit tests for fileMetadata API client helpers.
 *
 * Covers the wire-shape contract for label updates, description/category
 * updates, and label clearing. Exercises the request URL, method, and body
 * for each call — no integration with backend, no Vue mounting needed.
 *
 * Closes file-actions tasks 163, 164, 165.
 */

import { updateFileLabels, updateFileMetadata } from './fileMetadata.js'

describe('fileMetadata API helpers', () => {
	let fetchMock

	beforeEach(() => {
		fetchMock = jest.fn()
		global.fetch = fetchMock
	})

	afterEach(() => {
		jest.clearAllMocks()
	})

	describe('updateFileLabels (item 163)', () => {
		it('PUTs new labels to /files/{fileId}/labels with `{ labels }` body', async () => {
			fetchMock.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ id: 42, labels: ['confidential', 'q3-2025'] }),
			})

			const result = await updateFileLabels({
				registerId: 5,
				schemaId: 28,
				objectId: 'obj-uuid',
				fileId: 42,
				labels: ['confidential', 'q3-2025'],
			})

			expect(fetchMock).toHaveBeenCalledTimes(1)
			const [url, opts] = fetchMock.mock.calls[0]
			expect(url).toBe('/index.php/apps/openregister/api/objects/5/28/obj-uuid/files/42/labels')
			expect(opts.method).toBe('PUT')
			expect(opts.headers['Content-Type']).toBe('application/json')
			expect(JSON.parse(opts.body)).toEqual({ labels: ['confidential', 'q3-2025'] })
			expect(result.labels).toEqual(['confidential', 'q3-2025'])
		})

		it('throws on non-OK response so the caller can revert optimistic UI', async () => {
			fetchMock.mockResolvedValueOnce({ ok: false, status: 423 })

			await expect(
				updateFileLabels({
					registerId: 5,
					schemaId: 28,
					objectId: 'obj-uuid',
					fileId: 42,
					labels: ['locked-while-editing'],
				}),
			).rejects.toThrow('HTTP 423')
		})
	})

	describe('updateFileMetadata (item 164)', () => {
		it('PUTs description + category in one round-trip; null fields are NOT sent', async () => {
			fetchMock.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ id: 42, description: 'Q3 minutes', category: 'governance' }),
			})

			const result = await updateFileMetadata({
				registerId: 5,
				schemaId: 28,
				objectId: 'obj-uuid',
				fileId: 42,
				description: 'Q3 minutes',
				category: 'governance',
				// labels omitted -> null -> NOT in payload
			})

			expect(fetchMock).toHaveBeenCalledTimes(1)
			const [url, opts] = fetchMock.mock.calls[0]
			expect(url).toBe('/index.php/apps/openregister/api/objects/5/28/obj-uuid/files/42')
			expect(opts.method).toBe('PUT')
			const body = JSON.parse(opts.body)
			expect(body).toEqual({
				description: 'Q3 minutes',
				category: 'governance',
			})
			// Crucial: labels MUST NOT be present (null = leave untouched).
			expect(body).not.toHaveProperty('labels')
			expect(result.description).toBe('Q3 minutes')
		})

		it('explicit empty values are sent — distinct from null/skip', async () => {
			fetchMock.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ id: 42, description: '', category: '' }),
			})

			await updateFileMetadata({
				registerId: 5,
				schemaId: 28,
				objectId: 'obj-uuid',
				fileId: 42,
				description: '', // explicit clear
				category: '', // explicit clear
				labels: null, // skip
			})

			const body = JSON.parse(fetchMock.mock.calls[0][1].body)
			expect(body).toEqual({ description: '', category: '' })
			expect(body).not.toHaveProperty('labels')
		})
	})

	describe('label clearing (item 165)', () => {
		it('PUTs an empty array to /labels to remove every label', async () => {
			fetchMock.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ id: 42, labels: [] }),
			})

			const result = await updateFileLabels({
				registerId: 5,
				schemaId: 28,
				objectId: 'obj-uuid',
				fileId: 42,
				labels: [],
			})

			const body = JSON.parse(fetchMock.mock.calls[0][1].body)
			expect(body).toEqual({ labels: [] })
			// Crucial: must NOT be `{}` or `{labels: null}` — an explicit
			// empty array is the contract for "clear all labels".
			expect(Array.isArray(body.labels)).toBe(true)
			expect(result.labels).toEqual([])
		})

		it('clearing labels via updateFileMetadata also passes [] not null', async () => {
			fetchMock.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ id: 42, labels: [] }),
			})

			await updateFileMetadata({
				registerId: 5,
				schemaId: 28,
				objectId: 'obj-uuid',
				fileId: 42,
				labels: [], // explicit clear
			})

			const body = JSON.parse(fetchMock.mock.calls[0][1].body)
			expect(body).toEqual({ labels: [] })
		})
	})
})
