import CopyObject from './CopyObject.vue'
import { copyPayload } from '../../services/objectCopy.js'
import { navigationStore, objectStore } from '../../store/store.js'

// The single-object copy action and the mass-copy payload, over the same objects.

jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: {
		objectItem: null,
		registerObjectType: jest.fn(),
		saveObject: jest.fn(),
		refreshObjectList: jest.fn(),
		setObjectItem: jest.fn(),
	},
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({}))

/**
 * A stored object with every metadata field set.
 *
 * @param {object} extra Root fields to add
 * @return {object} The object
 */
function stored(extra) {
	return {
		id: 'root-id',
		...extra,
		'@self': {
			id: 'self-id',
			uuid: 'u-1',
			uri: '/x',
			created: 'c',
			updated: 'u',
			version: '1',
			files: [],
			relations: {},
			folder: 'f',
			size: 3,
			deleted: null,
			name: 'Original',
			register: 4,
			schema: 9,
			owner: 'alice',
		},
	}
}

const OBJECTS = {
	plain: () => stored({ answer: 42 }),
	named: () => stored({ name: 'Root name' }),
	titled: () => stored({ title: 'Root title', name: undefined }),
	both: () => stored({ name: '', title: '' }),
	withoutSelf: () => ({ id: 'only-root', name: 'n' }),
}

/**
 * A bare component context with the component's methods.
 *
 * @param {object} state The modal state
 * @return {object} The context
 */
function context(state) {
	const ctx = { ...CopyObject.data.call({}), ...state, closeDialog: jest.fn() }
	for (const [name, method] of Object.entries(CopyObject.methods)) {
		if (name !== 'closeDialog') {
			ctx[name] = method.bind(ctx)
		}
	}
	return ctx
}

/**
 * The context's own state, without functions and timers.
 *
 * @param {object} ctx The context
 * @return {string} The state
 */
function state(ctx) {
	const own = {}
	for (const [key, value] of Object.entries(ctx)) {
		if (typeof value !== 'function' && key !== 'closeModalTimeout') {
			own[key] = value
		}
	}
	own.timer = ctx.closeModalTimeout === null ? 'none' : 'set'
	return JSON.stringify(own)
}

describe('CopyObject.copyObject', () => {
	let saved

	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
		saved = []
		objectStore.saveObject.mockImplementation((type, payload) => {
			saved.push([type, JSON.stringify(payload)])
			return Promise.resolve({ id: 'new', type })
		})
	})

	afterEach(() => jest.useRealTimers())

	it.each(['', '   '])('refuses the empty name %j', async (name) => {
		objectStore.objectItem = OBJECTS.plain()
		const ctx = context({ copyName: name })
		await ctx.copyObject()
		expect(state(ctx)).toMatchSnapshot()
		expect(objectStore.saveObject).not.toHaveBeenCalled()
	})

	it.each(Object.keys(OBJECTS))('copies %s', async (name) => {
		objectStore.objectItem = OBJECTS[name]()
		const ctx = context({ copyName: '  The copy  ' })
		await ctx.copyObject().catch((error) => saved.push(['threw', error.message]))
		const before = state(ctx)
		jest.runAllTimers()
		expect({
			saved,
			before,
			after: state(ctx),
			item: JSON.stringify(objectStore.objectItem),
			registered: objectStore.registerObjectType.mock.calls,
			refreshed: objectStore.refreshObjectList.mock.calls,
			closed: ctx.closeDialog.mock.calls.length,
			selected: objectStore.setObjectItem.mock.calls,
			modal: navigationStore.setModal.mock.calls,
		}).toMatchSnapshot()
	})

	it.each([
		['no saved object', () => Promise.resolve(null)],
		['a failing save', () => Promise.reject(new Error('Save failed'))],
		['a failure without a message', () => Promise.reject({})],
	])('reports %s', async (name, answer) => {
		objectStore.objectItem = OBJECTS.named()
		objectStore.saveObject.mockImplementation(answer)
		const ctx = context({ copyName: 'Copy', success: null })
		await ctx.copyObject()
		jest.runAllTimers()
		expect({
			state: state(ctx),
			selected: objectStore.setObjectItem.mock.calls,
			refreshed: objectStore.refreshObjectList.mock.calls,
		}).toMatchSnapshot()
	})
})

describe('copyPayload', () => {
	it.each(Object.keys(OBJECTS))('builds the payload of %s', (name) => {
		const original = OBJECTS[name]()
		const payload = copyPayload(original, 'Copy of {name} ({id})')
		expect({
			payload: JSON.stringify(payload),
			original: JSON.stringify(original),
		}).toMatchSnapshot()
	})
})
