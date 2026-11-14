/**
 * The seed directory section reads the last report and names its outcome, totals and failures.
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import SeedReportSection from './SeedReportSection.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/gen${url}` }))

/**
 * A section instance over plain state, its computed values bound.
 *
 * @param {object} state Its state
 * @return {object} The instance
 */
function section(state = {}) {
	const vm = { ...SeedReportSection.data(), ...state }
	for (const [name, method] of Object.entries(SeedReportSection.methods)) {
		vm[name] = method.bind(vm)
	}
	for (const [name, getter] of Object.entries(SeedReportSection.computed)) {
		Object.defineProperty(vm, name, { get: getter.bind(vm) })
	}
	return vm
}

const report = {
	status: 'partial',
	directory: '/seed',
	moment: 'not a date',
	problems: ['two files share a slug'],
	totals: { imported: 4, skipped: 1, failed: 2 },
	files: [
		{ file: '10-base.json', status: 'imported', failures: [] },
		{ file: '20-cases.json', status: 'failed', failures: [{ file: '20-cases.json', entity: 'schema', slug: 'case', reason: 'no slug' }] },
		{ file: '30-link.json', status: 'refused', failures: [{ file: '30-link.json', entity: 'file', slug: '', reason: 'a symlink out of the directory' }] },
	],
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('SeedReportSection', () => {
	it('reads the last report from the administrator route', async () => {
		axios.get.mockResolvedValue({ data: { report } })
		const vm = section()

		await vm.load()

		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/settings/seed')
		expect(vm.report).toEqual(report)
		expect(vm.loaded).toBe(true)
		expect(vm.error).toBe(false)
	})

	it('says no seed ran when there is no report', async () => {
		axios.get.mockResolvedValue({ data: { report: null } })
		const vm = section({ report })

		await vm.load()

		expect(vm.report).toBeNull()
		expect(vm.loaded).toBe(true)
	})

	it('shows an error when the report cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('403'))
		const vm = section({ report })

		await vm.load()

		expect(vm.report).toBeNull()
		expect(vm.error).toBe(true)
	})

	it('names the outcome, keeps an unknown one as given', () => {
		expect(section({ report: { status: 'success' } }).statusLabel).toBe('Succeeded')
		expect(section({ report: { status: 'partial' } }).statusLabel).toBe('Partly succeeded')
		expect(section({ report: { status: 'failed' } }).statusLabel).toBe('Failed')
		expect(section({ report: { status: 'odd' } }).statusLabel).toBe('odd')
	})

	it('lists the problems, then every named failure', () => {
		expect(section({ report }).lines).toEqual([
			'two files share a slug',
			'20-cases.json: schema case: no slug',
			'30-link.json: file: a symlink out of the directory',
		])
	})

	it('reads totals as numbers, zero where missing, and keeps a moment it cannot parse', () => {
		const vm = section({ report: { ...report, totals: { imported: '4' } } })

		expect(vm.totals).toEqual({ imported: 4, skipped: 0, failed: 0 })
		expect(vm.momentLabel).toBe('not a date')
	})
})
