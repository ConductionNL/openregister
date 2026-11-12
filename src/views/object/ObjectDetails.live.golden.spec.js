/**
 * Golden record of the object detail view's live subscription: when it
 * subscribes, what it registers, how it bridges fresh objects into the view,
 * and how it drops a subscription that went stale while it was awaited.
 *
 * @spec exclude Golden record of client-side live subscription wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ObjectDetails from './ObjectDetails.vue'
import { objectStore } from '../../store/store.js'

const mockLog = []

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/ExclamationThick.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FileOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FolderOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOpenOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/TimelineQuestionOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountOff.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CloseCircleOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TableLarge.vue', () => ({}))
jest.mock('vue-material-design-icons/TableLargePlus.vue', () => ({}))
jest.mock('vue-material-design-icons/TableRemove.vue', () => ({}))
jest.mock('vue-material-design-icons/EmailOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/EmailOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CalendarOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CalendarPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/CalendarRemove.vue', () => ({}))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/LinkVariantOff.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
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
		liveKey: '',
		livePendingKey: '',
		liveEpoch: 0,
		liveUnwatch: null,
		...state,
	}
	for (const [name, method] of Object.entries(ObjectDetails.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.$watch = (source, callback) => {
		mockLog.push(['watch', source()])
		ctx.watched = callback
		return () => mockLog.push(['unwatch'])
	}
	return ctx
}

/**
 * Install a store whose subscribe answers with `reply`.
 *
 * @param {object} options objectItem, objectTypes and the subscribe reply
 */
function store(options) {
	for (const key of Object.keys(objectStore)) delete objectStore[key]
	Object.assign(objectStore, {
		objectItem: options.item,
		objectTypes: options.types ?? [],
		registerObjectType: (...args) =>
			mockLog.push(['registerObjectType', ...args]),
		unsubscribe: (handle) => mockLog.push(['unsubscribe', handle]),
		getObject: (...args) => ['cached', ...args],
		setObjectItem: (item) => mockLog.push(['setObjectItem', item]),
	})
	if (options.reply !== 'none') {
		objectStore.subscribe = (...args) => {
			mockLog.push(['subscribe', ...args])
			return options.reply instanceof Error
				? Promise.reject(options.reply)
				: Promise.resolve(options.reply)
		}
	}
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
		liveKey: ctx.liveKey,
		livePendingKey: ctx.livePendingKey,
		liveEpoch: ctx.liveEpoch,
		watching: Boolean(ctx.liveUnwatch),
	}
}

const CTX = { register: 1, schema: 2, id: 'ctx-id' }
const SELF_ITEM = { uuid: 'root-uuid', '@self': { uuid: 'self-uuid' } }

const CASES = {
	noSubscribe: [{ relationContext: CTX }, { item: SELF_ITEM, reply: 'none' }],
	noContext: [
		{ relationContext: null, liveHandle: 'h0', liveKey: 'k' },
		{ item: SELF_ITEM, reply: 'h1' },
	],
	noUuid: [
		{ relationContext: { register: 1, schema: 2 } },
		{ item: {}, reply: 'h1' },
	],
	selfUuid: [{ relationContext: CTX }, { item: SELF_ITEM, reply: 'h1' }],
	rootUuid: [
		{ relationContext: CTX },
		{ item: { uuid: 'root-uuid' }, reply: 'h1' },
	],
	contextId: [{ relationContext: CTX }, { item: null, reply: 'h1' }],
	knownType: [
		{ relationContext: CTX },
		{ item: SELF_ITEM, types: ['1-2'], reply: 'h1' },
	],
	alreadyLive: [
		{ relationContext: CTX, liveHandle: 'h0', liveKey: '1-2::self-uuid' },
		{ item: SELF_ITEM, reply: 'h1' },
	],
	otherLive: [
		{ relationContext: CTX, liveHandle: 'h0', liveKey: '1-2::other' },
		{ item: SELF_ITEM, reply: 'h1' },
	],
	pending: [
		{ relationContext: CTX, livePendingKey: '1-2::self-uuid' },
		{ item: SELF_ITEM, reply: 'h1' },
	],
	fails: [
		{ relationContext: CTX },
		{ item: SELF_ITEM, reply: new Error('offline') },
	],
	failsWithoutMessage: [
		{ relationContext: CTX },
		{ item: SELF_ITEM, reply: Promise.reject.bind(Promise) },
	],
}

describe('ObjectDetails live subscription golden', () => {
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
			store(
				options.reply === Promise.reject.bind(Promise)
					? { ...options, reply: 'x' }
					: options,
			)
			if (options.reply === Promise.reject.bind(Promise)) {
				objectStore.subscribe = () => Promise.reject('plain reason')
			}
			const ctx = view(state)
			await ctx.syncLiveSubscription()
			const after = written(ctx)
			if (ctx.watched) {
				ctx.watched({ fresh: 1 })
				ctx.watched(null)
				ctx.liveKey = 'moved'
				ctx.watched({ fresh: 2 })
			}
			expect({ log: mockLog, after }).toMatchSnapshot()
		})
	}

	it('drops a subscription released while it was awaited', async () => {
		store({ item: SELF_ITEM, reply: 'late' })
		const ctx = view({ relationContext: CTX })
		const pending = ctx.syncLiveSubscription()
		expect(written(ctx)).toMatchSnapshot()
		ctx.releaseLiveSubscription()
		await pending
		expect({ log: mockLog, after: written(ctx) }).toMatchSnapshot()
	})

	it('keeps the pending key of a newer subscribe', async () => {
		store({ item: SELF_ITEM, reply: 'late' })
		const ctx = view({ relationContext: CTX })
		const pending = ctx.syncLiveSubscription()
		ctx.livePendingKey = 'newer'
		await pending
		expect({ log: mockLog, after: written(ctx) }).toMatchSnapshot()
	})
})
