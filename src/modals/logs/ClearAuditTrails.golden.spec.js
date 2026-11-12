import ClearAuditTrails from './ClearAuditTrails.vue'
import { auditTrailStore, navigationStore } from '../../store/store.js'

// Clearing audit trails: which filters reach the delete request and what the dialog shows after.

jest.mock('../../store/store.js', () => ({
	auditTrailStore: {},
	navigationStore: {},
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s) => s,
	translatePlural: (app, s) => s,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))

/**
 * A bare dialog context with every method and computed bound to it.
 *
 * @return {object} The context
 */
function context() {
	const parts = [...(ClearAuditTrails.mixins ?? []), ClearAuditTrails]
	const ctx = { ...Object.assign({}, ...parts.map((part) => part.data?.call({}))), filteredCount: 4 }
	ctx.t = (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text)
	for (const [name, method] of Object.entries(
		Object.assign({}, ...parts.map((part) => part.methods)),
	)) {
		ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(ClearAuditTrails.computed)) {
		Object.defineProperty(ctx, name, {
			get: () => getter.call(ctx),
			enumerable: false,
		})
	}
	return ctx
}

const FILTERS = {
	none: null,
	empty: {},
	mixed: {
		action: 'delete',
		user: '',
		register: 0,
		schema: null,
		object: undefined,
		date: ['2026-01-01', '2026-02-01'],
		flag: false,
		'odd key': 'a&b=c',
	},
}

const ANSWERS = {
	success: () => Promise.resolve({ success: true, message: 'Cleared 9' }),
	successUnnamed: () => Promise.resolve({ success: true }),
	refused: () => Promise.resolve({ success: false, error: 'Not allowed' }),
	refusedSilent: () => Promise.resolve({ success: false }),
	brokenJson: () => Promise.reject(new Error('Unexpected token')),
	silentThrow: () => Promise.reject(new Error('')),
}

describe('ClearAuditTrails.clearAuditTrails', () => {
	let log

	beforeEach(() => {
		log = []
		jest.useFakeTimers()
		jest.spyOn(console, 'error').mockImplementation((...args) =>
			log.push(['console.error', args[0], String(args[1])]),
		)
	})

	afterEach(() => {
		jest.useRealTimers()
		jest.restoreAllMocks()
	})

	for (const [filterName, filters] of Object.entries(FILTERS)) {
		for (const [answerName, answer] of Object.entries(ANSWERS)) {
			for (const refreshFails of [false, true]) {
				it(`clears with filters ${filterName}, answering ${answerName}, refresh fails ${refreshFails}`, async () => {
					auditTrailStore.auditTrailFilters = filters
					const ctx = context()
					auditTrailStore.refreshAuditTrailList = async () => {
						log.push(['refresh', ctx.loading])
						if (refreshFails) {
							throw new Error('refresh down')
						}
					}
					global.fetch = (url, init) => {
						log.push(['fetch', url, init, ctx.loading])
						return Promise.resolve({ json: answer })
					}
					navigationStore.setDialog = (value) =>
						log.push(['setDialog', value])
					await ctx.clearAuditTrails()
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
	}
})
