/**
 * TermDiagnosticPanel: asks the term diagnostic when a term ends under the
 * calendar in the form and lists the days it walked.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md#requirement-the-diagnostic-is-reachable-from-the-calendar-admin-page-and-by-deep-link
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import TermDiagnosticPanel, { diagnosticRequest } from './TermDiagnosticPanel.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
}))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			const slots = Object.values(this.$slots).map((s) => s({}))
			return h('div', { ...this.$attrs, class: `stub-${name}` }, slots)
		},
	})
	return {
		__esModule: true,
		NcButton: stub('NcButton'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
	}
})

const CALENDAR = {
	slug: 'nl-national',
	timezone: 'Europe/Amsterdam',
	workingWeekdays: [1, 2, 3, 4, 5],
}

/** The diagnostic's answer for a term across Easter 2026. */
const EASTER = {
	firesAt: '2026-04-08T09:00:00+02:00',
	unrolledAt: null,
	rolledBy: null,
	walkTruncated: false,
	walk: [
		{ date: '2026-04-02', kind: 'working', counted: true },
		{ date: '2026-04-03', kind: 'Goede Vrijdag', counted: false },
		{ date: '2026-04-04', kind: 'weekend', counted: false },
		{ date: '2026-04-05', kind: 'weekend', counted: false },
		{ date: '2026-04-06', kind: 'Tweede Paasdag', counted: false },
		{ date: '2026-04-07', kind: 'working', counted: true },
	],
}

/**
 * The template's translator, with placeholders filled.
 *
 * @param {string} app The app id.
 * @param {string} text The English text.
 * @param {object} [vars] The placeholder values.
 * @return {string} The text.
 */
function tMock(app, text, vars = {}) {
	return text.replace(/{(\w+)}/g, (match, key) =>
		key in vars ? vars[key] : match,
	)
}

/**
 * Mount the panel over the calendar fixture.
 *
 * @param {object|null} [initialSla] The term to start from.
 * @return {object} The wrapper.
 */
function mountIt(initialSla = null) {
	return mount(TermDiagnosticPanel, {
		props: { definitionOf: () => CALENDAR, initialSla },
		global: { mocks: { t: tMock } },
	})
}

describe('TermDiagnosticPanel', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('asks the diagnostic with the calendar as it stands in the form and lists the walk with its rules', async () => {
		axios.post.mockResolvedValue({ data: EASTER })
		const wrapper = mountIt()
		wrapper.vm.anchorAt = '2026-04-02T09:00'
		wrapper.vm.value = '2'

		await wrapper.vm.explain()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/flow-timers/diagnostic',
			{
				calendar: CALENDAR,
				anchorAt: '2026-04-02T09:00',
				sla: { value: 2, unit: 'businessDays', rollToWorkingDay: 'none' },
			},
		)
		expect(wrapper.find('.term-diagnostic__fires').text()).toBe(
			'Ends at 2026-04-08T09:00:00+02:00',
		)
		expect(
			wrapper
				.findAll('[data-testid="term-diagnostic-walk"] li')
				.map((li) => li.text()),
		).toEqual([
			'2026-04-02Working day',
			'2026-04-03Goede Vrijdag',
			'2026-04-04Weekend',
			'2026-04-05Weekend',
			'2026-04-06Tweede Paasdag',
			'2026-04-07Working day',
		])
		expect(wrapper.findAll('.term-diagnostic__skipped')).toHaveLength(4)
	})

	it('starts from a linked term and sends its unit and roll', async () => {
		axios.post.mockResolvedValue({
			data: {
				...EASTER,
				unrolledAt: '2026-05-16T00:00:00+02:00',
				rolledBy: 'weekend',
			},
		})
		const wrapper = mountIt({
			value: 42,
			unit: 'calendarDays',
			rollToWorkingDay: 'next',
		})

		expect(wrapper.vm.value).toBe('42')
		expect(wrapper.vm.unit).toBe('calendarDays')
		expect(wrapper.vm.roll).toBe('next')

		wrapper.vm.anchorAt = '2026-04-04T10:00'
		await wrapper.vm.explain()
		await flushPromises()

		expect(axios.post.mock.calls[0][1].sla).toEqual({
			value: 42,
			unit: 'calendarDays',
			rollToWorkingDay: 'next',
		})
		expect(wrapper.text()).toContain(
			'Moved from 2026-05-16T00:00:00+02:00 by weekend',
		)
	})

	it('asks nothing without a start moment or a length', async () => {
		const wrapper = mountIt()
		wrapper.vm.value = '3'

		await wrapper.vm.explain()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('A start moment and a length are needed.')
	})

	it('shows the refusal the diagnostic answers with, or a fallback', async () => {
		axios.post.mockRejectedValueOnce({
			response: {
				status: 422,
				data: {
					errors: {
						code: 'term-diagnostic-refused',
						message: 'The anchor "x" could not be read as a moment.',
					},
				},
			},
		})
		const wrapper = mountIt()
		wrapper.vm.anchorAt = 'x'
		wrapper.vm.value = '1'

		await wrapper.vm.explain()
		expect(wrapper.vm.error).toBe(
			'The anchor "x" could not be read as a moment.',
		)
		expect(wrapper.vm.working).toBe(false)

		axios.post.mockRejectedValueOnce(new Error('network'))
		await wrapper.vm.explain()
		expect(wrapper.vm.error).toBe('The term could not be worked out.')
	})

	it('says when the listed walk was cut short', async () => {
		axios.post.mockResolvedValue({ data: { ...EASTER, walkTruncated: true } })
		const wrapper = mountIt()
		wrapper.vm.anchorAt = '2026-04-02T09:00'
		wrapper.vm.value = '400'

		await wrapper.vm.explain()
		await flushPromises()

		expect(wrapper.text()).toContain(
			'The list shows the first days only; the end moment counts every day.',
		)
	})
})

describe('diagnosticRequest', () => {
	it('builds the request from a calendar and a typed term', () => {
		expect(
			diagnosticRequest(CALENDAR, {
				anchorAt: '2026-04-02T09:00',
				value: '1.5',
				unit: 'hours',
				roll: 'previous',
			}),
		).toEqual({
			calendar: CALENDAR,
			anchorAt: '2026-04-02T09:00',
			sla: { value: 1.5, unit: 'hours', rollToWorkingDay: 'previous' },
		})
	})

	it('refuses a missing moment, a missing, zero or negative length', () => {
		const term = {
			anchorAt: '2026-04-02T09:00',
			value: '2',
			unit: 'businessDays',
			roll: 'none',
		}

		expect(diagnosticRequest(CALENDAR, { ...term, anchorAt: ' ' })).toBeNull()
		expect(diagnosticRequest(CALENDAR, { ...term, value: '' })).toBeNull()
		expect(diagnosticRequest(CALENDAR, { ...term, value: '0' })).toBeNull()
		expect(diagnosticRequest(CALENDAR, { ...term, value: '-3' })).toBeNull()
	})
})
