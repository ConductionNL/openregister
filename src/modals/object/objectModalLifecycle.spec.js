/**
 * How the copy, lock and download modals open and close: the state each
 * resets, the store call that closes it, a pending close that is cancelled,
 * the copy name proposed when the copy dialog opens, and the download that
 * starts on mount.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import CopyObject from './CopyObject.vue'
import DownloadObject from './DownloadObject.vue'
import LockObject from './LockObject.vue'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn(), dialog: false },
	objectStore: {},
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one) => one }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-codemirror6', () => ({}))
jest.mock('@codemirror/lang-json', () => ({ json: () => null, jsonParseLinter: () => null }))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOutline.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../../services/getTheme.js', () => ({ getTheme: () => 'light' }))

/**
 * A bare component context: its data, its computed values and its methods bound to it.
 *
 * @param {object} component The component options
 * @param {object} state State to start from
 * @return {object} The context
 */
function context(component, state = {}) {
	const ctx = { ...component.data.call({}), ...state }
	for (const [name, getter] of Object.entries(component.computed || {})) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx), configurable: true })
	}
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.useFakeTimers()
	jest.clearAllMocks()
	for (const key of Object.keys(objectStore)) delete objectStore[key]
})
afterEach(() => jest.useRealTimers())

describe('CopyObject opening and closing', () => {
	const opened = CopyObject.watch['navigationStore.dialog']

	it('exposes the navigation store for its watcher', () => {
		expect(context(CopyObject).navigationStore).toBe(navigationStore)
	})

	it('proposes a copy name from the original and resets a previous attempt when the copy dialog opens', () => {
		objectStore.objectItem = { '@self': { name: 'Parkeervergunning' } }
		const ctx = context(CopyObject, { copyName: 'old', success: false, loading: true, error: 'Earlier failure' })

		opened.call(ctx, 'copyObject')

		expect([ctx.copyName, ctx.success, ctx.loading, ctx.error]).toEqual(['Copy of Parkeervergunning', null, false, false])
	})

	it('leaves its state alone when another dialog opens', () => {
		objectStore.objectItem = { name: 'x' }
		const ctx = context(CopyObject, { copyName: 'kept', error: 'kept' })

		opened.call(ctx, 'deleteObject')

		expect([ctx.copyName, ctx.error]).toEqual(['kept', 'kept'])
	})

	it('falls back to the object id when the original has no name', () => {
		objectStore.objectItem = { '@self': { id: 'o-7' } }

		expect(context(CopyObject).defaultCopyName).toBe('Copy of Object o-7')
	})

	it('closes the dialog, resets every field and cancels a pending open', () => {
		const openCopy = jest.fn()
		const ctx = context(CopyObject, { copyName: 'Copy', success: true, loading: true, error: 'x' })
		ctx.closeModalTimeout = setTimeout(openCopy, 2000)

		ctx.closeDialog()
		jest.advanceTimersByTime(2000)

		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect([ctx.success, ctx.loading, ctx.error, ctx.copyName]).toEqual([null, false, false, ''])
		expect(openCopy).not.toHaveBeenCalled()
	})
})

describe('LockObject.closeModal', () => {
	it('closes the modal, restores the defaults and cancels a pending close', () => {
		const pending = jest.fn()
		const ctx = context(LockObject, { process: 'review', duration: 60, success: true, loading: true, error: 'x' })
		ctx.closeModalTimeout = setTimeout(pending, 2000)

		ctx.closeModal()
		jest.advanceTimersByTime(2000)

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect([ctx.success, ctx.loading, ctx.error, ctx.process, ctx.duration]).toEqual([null, false, null, '', 3600])
		expect(pending).not.toHaveBeenCalled()
	})

	it('starts with an hour-long lock and no process', () => {
		expect(LockObject.data()).toEqual({ process: '', duration: 3600, success: null, loading: false, error: null, closeModalTimeout: null })
	})
})

describe('DownloadObject lifecycle', () => {
	it('starts the download on mount when an object is selected', () => {
		objectStore.objectItem = { id: 'o1' }
		const ctx = { downloadObject: jest.fn() }

		DownloadObject.mounted.call(ctx)

		expect(ctx.downloadObject).toHaveBeenCalledTimes(1)
	})

	it.each([
		['no object', undefined],
		['an object without an id', { name: 'x' }],
	])('does not download on mount with %s', (label, item) => {
		objectStore.objectItem = item
		const ctx = { downloadObject: jest.fn() }

		DownloadObject.mounted.call(ctx)

		expect(ctx.downloadObject).not.toHaveBeenCalled()
	})

	it('closes the modal, resets its outcome and cancels a pending close', () => {
		const pending = jest.fn()
		const ctx = context(DownloadObject, { success: true, loading: true, error: 'x' })
		ctx.closeModalTimeout = setTimeout(pending, 2000)

		ctx.closeDialog()
		jest.advanceTimersByTime(2000)

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect([ctx.success, ctx.loading, ctx.error]).toEqual([null, false, false])
		expect(pending).not.toHaveBeenCalled()
	})
})
