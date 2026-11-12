import PurgeMultiple from './PurgeMultiple.vue'
import eventBus from '../../eventBus.js'
import { deletedStore, navigationStore } from '../../store/store.js'

// Purging deleted objects: the ids sent, the message shown and the event raised.

jest.mock('../../store/store.js', () => ({
	deletedStore: {},
	navigationStore: {},
}))
jest.mock('../../eventBus.js', () => ({ emit: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))

/**
 * A bare dialog context with every method and computed bound to it.
 *
 * @param {Array|null} selected The selected objects
 * @return {object} The context
 */
function context(selected) {
	const ctx = {
		...PurgeMultiple.data.call({}),
		selectedObjects: selected,
		error: 'old error',
	}
	for (const [name, method] of Object.entries(PurgeMultiple.methods)) {
		ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(PurgeMultiple.computed)) {
		Object.defineProperty(ctx, name, {
			get: () => getter.call(ctx),
			enumerable: false,
		})
	}
	return ctx
}

const SELECTIONS = {
	missing: null,
	empty: [],
	two: [{ id: 'a' }, { id: 7 }],
}

const ANSWERS = {
	allDeleted: () => Promise.resolve({ deleted: 2, failed: 0 }),
	someFailed: () => Promise.resolve({ deleted: 1, failed: 1 }),
	noneDeleted: () => Promise.resolve({ deleted: 0, failed: 2 }),
	noCounts: () => Promise.resolve({}),
	textCounts: () => Promise.resolve({ deleted: '2', failed: '1' }),
	throwsNamed: () => Promise.reject(new Error('Denied')),
	throwsSilent: () => Promise.reject(new Error('')),
	nullResult: () => Promise.resolve(null),
}

describe('PurgeMultiple.permanentlyDeleteMultiple', () => {
	let log

	beforeEach(() => {
		log = []
		jest.useFakeTimers()
		eventBus.emit.mockImplementation((...args) => log.push(['emit', ...args]))
		navigationStore.setDialog = (value) => log.push(['setDialog', value])
		deletedStore.clearSelectedForBulkAction = () =>
			log.push(['clearSelectedForBulkAction'])
	})

	afterEach(() => {
		jest.useRealTimers()
		jest.clearAllMocks()
	})

	for (const [selectionName, selected] of Object.entries(SELECTIONS)) {
		for (const [answerName, answer] of Object.entries(ANSWERS)) {
			it(`purges ${selectionName}, answering ${answerName}`, async () => {
				const ctx = context(selected)
				deletedStore.permanentlyDeleteMultiple = (ids) => {
					log.push(['permanentlyDeleteMultiple', ids, ctx.loading])
					return answer()
				}
				await ctx.permanentlyDeleteMultiple()
				const state = {
					success: ctx.success,
					error: ctx.error,
					successMessage: ctx.successMessage,
					loading: ctx.loading,
					timer: ctx.closeModalTimeout !== null,
				}
				jest.advanceTimersByTime(2999)
				log.push(['after 2999 ms'])
				jest.advanceTimersByTime(1)
				expect({ log, state }).toMatchSnapshot()
			})
		}
	}
})
