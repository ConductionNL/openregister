import { mount } from '@vue/test-utils'
/**
 * Golden record of what the audit trail changes and details modals share:
 * reading the changed data, formatting it, copying it and closing.
 *
 * @spec exclude Golden record of client-side audit trail modal helpers.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import AuditTrailChanges from './AuditTrailChanges.vue'
import AuditTrailDetails from './AuditTrailDetails.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/Close.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/CompareHorizontal.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('../../store/store.js', () => ({
	auditTrailStore: { auditTrailItem: null },
	navigationStore: { dialog: null, setDialog: jest.fn() },
}))

const { auditTrailStore, navigationStore } = jest.requireMock('../../store/store.js')

const circular = {}
circular.self = circular

const CHANGED = [
	null,
	undefined,
	'',
	'text',
	0,
	7,
	[],
	['a'],
	{},
	{ title: { old: 'a', new: 'b' } },
]

const FORMATTED = [
	null,
	'',
	'plain',
	'{"a":1}',
	'[1,2]',
	{ a: { b: 2 } },
	[1],
	circular,
	12,
]

/**
 * Mounts a modal with its dialog closed, so only its logic runs.
 *
 * @param {object} component The modal
 * @return {object} The instance
 */
function modal(component) {
	navigationStore.dialog = null
	return mount(component, {
		global: { config: { globalProperties: { t: (app, text) => `t:${text}` } } },
	}).vm
}

describe.each([
	['changes', AuditTrailChanges],
	['details', AuditTrailDetails],
])('audit trail %s modal golden', (name, component) => {
	const logged = []

	beforeEach(() => {
		logged.length = 0
		window.OC = {
			Notification: {
				showSuccess: (text) => logged.push(['success', text]),
				showError: (text) => logged.push(['error', text]),
			},
		}
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => jest.restoreAllMocks())

	it('reads the changed data', () => {
		const answers = CHANGED.map((changed) => {
			auditTrailStore.auditTrailItem = { changed }
			return [changed, modal(component).hasChanges]
		})
		auditTrailStore.auditTrailItem = null
		answers.push(['no item', modal(component).hasChanges])
		expect(answers).toMatchSnapshot()
	})

	it('formats dates and changes', () => {
		const vm = modal(component)
		expect(
			[null, '', 'nonsense'].map((date) => vm.formatDate(date)),
		).toMatchSnapshot()
		expect(vm.formatDate('2026-01-02T03:04:05Z')).toBe(
			new Date('2026-01-02T03:04:05Z').toLocaleString(),
		)
		expect(FORMATTED.map((value) => vm.formatChanges(value))).toMatchSnapshot()
	})

	it('copies the changes, and reports a failure', async () => {
		const written = []
		Object.defineProperty(navigator, 'clipboard', {
			configurable: true,
			value: { writeText: (text) => written.push(text) && Promise.resolve() },
		})
		auditTrailStore.auditTrailItem = { changed: { a: 1 } }
		await modal(component).copyChanges()
		auditTrailStore.auditTrailItem = null
		await modal(component).copyChanges()
		expect({ written, logged }).toMatchSnapshot()
	})

	it('closes the dialog', () => {
		navigationStore.setDialog.mockClear()
		modal(component).closeDialog()
		expect(navigationStore.setDialog.mock.calls).toEqual([[false]])
	})
})
