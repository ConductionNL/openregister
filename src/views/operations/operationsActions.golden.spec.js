/**
 * Golden record of the operations console's actions: the request each one
 * sends, what it reloads, what it shows as busy while it runs, and the error
 * it shows for each shape of refusal.
 *
 * @spec exclude Golden record of client-side console actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import OperationsConsoleIndex from './OperationsConsoleIndex.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text) => `t:${text}`,
	n: (app, one, many) => many,
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))

const ACTIONS = {
	act: (ctx) => ctx.act({ id: 'job-1' }, 'retry'),
	runNow: (ctx) => ctx.runNow('cleanup'),
	enterMaintenance: (ctx) => {
		ctx.maintenance = { holds: false, message: 'Back soon' }
		return ctx.enterMaintenance()
	},
	enterMaintenanceSilently: (ctx) => {
		ctx.maintenance = { holds: false, message: '' }
		return ctx.enterMaintenance()
	},
	leaveMaintenance: (ctx) => ctx.leaveMaintenance(),
}

const OUTCOMES = {
	ok: () => Promise.resolve({ data: { holds: true, message: 'Closed' } }),
	refusedWithError: () =>
		Promise.reject({ response: { data: { error: 'Locked', message: 'Held' } } }),
	refusedWithMessage: () =>
		Promise.reject({ response: { data: { message: 'Held by run 7' } } }),
	refusedBare: () => Promise.reject(new Error('network')),
}

/**
 * A bare component context with the console's data and methods; the
 * reloads only record that they ran.
 *
 * @param {Array} log Where calls are recorded
 * @return {object} The context
 */
function context(log) {
	const ctx = { ...OperationsConsoleIndex.data.call({}) }
	for (const [name, method] of Object.entries(OperationsConsoleIndex.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.load = async () => log.push(['load', ctx.acting])
	ctx.loadRuns = async () => log.push(['loadRuns', ctx.acting])
	return ctx
}

describe('operations console actions golden', () => {
	for (const [action, run] of Object.entries(ACTIONS)) {
		for (const [outcome, answer] of Object.entries(OUTCOMES)) {
			it(`${action} when ${outcome}`, async () => {
				const log = []
				const ctx = context(log)
				ctx.error = 'stale'
				for (const verb of ['post', 'delete']) {
					axios[verb].mockReset()
					axios[verb].mockImplementation((...args) => {
						log.push([verb, ...args, ctx.acting, ctx.error])
						return answer()
					})
				}
				await run(ctx)
				expect({
					log,
					acting: ctx.acting,
					error: ctx.error,
					maintenance: ctx.maintenance,
				}).toMatchSnapshot()
			})
		}
	}
})
