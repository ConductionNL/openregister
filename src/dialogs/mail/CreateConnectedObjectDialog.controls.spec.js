/**
 * Which form control the connected-object dialog shows for each kind of
 * schema property.
 *
 * @spec exclude Unit test of client-side form control choice.
 */
import CreateConnectedObjectDialog from './CreateConnectedObjectDialog.vue'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))

const { controlFor } = CreateConnectedObjectDialog.methods

describe('CreateConnectedObjectDialog.controlFor', () => {
	it.each([
		['status', { enum: ['a', 'b'], type: 'boolean' }, 'select'],
		['status', { enum: [], type: 'boolean' }, 'checkbox'],
		['count', { type: 'number', format: 'date' }, 'number'],
		['count', { type: 'integer' }, 'number'],
		['due', { type: 'string', format: 'date' }, 'date'],
		['notes', { type: 'string', format: 'date-time' }, 'textarea'],
		['Description', { type: 'string' }, 'textarea'],
		['emailBody', {}, 'textarea'],
		['summaryText', { enum: 'not a list' }, 'textarea'],
		['title', { type: 'string' }, 'text'],
		['contentType', { format: 'date' }, 'date'],
	])('shows %s (%j) as %s', (key, def, control) => {
		expect(controlFor(key, def)).toBe(control)
	})
})
