import axios from '@nextcloud/axios'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Connections page's daily report dialog: the recipients choice, the hour,
 * the quiet-day option, and the requests behind save, switch off and test.
 *
 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
 */
import { flushPromises, mount } from '@vue/test-utils'
import ConnectionReportDialog, {
	buildRecipients,
	hourOptions,
	parseAddresses,
} from './ConnectionReportDialog.vue'
import pinia from '../../pinia.js'
import { useNavigationStore } from '../../store/modules/navigation.js'

jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(),
	post: jest.fn(),
	put: jest.fn(),
	delete: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open', 'size'],
		emits: ['update:open'],
		template: '<div class="dialog"><slot /><slot name="actions" /></div>',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div :class="\'note-\' + type"><slot /></div>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="loading" />' },
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		template:
			'<label :data-checked="String(modelValue)" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></label>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'clearable', 'label'],
		emits: ['update:modelValue'],
		template: '<select />',
	},
}))

const API = '/index.php/apps/openregister/api/scheduled-reports'

const stored = {
	id: 12,
	name: 'Daily connection report',
	kind: 'connection-health',
	scheduleType: 'daily',
	scheduleHour: 6,
	recipients: ['@admins', 'ops@example.org'],
	options: { onlyWhenAttention: true, lookbackHours: 24 },
	lastRunAt: null,
	lastError: 'Nothing needed attention; no mail sent',
}

/**
 * Mount the dialog after the list request answers with the given reports.
 *
 * @param {object[]} results The reports the list returns.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountWith(results) {
	axios.get.mockResolvedValue({ data: { results, total: results.length } })
	const wrapper = mount(ConnectionReportDialog)
	await flushPromises()
	return wrapper
}

const byTestId = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)

describe('ConnectionReportDialog helpers', () => {
	it('offers every hour of the day', () => {
		const options = hourOptions()
		expect(options).toHaveLength(24)
		expect(options[0]).toEqual({ id: 0, label: '00:00' })
		expect(options[7]).toEqual({ id: 7, label: '07:00' })
		expect(options[23]).toEqual({ id: 23, label: '23:00' })
	})

	it('splits typed addresses and drops empties, repeats and the token', () => {
		expect(parseAddresses(' a@x.nl, b@x.nl;a@x.nl\nc@x.nl ,, @admins ')).toEqual(
			['a@x.nl', 'b@x.nl', 'c@x.nl'],
		)
		expect(parseAddresses(undefined)).toEqual([])
	})

	it('puts the all-administrators token first when it is chosen', () => {
		expect(buildRecipients(true, 'a@x.nl')).toEqual(['@admins', 'a@x.nl'])
		expect(buildRecipients(false, 'a@x.nl')).toEqual(['a@x.nl'])
		expect(buildRecipients(true, '')).toEqual(['@admins'])
	})
})

describe('ConnectionReportDialog', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		useNavigationStore(pinia).setDialog('connectionReport')
	})

	it('reads the reports and shows the report as off when none is a connection report', async () => {
		const wrapper = await mountWith([{ id: 3, kind: 'export', scheduleHour: 2 }])
		expect(axios.get).toHaveBeenCalledWith(API)
		expect(byTestId(wrapper, 'connection-report-status').text()).toBe('Disabled')
		expect(byTestId(wrapper, 'connection-report-switch-off').exists()).toBe(
			false,
		)
		expect(byTestId(wrapper, 'connection-report-test').exists()).toBe(false)
		expect(
			byTestId(wrapper, 'connection-report-all-admins').attributes(
				'data-checked',
			),
		).toBe('true')
		expect(
			wrapper.findComponent({ name: 'NcSelect' }).props('modelValue'),
		).toEqual({ id: 7, label: '07:00' })
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('inputLabel')).toBe(
			'Send at',
		)
	})

	it('switches the report on for all administrators at 07:00 and closes', async () => {
		axios.post.mockResolvedValue({ data: { id: 20 } })
		const wrapper = await mountWith([])

		await byTestId(wrapper, 'connection-report-save').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(API, {
			name: 'Daily connection report',
			kind: 'connection-health',
			scheduleType: 'daily',
			scheduleHour: 7,
			deliveryMode: 'email',
			recipients: ['@admins'],
			options: { onlyWhenAttention: false },
		})
		expect(axios.put).not.toHaveBeenCalled()
		expect(useNavigationStore(pinia).dialog).toBe(false)
	})

	it('sends only to the typed addresses when all administrators is switched off, on quiet days too', async () => {
		axios.post.mockResolvedValue({ data: { id: 20 } })
		const wrapper = await mountWith([])

		await byTestId(wrapper, 'connection-report-all-admins').trigger('click')
		await byTestId(wrapper, 'connection-report-addresses').setValue(
			'a@x.nl, b@x.nl',
		)
		await byTestId(wrapper, 'connection-report-quiet-days').trigger('click')
		wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', { id: 5, label: '05:00' })
		await byTestId(wrapper, 'connection-report-save').trigger('click')
		await flushPromises()

		const body = axios.post.mock.calls[0][1]
		expect(body.recipients).toEqual(['a@x.nl', 'b@x.nl'])
		expect(body.options).toEqual({ onlyWhenAttention: true })
		expect(body.scheduleHour).toBe(5)
	})

	it('fills the form from the existing report and changes it in place', async () => {
		axios.put.mockResolvedValue({ data: stored })
		const wrapper = await mountWith([{ id: 3, kind: 'export' }, stored])

		expect(byTestId(wrapper, 'connection-report-status').text()).toBe('Enabled')
		expect(byTestId(wrapper, 'connection-report-last-run').text()).toBe('Never')
		expect(byTestId(wrapper, 'connection-report-last-message').text()).toBe(
			'Nothing needed attention; no mail sent',
		)
		expect(
			byTestId(wrapper, 'connection-report-all-admins').attributes(
				'data-checked',
			),
		).toBe('true')
		expect(
			byTestId(wrapper, 'connection-report-quiet-days').attributes(
				'data-checked',
			),
		).toBe('true')
		expect(byTestId(wrapper, 'connection-report-addresses').element.value).toBe(
			'ops@example.org',
		)

		await byTestId(wrapper, 'connection-report-quiet-days').trigger('click')
		await byTestId(wrapper, 'connection-report-save').trigger('click')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
		expect(axios.put).toHaveBeenCalledWith(API + '/12', {
			name: 'Daily connection report',
			kind: 'connection-health',
			scheduleType: 'daily',
			scheduleHour: 6,
			deliveryMode: 'email',
			recipients: ['@admins', 'ops@example.org'],
			options: { onlyWhenAttention: false },
		})
	})

	it('switches the report off by deleting its schedule', async () => {
		axios.delete.mockResolvedValue({ data: null })
		const wrapper = await mountWith([stored])

		await byTestId(wrapper, 'connection-report-switch-off').trigger('click')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(API + '/12')
		expect(byTestId(wrapper, 'connection-report-status').text()).toBe('Disabled')
		expect(byTestId(wrapper, 'connection-report-test').exists()).toBe(false)
	})

	it('sends a test through run-now and says it is on its way', async () => {
		axios.post.mockResolvedValue({ data: { queued: true } })
		const wrapper = await mountWith([stored])

		await byTestId(wrapper, 'connection-report-test').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(API + '/12/run-now')
		expect(wrapper.find('.note-success').text()).toBe(
			'A test report is on its way.',
		)
		expect(useNavigationStore(pinia).dialog).toBe('connectionReport')
	})

	it('shows the server reason when saving is refused and stays open', async () => {
		axios.post.mockRejectedValue({
			response: {
				status: 403,
				data: { error: 'Only administrators can do this.' },
			},
		})
		const wrapper = await mountWith([])

		await byTestId(wrapper, 'connection-report-save').trigger('click')
		await flushPromises()

		expect(wrapper.find('.note-error').text()).toBe(
			'Only administrators can do this.',
		)
		expect(useNavigationStore(pinia).dialog).toBe('connectionReport')
		expect(
			byTestId(wrapper, 'connection-report-save').attributes('disabled'),
		).toBeUndefined()
	})

	it('says the report could not be loaded when the list fails without a reason', async () => {
		axios.get.mockRejectedValue(new Error('network'))
		const wrapper = mount(ConnectionReportDialog)
		await flushPromises()

		expect(wrapper.find('.note-error').text()).toBe(
			'The daily report could not be loaded.',
		)
	})

	it('closes from its own close button and from Cancel', async () => {
		const wrapper = await mountWith([])
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', true)
		expect(useNavigationStore(pinia).dialog).toBe('connectionReport')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(useNavigationStore(pinia).dialog).toBe(false)

		useNavigationStore(pinia).setDialog('connectionReport')
		await byTestId(wrapper, 'connection-report-cancel').trigger('click')
		expect(useNavigationStore(pinia).dialog).toBe(false)
	})
})
