import axios from '@nextcloud/axios'
import WorkingCalendars from '../../views/settings/sections/WorkingCalendars.vue'
import EditWorkingCalendarModal from './EditWorkingCalendarModal.vue'

/**
 * Golden record of the working-calendar admin: how a stored calendar becomes
 * the form, how the form becomes the definition the API takes (rules,
 * shifts, exceptions, optional fields), and the calendar list read.
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-every-write-of-a-working-calendar-is-validated-the-same-way
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn(), put: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/Calendar.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/SaveButton.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/SettingsSection.vue', () => ({ __esModule: true, default: {} }))
jest.mock('./CalendarRowButton.vue', () => ({ __esModule: true, default: {} }))

/**
 * A bare component state carrying the component's methods.
 *
 * @param {object} component The component
 * @param {object} state The data
 * @return {object} The context
 */
function contextOf(component, state) {
	const ctx = { ...state }
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.t = (app, text) => `t:${text}`
	return ctx
}

const STORED = {
	full: {
		slug: 'nl',
		title: 'Netherlands',
		organisation: 'org-1',
		workingWeekdays: [1, 2, 3, 4, 5, 6],
		hoursPerWorkingDay: 7.5,
		serviceHours: { start: '09:00', end: '17:00' },
		rules: [
			{ kind: 'easter', name: 'Easter Monday', offset: 1 },
			{ kind: 'fixed', name: 'Kingsday', month: 4, day: 27, observedShift: { whenWeekday: 7, days: -1 } },
			{ name: 'Unnamed kind', month: 12, day: 25 },
			{ kind: 'other' },
		],
		exceptions: [{ date: '2026-12-24', name: 'Office closed' }, {}],
	},
	bare: { slug: 'x' },
	none: null,
}

describe('EditWorkingCalendarModal.toForm', () => {
	it.each(Object.keys(STORED))('reads a %s calendar into the form', (key) => {
		const ctx = contextOf(EditWorkingCalendarModal, {})
		const form = ctx.toForm(STORED[key])
		expect({ form, keys: Object.keys(form) }).toMatchSnapshot()
	})
})

const FORMS = {
	full: {
		slug: '  nl ',
		title: ' Netherlands ',
		organisation: ' org-1 ',
		workingWeekdays: [5, 1, 3],
		hoursPerWorkingDay: '7.5',
		serviceHours: { start: '09:00', end: '17:00' },
		rules: [
			{ kind: 'easter', name: 'Easter Monday', offset: '1', month: '4', day: '1', shiftWeekday: 7, shiftDays: '1' },
			{ kind: 'fixed', name: 'Kingsday', month: '4', day: '27', offset: '', shiftWeekday: 7, shiftDays: '-1' },
			{ kind: 'fixed', name: 'Christmas', month: '12', day: '25', offset: '', shiftWeekday: '', shiftDays: '' },
		],
		exceptions: [{ date: '2026-12-24', name: '' }, { date: '', name: 'dropped' }, { date: '2026-12-31', name: 'NYE' }],
	},
	minimal: {
		slug: 'x',
		title: 'X',
		organisation: '   ',
		workingWeekdays: [],
		hoursPerWorkingDay: '',
		serviceHours: {},
		rules: [],
		exceptions: [],
	},
}

describe('EditWorkingCalendarModal.toDefinition', () => {
	it.each(Object.keys(FORMS))('builds the definition of a %s form', (key) => {
		const form = JSON.parse(JSON.stringify(FORMS[key]))
		const ctx = contextOf(EditWorkingCalendarModal, { form })
		const definition = ctx.toDefinition()
		expect({ definition, keys: Object.keys(definition), formUntouched: JSON.stringify(form) === JSON.stringify(FORMS[key]) }).toMatchSnapshot()
	})
})

describe('WorkingCalendars.load', () => {
	it.each([
		['a list', () => Promise.resolve({ data: { results: [{ slug: 'nl' }] } })],
		['an empty answer', () => Promise.resolve({})],
		['a refusal with an error', () => Promise.reject({ response: { data: { error: 'Denied' } } })],
		['a bare failure', () => Promise.reject(new Error('offline'))],
	])('reads %s', async (label, answer) => {
		const ctx = contextOf(WorkingCalendars, { loading: false, error: 'old', calendars: ['old'] })
		const seen = []
		axios.get.mockImplementation((...args) => {
			seen.push([args, ctx.loading, ctx.error])
			return answer()
		})
		await ctx.load()
		const data = Object.fromEntries(Object.entries(ctx).filter(([, value]) => typeof value !== 'function'))
		expect({ data, seen }).toMatchSnapshot()
	})
})
