/**
 * Golden record of the operations console load: the three reads it asks at
 * once, the panes it fills from them, the further panes it then loads, and
 * the one error it shows when any of that fails.
 *
 * @spec exclude Golden record of the client-side console load.
 */
import axios from '@nextcloud/axios'
import OperationsConsoleIndex from './OperationsConsoleIndex.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, t: (app, text) => `t:${text}`, n: (app, one, many) => many }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))

const FULL = {
	'/gen/apps/openregister/api/operations/console': { panes: [{ id: 'p' }], window: { hours: 48 } },
	'/gen/apps/openregister/api/operations/jobs': { results: [{ id: 'j' }], unobserved: [{ id: 'u' }] },
	'/gen/apps/openregister/api/operations/rule-runs': { holdingAnError: [{ id: 'r' }] },
}

const EMPTY = {
	'/gen/apps/openregister/api/operations/console': { window: {} },
	'/gen/apps/openregister/api/operations/jobs': {},
	'/gen/apps/openregister/api/operations/rule-runs': {},
}

/**
 * Load the console on a bare context with the console's data and methods;
 * the further pane loads only record that they ran.
 *
 * @param {object} answers Response data by URL; a missing URL fails
 * @param {string} failingPane A further pane load that fails, if any
 * @return {Promise<object>} The data afterwards and what happened
 */
async function load(answers, failingPane = '') {
	const log = []
	const ctx = { ...OperationsConsoleIndex.data.call({}), error: 'old' }
	const methods = Object.keys(OperationsConsoleIndex.methods)
	for (const name of methods) {
		ctx[name] = OperationsConsoleIndex.methods[name].bind(ctx)
	}
	for (const pane of ['loadRuns', 'loadMaintenance', 'loadFacts']) {
		ctx[pane] = async () => {
			log.push([pane, ctx.loading, ctx.panes.length])
			if (pane === failingPane) {
				throw new Error('pane down')
			}
		}
	}
	axios.get.mockImplementation((url) => {
		log.push(['get', url, ctx.loading, ctx.error])
		return url in answers ? Promise.resolve({ data: answers[url] }) : Promise.reject(new Error('404'))
	})
	await ctx.load()
	for (const name of [...methods, 'loadRuns', 'loadMaintenance', 'loadFacts']) {
		delete ctx[name]
	}
	return { ctx, log }
}

describe('OperationsConsoleIndex.load', () => {
	beforeEach(() => axios.get.mockReset())

	it('fills the panes, then loads the runs, maintenance and facts', async () => {
		expect(await load(FULL)).toMatchSnapshot()
	})

	it('falls back to empty panes and a 24-hour window', async () => {
		expect(await load(EMPTY)).toMatchSnapshot()
	})

	it('shows the error and loads no further pane when a read fails', async () => {
		const { '/gen/apps/openregister/api/operations/jobs': jobs, ...rest } = FULL
		expect(jobs).toBeDefined()
		expect(await load(rest)).toMatchSnapshot()
	})

	it('shows the error when a further pane fails', async () => {
		expect(await load(FULL, 'loadMaintenance')).toMatchSnapshot()
	})
})
