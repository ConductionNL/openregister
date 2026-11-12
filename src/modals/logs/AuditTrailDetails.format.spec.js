/**
 * How the audit trail details modal formats request data, field names and
 * field values, and copies the full entry to the clipboard.
 *
 * @spec exclude Client-side audit trail display formatting.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import AuditTrailDetails from './AuditTrailDetails.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/CompareHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({}))
jest.mock('./AuditActionBadge.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	auditTrailStore: { auditTrailItem: null },
	navigationStore: {},
}))

const { auditTrailStore } = jest.requireMock('../../store/store.js')
const { formatJson, formatFieldName, formatFieldValue, copyFullData } = AuditTrailDetails.methods

describe('AuditTrailDetails formatting', () => {
	it.each([
		[null, ''],
		['', ''],
		[0, ''],
		['{"a":1}', '{\n  "a": 1\n}'],
		['not json', 'not json'],
		[{ b: [1] }, '{\n  "b": [\n    1\n  ]\n}'],
		[42, '42'],
	])('formats request data %j', (data, text) => {
		expect(formatJson(data)).toBe(text)
	})

	it('falls back to the string form of data that cannot be serialised', () => {
		const cyclic = { toString: () => 'cyclic' }
		cyclic.self = cyclic
		expect(formatJson(cyclic)).toBe('cyclic')
	})

	it.each([
		['ipAddress', 'Ip Address'],
		['session', 'Session'],
		['organisationId', 'Organisation Id'],
		['ID', 'I D'],
	])('formats the field name %s', (name, label) => {
		expect(formatFieldName(name)).toBe(label)
	})

	it.each([
		[null, '-'],
		[undefined, '-'],
		[0, '0'],
		[false, 'false'],
		['text', 'text'],
		[{ a: 1 }, '{\n  "a": 1\n}'],
	])('formats the field value %j', (value, text) => {
		expect(formatFieldValue(value)).toBe(text)
	})
})

describe('AuditTrailDetails.copyFullData', () => {
	const vm = { t: (app, s) => s }

	beforeEach(() => {
		auditTrailStore.auditTrailItem = { id: 4, action: 'update' }
		global.OC = { Notification: { showSuccess: jest.fn(), showError: jest.fn() } }
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText: jest.fn() },
			configurable: true,
		})
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		console.error.mockRestore()
		delete global.OC
	})

	it('copies the entry as indented JSON and reports success', async () => {
		navigator.clipboard.writeText.mockResolvedValue()
		await copyFullData.call(vm)
		expect(navigator.clipboard.writeText).toHaveBeenCalledWith('{\n  "id": 4,\n  "action": "update"\n}')
		expect(OC.Notification.showSuccess).toHaveBeenCalledWith('Full data copied to clipboard')
		expect(OC.Notification.showError).not.toHaveBeenCalled()
	})

	it('reports the failure when the clipboard refuses', async () => {
		navigator.clipboard.writeText.mockRejectedValue(new Error('denied'))
		await copyFullData.call(vm)
		expect(OC.Notification.showError).toHaveBeenCalledWith('Failed to copy data')
		expect(OC.Notification.showSuccess).not.toHaveBeenCalled()
	})
})
