import axios from '@nextcloud/axios'
import RegisterDescriptors from './RegisterDescriptors.vue'

/**
 * Golden record of a register descriptor re-import: the request, the
 * outcome it renders for each answer shape, the failure reasons, and that
 * only a successful import re-reads the inventory.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn(), get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('vue-material-design-icons/Download.vue', () => ({ __esModule: true, default: {} }), { virtual: true })
jest.mock('../../../components/shared/SettingsSection.vue', () => ({ __esModule: true, default: {} }))

const ROW = { appId: 'dossiq', slug: 'dossiq-main', title: 'Dossiq' }

let answer

/**
 * Re-import ROW on a bare panel state carrying the panel's methods.
 *
 * @return {Promise<object>} The outcome, the busy flags seen and the reloads
 */
async function reimport() {
	const seen = []
	const panel = { busy: '', outcome: { stale: true } }
	for (const [name, method] of Object.entries(RegisterDescriptors.methods)) {
		panel[name] = method.bind(panel)
	}
	panel.t = (app, text, params) => `${text}${params ? ' ' + JSON.stringify(params) : ''}`
	panel.load = jest.fn(async () => {
		seen.push(['load', panel.busy])
	})
	axios.post.mockImplementation((url) => {
		seen.push(['post', url, panel.busy, panel.outcome])
		return answer()
	})
	await panel.reimport(ROW)
	return { outcome: panel.outcome, busy: panel.busy, seen }
}


const SUCCESSES = {
	countAndBlocks: { counts: { authorizationReplaced: 1 }, replaced: [{ schema: 'x', path: 'authorization' }] },
	countAboveBlocks: { counts: { authorizationReplaced: 3 }, replaced: [{ schema: 'x', path: 'a' }] },
	blocksAboveCount: { counts: { authorizationReplaced: 0 }, replaced: [{ schema: 'x', path: 'a' }, { schema: 'y', path: 'b' }] },
	blocksWithoutCount: { replaced: [{ schema: 'x', path: 'a' }] },
	countWithoutBlocks: { counts: { authorizationReplaced: 2 } },
	blocksNotAList: { counts: { authorizationReplaced: 0 }, replaced: 'x' },
	nothingReplaced: { counts: { authorizationReplaced: 0 }, replaced: [] },
	empty: {},
}

const FAILURES = {
	reason: { response: { data: { reason: 'Locked', error: 'E' } }, message: 'M' },
	error: { response: { data: { error: 'Bad descriptor' } }, message: 'M' },
	message: { message: 'Network down' },
}

describe('RegisterDescriptors.reimport', () => {
	beforeEach(() => axios.post.mockReset())

	it.each(Object.keys(SUCCESSES))('renders the outcome of %s', async (key) => {
		answer = () => Promise.resolve({ data: SUCCESSES[key] })
		expect(await reimport()).toMatchSnapshot()
	})

	it('renders a success for an answer without data', async () => {
		answer = () => Promise.resolve(undefined)
		expect(await reimport()).toMatchSnapshot()
	})

	it.each(Object.keys(FAILURES))('renders a failure worded from its %s', async (key) => {
		answer = () => Promise.reject(FAILURES[key])
		expect(await reimport()).toMatchSnapshot()
	})
})

describe('RegisterDescriptors.load', () => {
	/**
	 * Load the inventory into a bare panel state carrying the panel's methods.
	 *
	 * @return {Promise<object>} The panel data after loading
	 */
	async function load() {
		const panel = { loading: false, error: 'old', rows: ['stale'], total: 9, absent: 9, behind: 9 }
		const methods = Object.keys(RegisterDescriptors.methods)
		for (const name of methods) {
			panel[name] = RegisterDescriptors.methods[name].bind(panel)
		}
		panel.t = (app, text, params) => `${text} ${JSON.stringify(params)}`
		axios.get.mockImplementation(() => {
			panel.seenLoading = panel.loading
			panel.seenError = panel.error
			return answer()
		})
		await panel.load()
		for (const name of [...methods, 't']) {
			delete panel[name]
		}
		return panel
	}

	beforeEach(() => axios.get.mockReset())

	it.each([
		['a full inventory', { results: [{ slug: 'a' }], total: 4, absent: 1, behind: 2 }],
		['an empty answer', {}],
	])('shows %s', async (label, data) => {
		answer = () => Promise.resolve({ data })
		expect(await load()).toMatchSnapshot()
		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/register-descriptors')
	})

	it.each([
		['the server error', { response: { data: { error: 'Denied' } }, message: 'M' }],
		['the request message', { message: 'Network down' }],
	])('words a failure from %s', async (label, failure) => {
		answer = () => Promise.reject(failure)
		expect(await load()).toMatchSnapshot()
	})

	it('fails on an answer without data, keeping the rows', async () => {
		answer = () => Promise.resolve({})
		const panel = await load()
		expect(panel.error).toMatch(/^Could not read the descriptor inventory: \{reason\} \{"reason":".+"\}$/)
		expect(panel).toMatchObject({ loading: false, rows: ['stale'], total: 9, absent: 9, behind: 9 })
	})
})
