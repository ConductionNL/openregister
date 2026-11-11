import DownloadObject from './DownloadObject.vue'
import { navigationStore, objectStore } from '../../store/store.js'

// The download action: the outcome it shows and when it closes.

jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: { objectItem: { id: 'o1' } },
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-codemirror6', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('@codemirror/lang-json', () => ({ json: () => null, jsonParseLinter: () => null }))

/**
 * A bare modal context with the modal's methods.
 *
 * @return {object} The context
 */
function context() {
	const ctx = { ...DownloadObject.data(), closeModalTimeout: null }
	for (const [name, method] of Object.entries(DownloadObject.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

describe('DownloadObject.downloadObject', () => {
	beforeEach(() => jest.useFakeTimers())
	afterEach(() => {
		jest.useRealTimers()
		delete objectStore.downloadObject
	})

	it('shows success and closes two seconds later', async () => {
		objectStore.downloadObject = jest.fn(() => Promise.resolve({ ok: true }))
		const ctx = context()
		await ctx.downloadObject()
		expect([ctx.success, ctx.error, ctx.loading]).toEqual([true, false, false])
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('shows a refused download without closing', async () => {
		objectStore.downloadObject = jest.fn(() => Promise.resolve({ ok: false }))
		const ctx = context()
		await ctx.downloadObject()
		expect([ctx.success, ctx.error, ctx.loading]).toEqual([false, false, false])
		expect(ctx.closeModalTimeout).toBeNull()
	})

	it.each([
		['a rejection with a message', () => Promise.reject(new Error('Gone')), 'Gone'],
		['a rejection without one', () => Promise.reject({}), 'An error occurred while downloading the object'],
		['a store call that throws', () => { throw new Error('Broken') }, 'Broken'],
	])('shows %s', async (label, download, message) => {
		objectStore.downloadObject = jest.fn(download)
		const ctx = context()
		await ctx.downloadObject()
		expect([ctx.success, ctx.error, ctx.loading]).toEqual([false, message, false])
	})
})
