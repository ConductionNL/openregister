import axios from '@nextcloud/axios'
import EditWorkingCalendarModal from './EditWorkingCalendarModal.vue'

/**
 * The working calendar form's own handlers: its computed options, the
 * opening-hour windows, rule and closure rows, the weekday toggle, the
 * preview and the save. Each runs against a plain `this` built from the
 * component's methods and computed getters.
 *
 * @spec exclude Client-side handlers of the working calendar form.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn(), put: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/Calendar.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/SaveButton.vue', () => ({ __esModule: true, default: {} }))
jest.mock('./CalendarRowButton.vue', () => ({ __esModule: true, default: {} }))

/**
 * A bare component state carrying the component's methods and computed getters.
 *
 * @param {object} state The data and props
 * @return {object} The context
 */
function contextOf(state = {}) {
	const ctx = { $emit: jest.fn(), ...state }
	for (const [name, method] of Object.entries(EditWorkingCalendarModal.methods)) {
		ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(EditWorkingCalendarModal.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx), configurable: true })
	}
	ctx.t = (app, text, vars) => (vars ? `t:${text}:${JSON.stringify(vars)}` : `t:${text}`)
	if (!ctx.form) {
		ctx.form = ctx.blankForm()
	}
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('EditWorkingCalendarModal computed', () => {
	it('names a new calendar and an existing one by its slug', () => {
		expect(contextOf({ calendar: null }).dialogName).toBe('t:New working calendar')
		expect(contextOf({ calendar: {} }).isNew).toBe(true)

		const existing = contextOf({ calendar: { '@self': { id: 'c1' } } })
		existing.form.slug = 'nl'
		expect(existing.isNew).toBe(false)
		expect(existing.dialogName).toBe('t:Working calendar {slug}:{"slug":"nl"}')
	})

	it('offers only the working weekdays for opening hours, in week order', () => {
		const ctx = contextOf()
		ctx.form.workingWeekdays = [6, 2, 1]

		expect(ctx.openWeekdays.map((day) => day.iso)).toEqual([1, 2, 6])
		expect(ctx.openWeekdays[2].label).toBe('t:Saturday')
	})

	it('starts the shift options with "never" and lists two rule kinds', () => {
		const ctx = contextOf()

		expect(ctx.shiftWeekdayOptions).toHaveLength(8)
		expect(ctx.shiftWeekdayOptions[0]).toEqual({ value: '', label: 't:It never moves' })
		expect(ctx.kindOptions.map((option) => option.value)).toEqual(['fixed', 'easter'])
		expect(ctx.weekdayOptions).toHaveLength(7)
	})
})

describe('EditWorkingCalendarModal opening hours', () => {
	it('adds a default window, then removes the chosen one', () => {
		const ctx = contextOf()

		expect(ctx.windowsFor(1)).toEqual([])
		ctx.addWindow(1)
		ctx.addWindow(1)
		ctx.form.serviceHours[1][1].start = '13:00'
		expect(ctx.windowsFor(1)).toEqual([{ start: '09:00', end: '17:00' }, { start: '13:00', end: '17:00' }])

		ctx.removeWindow(1, 0)
		expect(ctx.windowsFor(1)).toEqual([{ start: '13:00', end: '17:00' }])
		expect(ctx.windowsFor(2)).toEqual([])
	})

	it('reads stored windows keyed by name or ISO number and skips empty days', () => {
		const ctx = contextOf()

		expect(ctx.serviceHoursToForm({
			monday: [{ start: '09:00', end: '12:00' }, {}],
			2: [{ start: '10:00', end: '11:00' }],
			3: [],
			friday: 'closed',
		})).toEqual({
			1: [{ start: '09:00', end: '12:00' }, { start: '', end: '' }],
			2: [{ start: '10:00', end: '11:00' }],
		})
		expect(ctx.serviceHoursToForm(undefined)).toEqual({})
	})

	it('writes only complete windows, by weekday name, and nothing when none is complete', () => {
		const ctx = contextOf()
		ctx.form.serviceHours = { 1: [{ start: '09:00', end: '' }] }
		expect(ctx.serviceHoursToDefinition()).toBeNull()

		ctx.form.serviceHours = { 1: [{ start: '09:00', end: '12:00' }, { start: '', end: '17:00' }], 7: [{ start: '10:00', end: '14:00' }] }
		expect(ctx.serviceHoursToDefinition()).toEqual({
			monday: [{ start: '09:00', end: '12:00' }],
			sunday: [{ start: '10:00', end: '14:00' }],
		})
	})

	it('leaves organisation and service hours off a definition that does not set them', () => {
		const ctx = contextOf()
		ctx.form.slug = ' nl '
		ctx.form.title = ' NL '
		ctx.form.organisation = '   '
		ctx.form.workingWeekdays = [5, 1]

		const definition = ctx.toDefinition()

		expect(definition).toEqual({
			slug: 'nl',
			title: 'NL',
			workingWeekdays: [1, 5],
			hoursPerWorkingDay: 8,
			rules: [],
			exceptions: [],
		})
	})
})

describe('EditWorkingCalendarModal rows', () => {
	it('toggles a weekday off and back on', () => {
		const ctx = contextOf()

		ctx.toggleWeekday(3)
		expect(ctx.form.workingWeekdays).toEqual([1, 2, 4, 5])
		ctx.toggleWeekday(3)
		expect(ctx.form.workingWeekdays).toEqual([1, 2, 4, 5, 3])
	})

	it('maps a kind or weekday to its option, falling back to the first', () => {
		const ctx = contextOf()

		expect(ctx.kindOption('easter').value).toBe('easter')
		expect(ctx.kindOption('unknown').value).toBe('fixed')
		expect(ctx.weekdayOption('sunday').value).toBe('sunday')
		expect(ctx.weekdayOption('someday').value).toBe('')
	})

	it('adds a blank rule, edits its kind and shift, and removes it', () => {
		const ctx = contextOf()

		ctx.addRule()
		expect(ctx.form.rules).toEqual([{ kind: 'fixed', name: '', month: '1', day: '1', offset: '0', shiftWeekday: '', shiftDays: '-1' }])

		ctx.setRuleKind(0, { value: 'easter' })
		ctx.setShiftWeekday(0, { value: 'saturday' })
		expect(ctx.form.rules[0].kind).toBe('easter')
		expect(ctx.form.rules[0].shiftWeekday).toBe('saturday')

		ctx.setRuleKind(0, null)
		ctx.setShiftWeekday(0, null)
		expect(ctx.form.rules[0].kind).toBe('fixed')
		expect(ctx.form.rules[0].shiftWeekday).toBe('')

		ctx.removeRule(0)
		expect(ctx.form.rules).toEqual([])
	})

	it('adds and removes closure days, keeping the others', () => {
		const ctx = contextOf()

		ctx.addException()
		ctx.addException()
		ctx.form.exceptions[1].date = '2026-12-24'
		ctx.removeException(0)

		expect(ctx.form.exceptions).toEqual([{ date: '2026-12-24', name: '' }])
	})
})

describe('EditWorkingCalendarModal preview', () => {
	it('posts the unsaved definition and the year, and shows the dates', async () => {
		axios.post.mockResolvedValue({ data: { dates: [{ date: '2026-04-27', name: 'Kingsday' }] } })
		const ctx = contextOf({ previewYear: '2026', previewError: 'old', previewDates: ['old'] })
		ctx.form.slug = 'nl'

		await ctx.preview()

		expect(axios.post).toHaveBeenCalledWith(
			'/gen/apps/openregister/api/flow-timers/calendars/preview',
			{ calendar: ctx.toDefinition(), year: 2026 },
		)
		expect(ctx.previewDates).toEqual([{ date: '2026-04-27', name: 'Kingsday' }])
		expect(ctx.previewError).toBe('')
		expect(ctx.previewing).toBe(false)
	})

	it('shows no dates when the answer carries none', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const ctx = contextOf({ previewYear: '2027' })

		await ctx.preview()

		expect(ctx.previewDates).toEqual([])
	})

	it('shows the server error, or a generic one when the server gives none', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Month 13 does not exist' } } })
		const ctx = contextOf({ previewYear: '2026' })

		await ctx.preview()
		expect(ctx.previewError).toBe('Month 13 does not exist')
		expect(ctx.previewing).toBe(false)

		axios.post.mockRejectedValueOnce(new Error('network'))
		await ctx.preview()
		expect(ctx.previewError).toBe('t:The preview could not be computed.')
	})
})

describe('EditWorkingCalendarModal save', () => {
	const BASE = '/gen/apps/openregister/api/objects/flow-timers/working-calendar'

	it('creates a new calendar with a POST and announces it', async () => {
		axios.post.mockResolvedValue({})
		const ctx = contextOf({ calendar: null })
		ctx.form.slug = 'nl'

		await ctx.save()

		expect(axios.post).toHaveBeenCalledWith(BASE, ctx.toDefinition())
		expect(axios.put).not.toHaveBeenCalled()
		expect(ctx.$emit).toHaveBeenCalledWith('saved')
		expect(ctx.saving).toBe(false)
		expect(ctx.error).toBe('')
	})

	it('updates an existing calendar by id, or by uuid when it has no id', async () => {
		axios.put.mockResolvedValue({})

		await contextOf({ calendar: { '@self': { id: 'c1', uuid: 'u1' } } }).save()
		await contextOf({ calendar: { '@self': { uuid: 'u2' } } }).save()

		expect(axios.put.mock.calls.map((call) => call[0])).toEqual([`${BASE}/c1`, `${BASE}/u2`])
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('keeps the validator message, or a generic one, and does not announce a save', async () => {
		axios.put.mockRejectedValueOnce({ response: { data: { error: 'Rule 2 has no day' } } })
		const ctx = contextOf({ calendar: { '@self': { id: 'c1' } } })

		await ctx.save()
		expect(ctx.error).toBe('Rule 2 has no day')
		expect(ctx.saving).toBe(false)

		axios.put.mockRejectedValueOnce(new Error('network'))
		await ctx.save()
		expect(ctx.error).toBe('t:The calendar could not be saved.')
		expect(ctx.$emit).not.toHaveBeenCalled()
	})
})

describe('EditWorkingCalendarModal calendar watcher', () => {
	it('reloads the form and clears errors and preview when handed another calendar', () => {
		const ctx = contextOf({ error: 'x', previewError: 'y', previewDates: [1] })

		EditWorkingCalendarModal.watch.calendar.handler.call(ctx, { slug: 'be', title: 'Belgium' })

		expect(ctx.form.slug).toBe('be')
		expect(ctx.form.title).toBe('Belgium')
		expect(ctx.error).toBe('')
		expect(ctx.previewError).toBe('')
		expect(ctx.previewDates).toEqual([])
	})
})
