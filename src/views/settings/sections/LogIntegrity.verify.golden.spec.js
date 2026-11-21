import axios from '@nextcloud/axios'
import LogIntegrity from './LogIntegrity.vue'

/**
 * Golden record of the audit-chain verification: the request, the state
 * during it, the result and status reload after an answer, and the logged
 * failure and message when it cannot complete.
 *
 * @spec exclude Golden record of the client-side audit chain verification.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/AlertOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ShieldAlert.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ShieldCheck.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ShieldSearch.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({ __esModule: true, default: {} }))

describe('LogIntegrity.verifyChain', () => {
	let logged

	beforeEach(() => {
		axios.get.mockReset()
		logged = jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => logged.mockRestore())

	it.each([
		['an intact chain', () => Promise.resolve({ data: { valid: true, checked: 10 } }), () => Promise.resolve()],
		['a broken chain', () => Promise.resolve({ data: { valid: false, brokenAt: 7 } }), () => Promise.resolve()],
		['a failed request', () => Promise.reject(new Error('offline')), () => Promise.resolve()],
		['a failed status reload', () => Promise.resolve({ data: { valid: true } }), () => Promise.reject(new Error('status down'))],
	])('verifies %s', async (label, answer, statusReload) => {
		const events = []
		const section = { verifying: false, error: 'old', verification: { stale: true } }
		for (const [name, method] of Object.entries(LogIntegrity.methods)) {
			section[name] = method.bind(section)
		}
		section.t = (app, text) => `t:${text}`
		section.loadStatus = () => {
			events.push(['loadStatus', section.verification])
			return statusReload()
		}
		axios.get.mockImplementation((...args) => {
			events.push(['get', ...args, section.verifying, section.error, section.verification])
			return answer()
		})
		await section.verifyChain()
		expect({
			events,
			verifying: section.verifying,
			error: section.error,
			verification: section.verification,
			logged: logged.mock.calls.map(([text, e]) => [text, e?.message]),
		}).toMatchSnapshot()
	})
})
