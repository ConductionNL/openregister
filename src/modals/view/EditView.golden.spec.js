/**
 * Golden record of EditView's save and user search: the store and OCS calls,
 * the state left behind, what is logged and what is emitted.
 *
 * @spec exclude Golden record of client-side modal orchestration.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditView from './EditView.vue'
import { viewsStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({ viewsStore: {} }))
jest.mock('../../services/groupSearch.js', () => ({
	searchGroupsDebounced: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/ShareVariant.vue', () => ({}))
jest.mock('../../components/shared/GroupSelect.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))

let logged
let emitted

/**
 * A bare EditView context.
 *
 * @param {object} state State on top of the data
 * @return {object} The context
 */
function context(state) {
	const ctx = { ...EditView.data.call({}), ...state }
	for (const [name, method] of Object.entries(EditView.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.t = (app, s) => s
	ctx.$emit = (...args) => emitted.push(args)
	return ctx
}

beforeEach(() => {
	logged = []
	emitted = []
	for (const level of ['error', 'warn']) {
		jest.spyOn(console, level).mockImplementation((...args) =>
			logged.push([
				level,
				...args.map((arg) =>
					arg instanceof Error ? `Error(${arg.message})` : arg,
				),
			]),
		)
	}
	jest.useFakeTimers()
})
afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
})

describe('EditView.saveView (golden)', () => {
	const ANSWERS = {
		saved: () => Promise.resolve({}),
		serverError: () =>
			Promise.reject(
				Object.assign(new Error('400'), {
					response: { data: { error: 'Name taken' } },
				}),
			),
		plainError: () => Promise.reject(new Error('offline')),
		silentError: () => Promise.reject(new Error('')),
	}
	const FORMS = {
		full: {
			view: { id: 7, uuid: 'u7' },
			viewData: {
				name: '  Mine  ',
				description: 'd',
				isPublic: true,
				isDefault: false,
				query: { a: 1 },
			},
			selectedGroups: [{ id: 'g1', mode: 'write' }, { id: 'g2' }],
			selectedUsers: [{ id: 'alice' }],
		},
		uuidOnly: {
			view: { uuid: 'u8' },
			viewData: {
				name: 'X',
				description: '',
				isPublic: false,
				isDefault: true,
				query: {},
			},
		},
		blankName: { view: { id: 9 }, viewData: { name: '   ', query: {} } },
	}

	for (const [formName, form] of Object.entries(FORMS)) {
		for (const [answerName, answer] of Object.entries(ANSWERS)) {
			it(`saves the ${formName} form, answering ${answerName}`, async () => {
				const calls = []
				viewsStore.updateView = (...args) => {
					calls.push(['updateView', ...args])
					return answer()
				}
				viewsStore.fetchViews = () => {
					calls.push(['fetchViews'])
					return Promise.resolve()
				}
				const ctx = context(form)
				await ctx.saveView()
				const before = { emitted: [...emitted] }
				jest.runAllTimers()
				expect({
					calls,
					before,
					emitted,
					logged,
					success: ctx.success,
					error: ctx.error,
					loading: ctx.loading,
					nameTouched: ctx.nameTouched,
				}).toMatchSnapshot()
			})
		}
	}
})

describe('EditView.searchUsers (golden)', () => {
	const ANSWERS = {
		users: () => ({
			ok: true,
			json: async () => ({ ocs: { data: { users: ['alice', 'bob'] } } }),
		}),
		noUsers: () => ({ ok: true, json: async () => ({ ocs: { data: {} } }) }),
		refused: () => ({ ok: false, statusText: 'Forbidden' }),
		thrown: () => {
			throw new Error('offline')
		},
	}

	for (const [answerName, answer] of Object.entries(ANSWERS)) {
		it(`searches users, answering ${answerName}`, async () => {
			const calls = []
			global.fetch = jest.fn(async (...args) => {
				calls.push(args)
				return answer()
			})
			const ctx = context({
				selectedUsers: [{ id: 'alice', name: 'Alice' }],
				availableUsers: ['stale'],
			})
			ctx.searchUsers('a l')
			const pending = { loadingUsers: ctx.loadingUsers, calls: calls.length }
			jest.advanceTimersByTime(300)
			for (let tick = 0; tick < 20; tick++) {
				await Promise.resolve()
			}
			expect({
				pending,
				calls,
				availableUsers: ctx.availableUsers,
				loadingUsers: ctx.loadingUsers,
				logged,
			}).toMatchSnapshot()
		})
	}

	it.each([[''], ['   '], [null]])(
		'clears the results for the empty search %j',
		(query) => {
			global.fetch = jest.fn()
			const ctx = context({ availableUsers: ['stale'] })
			ctx.searchUsers(query)
			jest.runAllTimers()
			expect(ctx.availableUsers).toEqual([])
			expect(global.fetch).not.toHaveBeenCalled()
		},
	)

	it('restarts the debounce on every keystroke', () => {
		global.fetch = jest.fn(async () => ({ ok: false, statusText: 'x' }))
		const ctx = context({})
		ctx.searchUsers('a')
		jest.advanceTimersByTime(200)
		ctx.searchUsers('ab')
		jest.advanceTimersByTime(200)
		expect(global.fetch).not.toHaveBeenCalled()
		jest.advanceTimersByTime(100)
		expect(global.fetch).toHaveBeenCalledTimes(1)
		expect(global.fetch.mock.calls[0][0]).toContain('search=ab')
	})
})
