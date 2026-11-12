/**
 * Golden record of DeleteObject::deleteObject: the type it registers, the
 * delete it asks the store for and the state it leaves, for complete and
 * incomplete objects, a refused and a thrown delete.
 *
 * @spec exclude Golden record of client-side modal orchestration.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeleteObject from './DeleteObject.vue'
import { objectStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn() },
	objectStore: {},
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('../shared/ConfirmDialogFrame.vue', () => ({}))

const ITEMS = {
	complete: { '@self': { register: 2, schema: 3, id: 'o1' } },
	knownType: { '@self': { register: 2, schema: 4, id: 'o2' } },
	noId: { '@self': { register: 2, schema: 3 } },
	noSelf: {},
	none: null,
}
const ANSWERS = {
	deleted: () => Promise.resolve(true),
	refusedWithError: () => Promise.resolve(false),
	refusedPlain: () => Promise.resolve(false),
	thrown: () => Promise.reject(new Error('gone')),
	thrownNoMessage: () => Promise.reject(new Error('')),
}

/**
 * A bare DeleteObject context.
 *
 * @return {object} The context
 */
function context() {
	const ctx = {}
	for (const mixin of DeleteObject.mixins) {
		Object.assign(ctx, mixin.data.call({}))
		for (const [name, method] of Object.entries(mixin.methods)) {
			ctx[name] = method.bind(ctx)
		}
	}
	for (const [name, method] of Object.entries(DeleteObject.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.useFakeTimers()
})
afterEach(() => {
	jest.useRealTimers()
})

describe('DeleteObject::deleteObject (golden)', () => {
	for (const [itemName, item] of Object.entries(ITEMS)) {
		for (const answerName of Object.keys(ANSWERS)) {
			it(`deletes ${itemName}, answering ${answerName}`, async () => {
				const trace = []
				objectStore.objectItem = item
				objectStore.objectTypes = ['object-2-4']
				objectStore.errors =
					answerName === 'refusedWithError'
						? { 'object-2-3': 'locked', 'object-2-4': 'held' }
						: {}
				objectStore.createObjectTypeSlug = (register, schema) =>
					`object-${register}-${schema}`
				objectStore.registerObjectType = (...args) =>
					trace.push(['register', ...args])
				objectStore.deleteObject = (...args) => {
					trace.push(['delete', ...args])
					return ANSWERS[answerName]()
				}
				objectStore.refetchSearchCollection = () => trace.push(['refetch'])
				const ctx = context()
				await ctx.deleteObject()
				expect({
					trace,
					success: ctx.success,
					error: ctx.error,
					loading: ctx.loading,
					closing: ctx.closeModalTimeout !== null,
				}).toMatchSnapshot()
			})
		}
	}
})
