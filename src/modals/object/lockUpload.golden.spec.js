/**
 * Golden record of locking an object and uploading one: what each sends to
 * the store, the state it ends in, and when it closes.
 *
 * @spec exclude Golden record of client-side lock and upload modal actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import LockObject from './LockObject.vue'
import UploadObject from './UploadObject.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@codemirror/lang-json', () => ({ json: () => ({}), jsonParseLinter: () => () => [] }))
jest.mock('vue-codemirror6', () => ({}))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({}))
jest.mock('vue-material-design-icons/AutoFix.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOutline.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../../services/getTheme.js', () => ({ getTheme: () => 'light' }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))

/**
 * A bare component context with the component's methods bound to it.
 *
 * @param {object} component The component options
 * @param {object} state The modal state
 * @return {object} The context
 */
function context(component, state) {
	const ctx = { ...component.data.call({}), ...state }
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.closeModal = jest.fn()
	return ctx
}

/**
 * The state fields a modal action writes.
 *
 * @param {object} ctx The context
 * @return {object} The fields
 */
function outcome(ctx) {
	return {
		loading: ctx.loading,
		success: ctx.success,
		error: ctx.error,
		closes: ctx.closeModal.mock.calls.length,
	}
}

beforeEach(() => {
	jest.useFakeTimers()
	for (const key of Object.keys(objectStore)) delete objectStore[key]
})
afterEach(() => jest.useRealTimers())

describe('LockObject.lockObject (golden)', () => {
	it.each([
		['locks with a process and duration', { process: 'review', duration: 60 }, () => Promise.resolve({})],
		['locks without a process or duration', { process: '', duration: 0 }, () => Promise.resolve({})],
		['reports a refusal', { process: 'p', duration: 5 }, () => Promise.reject(new Error('Already locked'))],
		['reports a failure without a message', { process: 'p', duration: 5 }, () => Promise.reject({})],
	])('%s', async (name, state, answer) => {
		const calls = []
		objectStore.objectItem = { id: 'obj-1' }
		objectStore.lockObject = (...args) => {
			calls.push(args)
			return answer()
		}
		const ctx = context(LockObject, state)
		const pending = ctx.lockObject()
		const during = ctx.loading
		await pending
		const before = outcome(ctx)
		jest.advanceTimersByTime(1999)
		const early = ctx.closeModal.mock.calls.length
		jest.advanceTimersByTime(1)
		expect({ calls, during, before, early, after: outcome(ctx) }).toMatchSnapshot()
	})
})

describe('UploadObject.uploadObject (golden)', () => {
	it.each([
		[
			'saves with a mapping',
			{ object: '{"a":1}', registers: { value: { id: 3 } }, schemas: { value: { id: 7 } }, mappings: { value: { id: 9 } } },
			() => Promise.resolve({ id: 'new' }),
		],
		[
			'saves without a mapping',
			{ object: 'null', registers: { value: {} }, schemas: { value: {} }, mappings: {} },
			() => Promise.resolve({ id: 'new' }),
		],
		[
			'reports nothing saved',
			{ object: '{}', registers: { value: { id: 1 } }, schemas: { value: { id: 2 } } },
			() => Promise.resolve(null),
		],
		[
			'reports a failure',
			{ object: '{}', registers: { value: { id: 1 } }, schemas: { value: { id: 2 } } },
			() => Promise.reject(new Error('Invalid')),
		],
		[
			'reports a failure without a message',
			{ object: '{}', registers: { value: { id: 1 } }, schemas: { value: { id: 2 } } },
			() => Promise.reject({}),
		],
	])('%s', async (name, state, answer) => {
		const log = []
		objectStore.registerObjectType = (...args) => log.push(['register', ...args])
		objectStore.saveObject = (...args) => {
			log.push(['save', ...args])
			return answer()
		}
		const ctx = context(UploadObject, state)
		const returned = ctx.uploadObject()
		const during = ctx.loading
		await Promise.resolve()
		await Promise.resolve()
		await Promise.resolve()
		const before = outcome(ctx)
		jest.advanceTimersByTime(2000)
		expect({
			returned,
			log,
			during,
			before,
			after: outcome(ctx),
		}).toMatchSnapshot()
	})

	it('rejects an object that is not JSON, still loading', async () => {
		objectStore.registerObjectType = jest.fn()
		objectStore.saveObject = jest.fn()
		const ctx = context(UploadObject, { object: '{nope', registers: { value: {} }, schemas: { value: {} } })
		await expect(ctx.uploadObject()).rejects.toThrow(SyntaxError)
		expect(ctx.loading).toBe(true)
		expect(objectStore.saveObject).not.toHaveBeenCalled()
	})
})
