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
