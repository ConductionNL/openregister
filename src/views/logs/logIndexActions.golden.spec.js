/**
 * The audit trail table columns and export, and the search trail details
 * dialog: what they show, request and save, step by step.
 *
 * @spec exclude Golden record of client-side log page actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { showError, showSuccess } from '@nextcloud/dialogs'
import AuditTrailIndex from './AuditTrailIndex.vue'
import SearchTrailIndex from './SearchTrailIndex.vue'
import { auditTrailStore } from '../../store/store.js'

jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	translatePlural: (app, s) => s,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	auditTrailStore: { auditTrailFilters: null, auditTrailSort: {} },
	navigationStore: {},
	searchTrailStore: {},
}))
jest.mock('../../eventBus.js', () => ({ $on: () => {}, $off: () => {} }))
jest.mock('../../components/PaginationComponent.vue', () => ({}))
jest.mock('../../components/shared/ActionMenu.vue', () => ({}))
jest.mock('vue-material-design-icons/Broom.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/CompareHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/Download.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))

let log

beforeEach(() => {
	jest.clearAllMocks()
	log = []
	showSuccess.mockImplementation((text) => log.push(['success', text]))
	showError.mockImplementation((text) => log.push(['error', text]))
	jest
		.spyOn(console, 'error')
		.mockImplementation((...args) => log.push(['console', ...args]))
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('AuditTrailIndex.tableColumns', () => {
	it('lists the audit trail columns', () => {
		expect(AuditTrailIndex.computed.tableColumns.call({})).toMatchSnapshot()
	})
})

describe('AuditTrailIndex.exportFilteredAuditTrails', () => {
	const RealBlob = global.Blob

	beforeEach(() => {
		global.Blob = jest.fn((parts, options) => {
			log.push(['blob', parts, options])
			return { blob: 1 }
		})
		window.URL.createObjectURL = jest.fn(() => {
			log.push(['createObjectURL'])
			return 'blob:x'
		})
		window.URL.revokeObjectURL = jest.fn((url) => log.push(['revoke', url]))
		jest.spyOn(document.body, 'appendChild').mockImplementation((node) => {
			log.push(['append', node.tagName, node.href, node.download])
			return node
		})
		jest.spyOn(document.body, 'removeChild').mockImplementation((node) => {
			log.push(['remove', node.tagName])
			return node
		})
		jest
			.spyOn(HTMLAnchorElement.prototype, 'click')
			.mockImplementation(() => log.push(['click']))
	})

	afterEach(() => {
		global.Blob = RealBlob
	})

	/**
	 * A fetch double that logs its URL and answers once.
	 *
	 * @param {Promise} answer What response.json() gives, or a rejection
	 * @return {Function} The double
	 */
	function fetchAnswering(answer) {
		return jest.fn((url) => {
			log.push(['fetch', url])
			return Promise.resolve({ json: () => answer })
		})
	}

	const SAVED = Promise.resolve({
		success: true,
		data: { content: 'a,b', contentType: 'text/csv', filename: 'audit.csv' },
	})

	const CASES = {
		'defaults without filters': [{}, null, SAVED],
		'given options with filters': [
			{ format: 'json', includeChanges: true, includeMetadata: true },
			{ action: 'update', user: '', schema: null, register: undefined, size: 0, ok: false },
			SAVED,
		],
		'a refusal with a reason': [{}, {}, Promise.resolve({ success: false, error: 'Too big' })],
		'a refusal without a reason': [{}, {}, Promise.resolve({ success: true })],
		'a broken answer': [{}, {}, Promise.reject(new Error('bad json'))],
	}

	for (const [name, [options, filters, answer]] of Object.entries(CASES)) {
		it(`exports with ${name}`, async () => {
			auditTrailStore.auditTrailFilters = filters
			global.fetch = fetchAnswering(answer)
			await AuditTrailIndex.methods.exportFilteredAuditTrails(options)
			expect(log).toMatchSnapshot()
		})
	}

	it('exports CSV with changes from the export button', () => {
		const ctx = { exportFilteredAuditTrails: jest.fn() }
		AuditTrailIndex.methods.exportAuditTrails.call(ctx)
		expect(ctx.exportFilteredAuditTrails.mock.calls).toEqual([
			[{ format: 'csv', includeChanges: true }],
		])
	})
})

describe('SearchTrailIndex.viewDetails', () => {
	beforeEach(() => {
		global.OC = {
			dialogs: { info: jest.fn((...args) => log.push(['info', ...args])) },
		}
	})

	/**
	 * The details dialog for one search trail.
	 *
	 * @param {object} trail The search trail
	 * @return {Array} The logged dialog call
	 */
	function details(trail) {
		const ctx = {
			t: (app, text) => `[${text}]`,
			formatExecutionTime: (time) => `<${time}>`,
		}
		SearchTrailIndex.methods.viewDetails.call(ctx, trail)
		return log
	}

	const CREATED = '2026-01-02T03:04:05Z'
	const when = new Date(CREATED).toLocaleString()

	it('shows every field it has', () => {
		const shown = details({
			searchTerm: 'cats',
			created: CREATED,
			registerName: 'Pets',
			register: 1,
			schemaName: 'Cat',
			schema: 2,
			userName: 'Ann',
			user: 'ann',
			totalResults: 4,
			responseTime: 12,
			parameters: { limit: 5 },
		})
		expect(shown[0][1].replace(when, '<when>')).toMatchSnapshot()
		expect(shown[0].slice(2)).toEqual(['[Search Trail Details]', null, true])
	})

	it('falls back field by field', () => {
		const shown = details({
			created: CREATED,
			register: 1,
			schema: 2,
			user: 'ann',
			parameters: 'not an object',
		})
		expect(shown[0][1].replace(when, '<when>')).toMatchSnapshot()
	})

	it('says N/A where nothing is known', () => {
		const shown = details({ created: CREATED, parameters: null })
		expect(shown[0][1].replace(when, '<when>')).toMatchSnapshot()
	})
})
