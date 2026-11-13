import axios from '@nextcloud/axios'
import LogIntegrity from './LogIntegrity.vue'

/**
 * LogIntegrity seal coverage: the status read on creation and its failure
 * message, the bar colour by how much of the trail is unsealed, the
 * spelled-out tooltip, and the digit grouping.
 *
 * @spec exclude Client-side seal coverage display of the audit trail.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/AlertOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ShieldAlert.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ShieldCheck.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ShieldSearch.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({ __esModule: true, default: {} }))

/**
 * A section context with its data, methods and a recording `t`.
 *
 * @param {object} state State to start from
 * @return {object} The context
 */
function section(state = {}) {
	const ctx = {
		...LogIntegrity.data.call({}),
		...state,
		t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m)),
	}
	for (const [name, method] of Object.entries(LogIntegrity.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const barClass = (status) => LogIntegrity.computed.barClass.call(section({ status }))

describe('LogIntegrity seal coverage', () => {
	let logged

	beforeEach(() => {
		axios.get.mockReset()
		logged = jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => logged.mockRestore())

	it('reads the coverage from the integrity endpoint on creation', async () => {
		const status = { total: 10, sealed: 10, unsealed: 0, coverage: 100 }
		axios.get.mockResolvedValue({ data: status })
		const ctx = section()
		await LogIntegrity.created.call(ctx)
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/audit-trails/integrity')
		expect(ctx.status).toBe(status)
		expect(ctx.error).toBe('')
	})

	it('keeps the status empty, logs and says so when the read fails', async () => {
		const failure = new Error('down')
		axios.get.mockRejectedValue(failure)
		const ctx = section()
		await ctx.loadStatus()
		expect(ctx.status).toBeNull()
		expect(ctx.error).toBe('Could not read the seal coverage of the audit trail.')
		expect(logged).toHaveBeenCalledWith('Failed to load audit integrity status:', failure)
	})

	it.each([
		['no status yet', null, 'integrity__bar-fill--success'],
		['nothing unsealed', { unsealed: 0, coverage: 0 }, 'integrity__bar-fill--success'],
		['94.9% coverage', { unsealed: 51, coverage: 94.9 }, 'integrity__bar-fill--error'],
		['exactly 95% coverage', { unsealed: 5, coverage: 95 }, 'integrity__bar-fill--warning'],
		['99% coverage', { unsealed: 1, coverage: 99 }, 'integrity__bar-fill--warning'],
	])('colours the bar for %s', (label, status, expected) => {
		expect(barClass(status)).toBe(expected)
	})

	it('spells out the sealed and total counts in the tooltip', () => {
		const ctx = section({ status: { sealed: 1234567, total: 2000000 } })
		expect(LogIntegrity.computed.coverageTitle.call(ctx)).toBe(
			`${(1234567).toLocaleString()} of ${(2000000).toLocaleString()} entries sealed`,
		)
	})

	it('has no tooltip before the status is read', () => {
		expect(LogIntegrity.computed.coverageTitle.call(section())).toBe('')
	})

	it.each([
		[49123, (49123).toLocaleString()],
		[0, '0'],
		[null, '0'],
		[undefined, '0'],
		['12', '12'],
	])('formats %p as %p', (value, expected) => {
		expect(section().formatNumber(value)).toBe(expected)
	})
})
