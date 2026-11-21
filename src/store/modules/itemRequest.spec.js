/**
 * itemRequest: the loading flag, the stored error and the error clearing the
 * application and webhook stores rely on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of client-side request state plumbing.
 */
import { itemRequest } from './itemRequest.js'

describe('itemRequest', () => {
	it('raises the loading flag while the request runs and returns its result', async () => {
		const store = { loading: false, error: 'old' }
		let seenLoading = null
		const result = await itemRequest(store, {
			log: jest.fn(),
			run: async () => {
				seenLoading = store.loading
				return { data: 1 }
			},
		})
		expect(seenLoading).toBe(true)
		expect(store.loading).toBe(false)
		expect(store.error).toBe('old')
		expect(result).toEqual({ data: 1 })
	})

	it('leaves the loading flag alone for a soft request', async () => {
		const store = { loading: false, error: null }
		let seenLoading = null
		await itemRequest(store, {
			soft: true,
			log: jest.fn(),
			run: async () => {
				seenLoading = store.loading
			},
		})
		expect(seenLoading).toBe(false)
	})

	it('clears the error once the request has started when asked to', async () => {
		const store = { loading: false, error: 'old' }
		let errorAtStart = null
		const pending = itemRequest(store, {
			clearsError: true,
			log: jest.fn(),
			run: async () => {
				errorAtStart = store.error
				await Promise.resolve()
			},
		})
		expect(errorAtStart).toBe('old')
		expect(store.error).toBeNull()
		await pending
		expect(store.error).toBeNull()
	})

	it('stores, logs and rethrows a failure, after clearing the error', async () => {
		const store = { loading: false, error: 'old' }
		const log = jest.fn()
		const failure = new Error('boom')
		await expect(itemRequest(store, {
			clearsError: true,
			log,
			run: async () => {
				throw failure
			},
		})).rejects.toBe(failure)
		expect(log).toHaveBeenCalledWith(failure)
		expect(store.error).toBe('boom')
		expect(store.loading).toBe(false)
	})

	it('wraps a failure in the failure text when one is given', async () => {
		const store = { loading: false, error: null }
		await expect(itemRequest(store, {
			failure: 'Could not save',
			log: jest.fn(),
			run: async () => {
				throw new Error('boom')
			},
		})).rejects.toThrow('Could not save: boom')
	})
})
