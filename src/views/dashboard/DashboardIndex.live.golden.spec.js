/**
 * Golden record of the dashboard's live collection subscription: when it
 * subscribes, what it registers, and how it drops a subscription that went
 * stale while it was awaited.
 *
 * @spec exclude Golden record of client-side live subscription wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DashboardIndex from './DashboardIndex.vue'
import { objectStore, registerStore, schemaStore } from '../../store/store.js'

const mockLog = []

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CalendarRange.vue', () => ({}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileTreeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/History.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	dashboardStore: {},
	registerStore: {},
	schemaStore: {},
	objectStore: {},
}))

/**
 * A view context with the view's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function view(state) {
	const ctx = {
		liveHandle: null,
		liveType: '',
		livePendingType: '',
		liveEpoch: 0,
		liveRefetchTimer: null,
		...state,
	}
	for (const [name, method] of Object.entries(DashboardIndex.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * Install the stores; subscribe answers with `reply`.
 *
 * @param {object} options types, register, schema and the subscribe reply
 */
function stores(options) {
	for (const key of Object.keys(objectStore)) delete objectStore[key]
	Object.assign(objectStore, {
		objectTypes: options.types ?? [],
		registerObjectType: (...args) =>
			mockLog.push(['registerObjectType', ...args]),
		unsubscribe: (handle) => mockLog.push(['unsubscribe', handle]),
	})
	if (options.reply !== 'none') {
		objectStore.subscribe = (...args) => {
			mockLog.push(['subscribe', ...args])
			return options.reply instanceof Error || options.reply === 'plain'
				? Promise.reject(
						options.reply === 'plain' ? 'plain reason' : options.reply,
					)
				: Promise.resolve(options.reply)
		}
	}
	registerStore.registerItem = options.register ?? { id: 1 }
	schemaStore.schemaItem = options.schema ?? { id: 2 }
}

/**
 * The state the subscription writes.
 *
 * @param {object} ctx The context
 * @return {object} The state
 */
function written(ctx) {
	return {
		liveHandle: ctx.liveHandle,
		liveType: ctx.liveType,
		livePendingType: ctx.livePendingType,
		liveEpoch: ctx.liveEpoch,
	}
}

const CASES = {
	noSubscribe: [{ liveCollectionType: '1-2' }, { reply: 'none' }],
	unscoped: [
		{ liveCollectionType: '', liveHandle: 'h0', liveType: 'x' },
		{ reply: 'h1' },
	],
	newType: [{ liveCollectionType: '1-2' }, { reply: 'h1' }],
	knownType: [{ liveCollectionType: '1-2' }, { types: ['1-2'], reply: 'h1' }],
	noRegister: [{ liveCollectionType: '1-2' }, { register: {}, reply: 'h1' }],
	noSchema: [{ liveCollectionType: '1-2' }, { schema: { id: 0 }, reply: 'h1' }],
	alreadyLive: [
		{ liveCollectionType: '1-2', liveHandle: 'h0', liveType: '1-2' },
		{ reply: 'h1' },
	],
	otherLive: [
		{ liveCollectionType: '1-2', liveHandle: 'h0', liveType: '3-4' },
		{ reply: 'h1' },
	],
	pending: [
		{ liveCollectionType: '1-2', livePendingType: '1-2' },
		{ reply: 'h1' },
	],
	fails: [{ liveCollectionType: '1-2' }, { reply: new Error('offline') }],
	failsWithoutMessage: [{ liveCollectionType: '1-2' }, { reply: 'plain' }],
}

describe('DashboardIndex live subscription golden', () => {
	beforeEach(() => {
		mockLog.length = 0
		jest.spyOn(console, 'warn').mockImplementation((...args) =>
			mockLog.push(['warn', ...args]),
		)
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	for (const [name, [state, options]] of Object.entries(CASES)) {
		it(`subscribes: ${name}`, async () => {
			stores(options)
			const ctx = view(state)
			await ctx.syncLiveSubscription()
			expect({ log: mockLog, after: written(ctx) }).toMatchSnapshot()
		})
	}

	it('drops a subscription released while it was awaited', async () => {
		stores({ reply: 'late' })
		const ctx = view({ liveCollectionType: '1-2' })
		const pending = ctx.syncLiveSubscription()
		const during = written(ctx)
		ctx.releaseLiveSubscription()
		await pending
		expect({ during, log: mockLog, after: written(ctx) }).toMatchSnapshot()
	})

	it('keeps the pending type of a newer subscribe', async () => {
		stores({ reply: 'late' })
		const ctx = view({ liveCollectionType: '1-2' })
		const pending = ctx.syncLiveSubscription()
		ctx.livePendingType = 'newer'
		await pending
		expect({ log: mockLog, after: written(ctx) }).toMatchSnapshot()
	})
})
