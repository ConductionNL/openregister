import axios from '@nextcloud/axios'
import FlowOverview from './FlowOverview.vue'

/**
 * Golden record of the flow page's load: the flow read, then its versions,
 * the node catalog and its runs at once, and what an unreadable flow leaves.
 *
 * @spec exclude Golden record of the client-side flow page load.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text) => text,
	translate: (app, text) => text,
	n: (app, one, many) => many,
	translatePlural: (app, one, many) => many,
	getCanonicalLocale: () => 'en-GB',
	getLanguage: () => 'en',
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('vue-material-design-icons/SitemapOutline.vue', () => ({ __esModule: true, default: {} }))

const ANSWERS = {
	'/gen/apps/openregister/api/flows/f-1': { id: 'f-1', name: 'Enquiry' },
	'/gen/apps/openregister/api/flows/f-1/versions': { results: [{ version: 2 }] },
	'/gen/apps/openregister/api/flow/node-catalog': { results: [{ id: 'n.a', displayName: 'Node A' }] },
}

/**
 * Load the flow on a bare page carrying the page's methods; the runs load
 * only records that it ran.
 *
 * @param {object} answers Response data by URL; a missing URL fails
 * @return {Promise<object>} The page data afterwards and what happened
 */
async function load(answers) {
	const log = []
	const page = { id: 'f-1', loading: false, actionError: 'old', flow: { stale: true }, versions: ['old'], names: { old: 1 } }
	const methods = Object.keys(FlowOverview.methods)
	for (const name of methods) {
		page[name] = FlowOverview.methods[name].bind(page)
	}
	page.loadRuns = async () => log.push(['loadRuns', page.loading])
	axios.get.mockImplementation((url, config) => {
		log.push(['get', url, config, page.loading, page.actionError])
		return url in answers ? Promise.resolve({ data: answers[url] }) : Promise.reject(new Error('404'))
	})
	await page.load()
	for (const name of [...methods, 'loadRuns']) {
		delete page[name]
	}
	return { page, log }
}

describe('FlowOverview.load', () => {
	beforeEach(() => axios.get.mockReset())

	it('reads the flow, then its versions, catalog and runs', async () => {
		expect(await load(ANSWERS)).toMatchSnapshot()
	})

	it('leaves the panels empty when only the flow can be read', async () => {
		expect(await load({ '/gen/apps/openregister/api/flows/f-1': ANSWERS['/gen/apps/openregister/api/flows/f-1'] })).toMatchSnapshot()
	})

	it('goes on with an empty flow answer', async () => {
		expect(await load({ ...ANSWERS, '/gen/apps/openregister/api/flows/f-1': '' })).toMatchSnapshot()
	})

	it('stops with no flow when the flow cannot be read', async () => {
		expect(await load({})).toMatchSnapshot()
	})
})
