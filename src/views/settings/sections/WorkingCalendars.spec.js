import axios from '@nextcloud/axios'
import WorkingCalendars from './WorkingCalendars.vue'

/**
 * WorkingCalendars: the calendars read through the objects API, the weekday
 * label, opening the editor, re-reading after a save, and a delete that
 * keeps the server's refusal (naming the timers that still use the calendar)
 * instead of a generic message.
 *
 * @spec exclude Client-side working calendar administration panel.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), delete: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../../modals/settings/EditWorkingCalendarModal.vue', () => ({ __esModule: true, default: {} }))

const LIST = '/gen/apps/openregister/api/objects/flow-timers/working-calendar'

/**
 * A section context with its data, its methods and an identity `t`.
 *
 * @param {object} state State to start from
 * @return {object} The context
 */
function section(state = {}) {
	const ctx = { ...WorkingCalendars.data.call({}), ...state, t: (app, text) => text }
	for (const [name, method] of Object.entries(WorkingCalendars.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

describe('WorkingCalendars', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.delete.mockReset()
	})

	it('starts loading and reads up to a hundred calendars on mount', async () => {
		const calendars = [{ slug: 'nl' }, { slug: 'be' }]
		axios.get.mockResolvedValue({ data: { results: calendars } })
		const ctx = section()
		expect(ctx.loading).toBe(true)
		WorkingCalendars.mounted.call(ctx)
		expect(axios.get).toHaveBeenCalledWith(LIST, { params: { _limit: 100 } })
		await Promise.resolve()
		await Promise.resolve()
		expect(ctx.calendars).toBe(calendars)
		expect(ctx.loading).toBe(false)
		expect(ctx.error).toBe('')
	})

	it.each([[{}], [null], [{ results: null }]])('reads an empty list from %p', async (data) => {
		axios.get.mockResolvedValue({ data })
		const ctx = section({ calendars: [{ slug: 'old' }] })
		await ctx.load()
		expect(ctx.calendars).toEqual([])
	})

	it('shows the server error when the read fails, else the fallback, and clears it on the next read', async () => {
		const ctx = section()
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'Register flow-timers not found' } } })
		await ctx.load()
		expect(ctx.error).toBe('Register flow-timers not found')
		expect(ctx.loading).toBe(false)
		axios.get.mockRejectedValueOnce(new Error('network'))
		await ctx.load()
		expect(ctx.error).toBe('The calendars could not be read.')
		axios.get.mockResolvedValueOnce({ data: { results: [] } })
		await ctx.load()
		expect(ctx.error).toBe('')
	})

	it.each([
		[[1, 2, 3, 4, 5], 'Mon, Tue, Wed, Thu, Fri'],
		[[7, 1], 'Sun, Mon'],
		[[6], 'Sat'],
		[[0, 8, 3], 'Wed'],
		[[], ''],
		[undefined, ''],
	])('labels the working weekdays %p as %p', (days, expected) => {
		expect(section().weekLabel({ workingWeekdays: days })).toBe(expected)
	})

	it('opens the editor on a calendar or on a new one', () => {
		const ctx = section()
		const calendar = { slug: 'nl' }
		ctx.edit(calendar)
		expect(ctx.editing).toBe(true)
		expect(ctx.selected).toBe(calendar)
		ctx.edit(null)
		expect(ctx.selected).toBeNull()
		expect(ctx.editing).toBe(true)
	})

	it('closes the editor, reports the save and re-reads after a save', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ slug: 'stored' }] } })
		const ctx = section({ editing: true })
		await ctx.onSaved()
		expect(ctx.editing).toBe(false)
		expect(ctx.outcome).toEqual({ ok: true, message: 'Saved. Deadlines that are already running keep the dates they were given.' })
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(ctx.calendars).toEqual([{ slug: 'stored' }])
	})

	it('deletes by object id, reports it and re-reads', async () => {
		axios.delete.mockResolvedValue({})
		axios.get.mockResolvedValue({ data: { results: [] } })
		const ctx = section({ outcome: { ok: false, message: 'old' } })
		const pending = ctx.remove({ slug: 'nl', '@self': { id: 'id-1', uuid: 'uuid-1' } })
		expect(ctx.busy).toBe('nl')
		expect(ctx.outcome).toBeNull()
		await pending
		expect(axios.delete).toHaveBeenCalledWith(`${LIST}/id-1`)
		expect(ctx.outcome).toEqual({ ok: true, message: 'The calendar was deleted.' })
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(ctx.busy).toBe('')
	})

	it('falls back to the uuid when the calendar has no id', async () => {
		axios.delete.mockResolvedValue({})
		axios.get.mockResolvedValue({ data: { results: [] } })
		await section().remove({ slug: 'be', '@self': { uuid: 'uuid-2' } })
		expect(axios.delete).toHaveBeenCalledWith(`${LIST}/uuid-2`)
	})

	it('keeps the refusal naming the timers when a delete is refused, without re-reading', async () => {
		axios.delete.mockRejectedValue({ response: { data: { error: 'Still used by 3 timers: a, b, c' } } })
		const ctx = section()
		await ctx.remove({ slug: 'nl', '@self': { id: 'id-1' } })
		expect(ctx.outcome).toEqual({ ok: false, message: 'Still used by 3 timers: a, b, c' })
		expect(axios.get).not.toHaveBeenCalled()
		expect(ctx.busy).toBe('')
	})

	it('says the calendar could not be deleted when the refusal has no message', async () => {
		axios.delete.mockRejectedValue(new Error('network'))
		const ctx = section()
		await ctx.remove({ slug: 'nl', '@self': { id: 'id-1' } })
		expect(ctx.outcome).toEqual({ ok: false, message: 'The calendar could not be deleted.' })
		expect(ctx.busy).toBe('')
	})
})
