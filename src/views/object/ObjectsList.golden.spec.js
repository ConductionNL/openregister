import ObjectsList from './ObjectsList.vue'
import { objectStore, registerStore, schemaStore } from '../../store/store.js'

// The live collection subscription: what it asks the store and the state it keeps.

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOpenOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))

/**
 * A bare list context with every method bound to it.
 *
 * @param {object} state Live state to start from
 * @return {object} The context
 */
function context(state) {
	const ctx = { ...ObjectsList.data.call({}), ...state }
	for (const [name, method] of Object.entries(ObjectsList.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const STATES = {
	idle: {},
	sameLive: { liveHandle: 'h-old', liveType: 'r1-s1' },
	otherLive: { liveHandle: 'h-old', liveType: 'r2-s2' },
	samePending: { livePendingType: 'r1-s1' },
	otherPending: { livePendingType: 'r2-s2', liveType: 'r2-s2' },
	staleType: { liveType: 'r1-s1' },
}

const SUBSCRIBES = {
	resolves: () => Promise.resolve('h-new'),
	seesState: (ctx) => Promise.resolve(`h-${ctx.livePendingType}-${ctx.liveType}`),
	rejectsError: () => Promise.reject(new Error('down')),
	rejectsPlain: () => Promise.reject('plain'),
	rejectsNull: () => Promise.reject(null),
	releasedWhileWaiting: (ctx) => {
		ctx.liveEpoch += 1
		return Promise.resolve('h-stale')
	},
	pendingCleared: (ctx) => {
		ctx.livePendingType = 'r9-s9'
		return Promise.resolve('h-new')
	},
}

const STORES = {
	known: { types: ['r1-s1'], register: { id: 1 }, schema: { id: 2 } },
	unknownScoped: { types: [], register: { id: 1 }, schema: { id: 2 } },
	unknownNoRegister: { types: [], register: null, schema: { id: 2 } },
	unknownNoSchema: { types: [], register: { id: 1 }, schema: {} },
	registerThrows: {
		types: [],
		register: { id: 1 },
		schema: { id: 2 },
		throws: true,
	},
}

/**
 * Point the store mocks at one scenario and log every call.
 *
 * @param {object} scenario The scenario
 * @return {Array} The call log
 */
function arrange(scenario) {
	const log = []
	objectStore.currentType = scenario.type
	objectStore.objectTypes = scenario.store.types
	registerStore.registerItem = scenario.store.register
	schemaStore.schemaItem = scenario.store.schema
	objectStore.registerObjectType = (...args) => {
		log.push(['registerObjectType', ...args])
		if (scenario.store.throws) {
			throw new Error('bad type')
		}
	}
	objectStore.unsubscribe = (handle) => log.push(['unsubscribe', handle])
	if (scenario.subscribe) {
		objectStore.subscribe = (type) => {
			log.push(['subscribe', type])
			return scenario.subscribe(scenario.ctx)
		}
	} else {
		delete objectStore.subscribe
	}
	return log
}

describe('ObjectsList.syncLiveSubscription', () => {
	let warn

	beforeEach(() => {
		warn = jest.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => warn.mockRestore())

	for (const type of ['r1-s1', '']) {
		for (const [stateName, state] of Object.entries(STATES)) {
			for (const [storeName, store] of Object.entries(STORES)) {
				for (const [subName, subscribe] of Object.entries({
					...SUBSCRIBES,
					missing: null,
				})) {
					it(`type "${type}", ${stateName}, ${storeName}, subscribe ${subName}`, async () => {
						const ctx = context(state)
						const log = arrange({ type, store, subscribe, ctx })
						await ctx.syncLiveSubscription()
						const live = {}
						for (const key of [
							'liveHandle',
							'liveType',
							'livePendingType',
							'liveEpoch',
						]) {
							live[key] = ctx[key]
						}
						expect({
							log,
							live,
							warn: warn.mock.calls,
						}).toMatchSnapshot()
					})
				}
			}
		}
	}
})
