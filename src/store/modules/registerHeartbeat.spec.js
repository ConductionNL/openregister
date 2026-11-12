/**
 * The register store's import heartbeat, getters, setters and refusals.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { Register } from '../../entities/index.js'
import { useRegisterStore } from './register.js'

const HEARTBEAT = '/index.php/apps/openregister/api/heartbeat'

/**
 * Build a fetch Response stand-in.
 *
 * @param {number} status HTTP status.
 * @return {object} Response-like object.
 */
function reply(status) {
	return { ok: status < 300, status, statusText: `S${status}`, json: async () => ({}) }
}

describe('register store heartbeat', () => {
	let errSpy
	let originalTimeout
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.useFakeTimers()
		errSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
		originalTimeout = AbortSignal.timeout
		AbortSignal.timeout = jest.fn(() => 'signal')
	})
	afterEach(() => {
		jest.useRealTimers()
		errSpy.mockRestore()
		AbortSignal.timeout = originalTimeout
		delete global.fetch
	})

	it('pings the heartbeat endpoint on every interval and stops on stop()', async () => {
		global.fetch = jest.fn(async () => reply(200))
		const hb = useRegisterStore().startImportHeartbeat(1000)
		await jest.advanceTimersByTimeAsync(3000)
		expect(global.fetch).toHaveBeenCalledTimes(3)
		expect(global.fetch).toHaveBeenCalledWith(HEARTBEAT, {
			method: 'GET',
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-cache' },
			signal: 'signal',
		})
		expect(AbortSignal.timeout).toHaveBeenCalledWith(10000)
		expect(hb.getStatus()).toEqual({ healthy: true, failures: 0, count: 3 })
		hb.stop()
		await jest.advanceTimersByTimeAsync(5000)
		expect(global.fetch).toHaveBeenCalledTimes(3)
	})

	it('turns unhealthy on the third failure in a row, notifies once, then recovers', async () => {
		const answers = [500, 500, 500, 500, 200, 200]
		global.fetch = jest.fn(async () => reply(answers.shift()))
		const notify = jest.fn()
		const hb = useRegisterStore().startImportHeartbeat(100, notify)

		await jest.advanceTimersByTimeAsync(200)
		expect(hb.getStatus()).toEqual({ healthy: true, failures: 2, count: 2 })
		expect(notify).not.toHaveBeenCalled()

		await jest.advanceTimersByTimeAsync(100)
		expect(hb.getStatus()).toEqual({ healthy: false, failures: 3, count: 3 })
		expect(notify).toHaveBeenCalledTimes(1)
		expect(notify).toHaveBeenLastCalledWith({ healthy: false, failures: 3, count: 3, error: 'HTTP 500: S500' })

		await jest.advanceTimersByTimeAsync(100)
		expect(notify).toHaveBeenCalledTimes(1)

		await jest.advanceTimersByTimeAsync(100)
		expect(hb.getStatus()).toEqual({ healthy: true, failures: 0, count: 5 })
		expect(notify).toHaveBeenLastCalledWith({ healthy: true, failures: 0, count: 5 })

		await jest.advanceTimersByTimeAsync(100)
		expect(notify).toHaveBeenCalledTimes(2)
		hb.stop()
	})

	it('counts failures and recovers without a listener', async () => {
		const answers = [500, 200]
		global.fetch = jest.fn(async () => {
			const s = answers.shift()
			if (s === 500) throw new Error('network')
			return reply(s)
		})
		const hb = useRegisterStore().startImportHeartbeat(100)
		await jest.advanceTimersByTimeAsync(100)
		expect(hb.getStatus()).toEqual({ healthy: true, failures: 1, count: 1 })
		expect(errSpy).toHaveBeenCalledWith('RegisterStore: Heartbeat #1 failed (failure 1):', 'network')
		await jest.advanceTimersByTimeAsync(100)
		expect(hb.getStatus()).toEqual({ healthy: true, failures: 0, count: 2 })
		hb.stop()
	})
})

describe('register store state', () => {
	let errSpy
	beforeEach(() => {
		setActivePinia(createPinia())
		errSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => errSpy.mockRestore())

	it('getters read the state the setters write', () => {
		const store = useRegisterStore()
		store.setActiveTab('objects-tab')
		store.setViewMode('table')
		store.setRegisterItem({ id: 3, title: 'Three' })
		expect(store.getActiveTab).toBe('objects-tab')
		expect(store.getViewMode).toBe('table')
		expect(store.getRegisterItem).toBeInstanceOf(Register)
		expect(store.getRegisterItem.id).toBe(3)
		expect(store.isLoading).toBe(false)
		expect(store.getError).toBeNull()
	})

	it('setRegisterItem records the error when the entity cannot be built', () => {
		const store = useRegisterStore()
		const bad = {}
		Object.defineProperty(bad, 'id', { get() { throw new Error('unreadable') } })
		store.setRegisterItem(bad)
		expect(store.getError).toBe('unreadable')
		expect(store.isLoading).toBe(false)
	})

	it('clearRegisterItem resets the item and the error', () => {
		const store = useRegisterStore()
		store.setRegisterItem({ id: 1 })
		store.error = 'old'
		store.clearRegisterItem()
		expect(store.registerItem).toBeNull()
		expect(store.error).toBeNull()
	})

	it('refuses to delete, save or upload without a register', async () => {
		global.fetch = jest.fn()
		const store = useRegisterStore()
		await expect(store.deleteRegister({})).rejects.toThrow('No register item to delete')
		await expect(store.saveRegister(null)).rejects.toThrow('No register item to save')
		await expect(store.uploadRegister(null)).rejects.toThrow('No register item to upload')
		expect(global.fetch).not.toHaveBeenCalled()
		delete global.fetch
	})
})
