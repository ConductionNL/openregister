import { mount } from '@vue/test-utils'
/**
 * Golden record of the working calendar form: its opening hours, rules and
 * single closure days, each row with its remove button and each list with its
 * add button.
 *
 * @spec exclude Golden record of client-side working calendar form markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditWorkingCalendarModal from './EditWorkingCalendarModal.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn(), put: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: mockStub('NcButton'),
	NcCheckboxRadioSwitch: mockStub('NcCheckboxRadioSwitch'),
	NcDialog: mockStub('NcDialog'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
	NcNoteCard: mockStub('NcNoteCard'),
	NcSelect: mockStub('NcSelect'),
	NcTextField: mockStub('NcTextField'),
}))
jest.mock('vue-material-design-icons/Calendar.vue', () => ({ __esModule: true, default: mockStub('Calendar') }))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({ __esModule: true, default: mockStub('ContentSave') }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ __esModule: true, default: mockStub('Delete') }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ __esModule: true, default: mockStub('Plus') }))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({ __esModule: true, default: mockStub('CloseCancelButton') }))

const CALENDAR = {
	'@self': { id: 'cal-1' },
	slug: 'nl-municipal',
	name: 'Municipal',
	timezone: 'Europe/Amsterdam',
	serviceHours: { monday: [{ start: '09:00', end: '12:00' }, { start: '13:00', end: '17:00' }], tuesday: [{ start: '09:00', end: '17:00' }] },
	rules: [
		{ kind: 'fixed', name: 'Kingsday', month: 4, day: 27, observedShift: { weekday: 'sunday', days: -1 } },
		{ kind: 'easter', name: 'Easter Monday', offset: 1 },
	],
	exceptions: [{ date: '2026-06-01', name: 'Anniversary' }],
}

describe('EditWorkingCalendarModal form', () => {
	it.each([
		['an existing calendar', CALENDAR],
		['a new calendar', null],
	])('renders %s', (label, calendar) => {
		const wrapper = mount(EditWorkingCalendarModal, {
			props: { show: true, calendar },
			global: { mocks: { t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text) } },
		})
		expect(wrapper.html()).toMatchSnapshot()
	})
})

/**
 * A stand-in that renders its tag, attributes, listeners and slots.
 *
 * @param {string} name The component name
 * @return {object} The stand-in
 */
function mockStub(name) {
	const { h } = require('vue')
	return {
		name,
		inheritAttrs: false,
		setup(props, { attrs, slots }) {
			return () => {
				const plain = Object.fromEntries(Object.entries(attrs).filter(([key]) => !/^on[A-Z]/.test(key)))
				const listeners = Object.keys(attrs).filter((key) => /^on[A-Z]/.test(key))
				if (listeners.length) plain['data-on'] = listeners.join(' ')
				return h(`x-${name}`, plain, Object.entries(slots).map(([slot, fill]) => h(`slot-${slot}`, fill())))
			}
		},
	}
}
