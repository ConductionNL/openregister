/**
 * Golden record of which fields of an audit trail entry the details modal
 * lists as additional: everything but the main fields, and only when filled.
 *
 * @spec exclude Golden record of client-side audit trail display.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import AuditTrailDetails from './AuditTrailDetails.vue'
import { auditTrailStore } from '../../store/store.js'

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

describe('AuditTrailDetails.additionalFields (golden)', () => {
	it.each([
		['no entry', null],
		['only main fields', { id: 1, action: 'create', created: 'c', object: 2, register: 3, schema: 4, user: 'u', userName: 'U', size: 9, changed: {}, request: 'r' }],
		[
			'extra fields',
			{ id: 1, session: 's1', ipAddress: '10.0.0.1', empty: '', none: null, missing: undefined, zero: 0, flag: false, nested: { a: 1 } },
		],
	])('lists the additional fields of %s', (name, item) => {
		auditTrailStore.auditTrailItem = item
		expect(JSON.stringify(AuditTrailDetails.computed.additionalFields.call({}))).toMatchSnapshot()
	})
})
