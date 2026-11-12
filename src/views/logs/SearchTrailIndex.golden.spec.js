import SearchTrailIndex from './SearchTrailIndex.vue'
import { searchTrailStore } from '../../store/store.js'

// Bulk delete and cleanup: what is confirmed, what is sent, what the user is told.

jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({
	__esModule: true,
	default: { render: () => null },
}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({
	__esModule: true,
	default: { render: () => null },
}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({
	__esModule: true,
	default: { render: () => null },
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	searchTrailStore: {},
}))
jest.mock('../../eventBus.js', () => ({ $on: () => {}, $off: () => {} }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s) => s,
	translatePlural: (app, s) => s,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../components/PaginationComponent.vue', () => ({}))
jest.mock('vue-material-design-icons/Broom.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))

/**
 * A bare index context with every method bound and the reload recorded.
 *
 * @param {Array} log Where calls are recorded
 * @param {object} state State to start from
 * @return {object} The context
 */
function context(log, state) {
	const ctx = { ...state }
	ctx.t = (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text)
	for (const [name, method] of Object.entries(SearchTrailIndex.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.loadSearchTrails = async () => {
		log.push(['loadSearchTrails', [...ctx.selectedSearchTrails]])
		if (state.reloadFails) {
			throw new Error('reload down')
		}
	}
	return ctx
}

const ANSWERS = {
	success: () => Promise.resolve({ success: true, message: 'Deleted 2' }),
	successUnnamed: () => Promise.resolve({ success: true }),
	refused: () => Promise.resolve({ success: false, error: 'Not allowed' }),
	refusedSilent: () => Promise.resolve({ success: false }),
	brokenJson: () => Promise.reject(new Error('Unexpected token')),
	refusedMessage: () => Promise.resolve({ success: false, message: 'Busy' }),
	successCount: () => Promise.resolve({ success: true, deletedCount: 7 }),
	successZero: () => Promise.resolve({ success: true, deletedCount: 0 }),
}

describe('SearchTrailIndex bulk delete and cleanup', () => {
	let log

	beforeEach(() => {
		log = []
		jest.spyOn(console, 'error').mockImplementation((...args) =>
			log.push(['console.error', args[0], String(args[1])]),
		)
		global.OC = {
			Notification: {
				showTemporary: (...args) => log.push(['notify', ...args]),
			},
		}
	})

	afterEach(() => jest.restoreAllMocks())

	for (const selected of [[], ['a', 'b']]) {
		for (const confirmed of [true, false]) {
			for (const [answerName, answer] of Object.entries(ANSWERS)) {
				it(`bulk deletes ${selected.length} selected, confirmed ${confirmed}, answering ${answerName}`, async () => {
					global.confirm = (text) =>
						log.push(['confirm', text]) && confirmed
					global.fetch = (url, init) => {
						log.push(['fetch', url, init])
						return Promise.resolve({ json: answer })
					}
					const ctx = context(log, { selectedSearchTrails: [...selected] })
					await ctx.bulkDeleteSearchTrails()
					expect({
						log,
						selected: ctx.selectedSearchTrails,
					}).toMatchSnapshot()
				})
			}
		}
	}

	for (const confirmed of [true, false]) {
		for (const [answerName, answer] of Object.entries({
			...ANSWERS,
			storeThrows: null,
		})) {
			it(`cleans up, confirmed ${confirmed}, answering ${answerName}`, async () => {
				global.confirm = (text) => log.push(['confirm', text]) && confirmed
				searchTrailStore.cleanupSearchTrails = (days) => {
					log.push(['cleanupSearchTrails', days])
					return answer
						? answer()
						: Promise.reject(new Error('store down'))
				}
				const ctx = context(log, { selectedSearchTrails: ['x'] })
				await ctx.cleanupSearchTrails()
				expect({ log }).toMatchSnapshot()
			})
		}
	}

	for (const method of ['bulkDeleteSearchTrails', 'cleanupSearchTrails']) {
		it(`${method} reports a reload that fails`, async () => {
			global.confirm = () => true
			global.fetch = () => Promise.resolve({ json: ANSWERS.successCount })
			searchTrailStore.cleanupSearchTrails = ANSWERS.successCount
			const ctx = context(log, {
				selectedSearchTrails: ['a'],
				reloadFails: true,
			})
			await ctx[method]()
			expect({ log, selected: ctx.selectedSearchTrails }).toMatchSnapshot()
		})
	}
})
