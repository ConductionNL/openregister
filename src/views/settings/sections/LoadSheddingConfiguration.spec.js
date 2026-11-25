/**
 * LoadSheddingConfiguration: shows the thresholds in force with their defaults,
 * refuses a wrong field before sending, and stores the whole form as numbers.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import LoadSheddingConfiguration from './LoadSheddingConfiguration.vue'
import { useSettingsStore } from '../../../store/settings.js'

jest.mock('../../../store/settings.js', () => {
	const { reactive } = require('vue')
	const store = reactive({ saving: false, loadLoadSheddingSettings: jest.fn(), updateLoadSheddingSettings: jest.fn() })
	return { useSettingsStore: () => store }
})
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../../tests/unit/support/ncGolden.js').ncLibrary())

const FIELDS = {
	breakerMinCalls: 10,
	breakerMinFailures: 5,
	breakerFailurePercent: 50,
	breakerCoolDown: 30,
	breakerMaxCoolDown: 300,
	pressureMinRequests: 50,
	pressureSlowMs: 2000,
	pressureSlowPercent: 20,
	pressureErrorPercent: 10,
}

const BOUNDS = Object.fromEntries(Object.keys(FIELDS).map((name) => [
	name,
	{ min: 1, max: name.endsWith('Percent') ? 100 : 2147483647 },
]))

const FORM = {
	values: { pressureEnabled: true, ...FIELDS, breakerCoolDown: 45 },
	defaults: { pressureEnabled: true, ...FIELDS },
	bounds: BOUNDS,
}

/**
 * Mount the section over the given load answer.
 *
 * @param {...(object|undefined)} answer What the store's load answers; the form when left out
 * @return {Promise<object>} The wrapper
 */
async function mounted(...answer) {
	useSettingsStore().loadLoadSheddingSettings.mockResolvedValue(answer.length > 0 ? answer[0] : FORM)
	const wrapper = mount(LoadSheddingConfiguration)
	await flushPromises()
	return wrapper
}

/**
 * The text field stub labelled with the given words.
 *
 * @param {object} wrapper The section
 * @param {string} label The field label
 * @return {object} The field's wrapper
 */
function field(wrapper, label) {
	return wrapper.findAll('[data-stub="NcTextField"]').find((node) => node.attributes('label') === label)
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('LoadSheddingConfiguration', () => {
	it('shows the value in force and the default under every threshold', async () => {
		const wrapper = await mounted()

		const coolDown = field(wrapper, 'First wait before a trial call (seconds)')
		expect(coolDown.attributes('modelvalue')).toBe('45')
		expect(coolDown.attributes('helpertext')).toBe('Default: 30')
		expect(coolDown.attributes('max')).toBe('2147483647')
		expect(field(wrapper, 'Share of slow requests that means pressure (%)').attributes('max')).toBe('100')
		expect(wrapper.findAll('[data-stub="NcTextField"]')).toHaveLength(9)
		expect(wrapper.find('[data-stub="switch"]').attributes('data-checked')).toBe('true')
		expect(wrapper.findAll('legend').map((node) => node.text())).toEqual(['Dependency breakers', 'Database pressure'])
	})

	it('stores the whole form as numbers, with the switch as the reader left it', async () => {
		const store = useSettingsStore()
		store.updateLoadSheddingSettings.mockResolvedValue({ values: { ...FORM.values, pressureEnabled: false, breakerMinCalls: 12 } })
		const wrapper = await mounted()

		await wrapper.find('[data-stub="switch"] input').trigger('click')
		wrapper.vm.values.breakerMinCalls = '12'
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(store.updateLoadSheddingSettings).toHaveBeenCalledWith({
			pressureEnabled: false,
			...FIELDS,
			breakerCoolDown: 45,
			breakerMinCalls: 12,
		})
		expect(wrapper.vm.values.breakerMinCalls).toBe(12)
	})

	it('refuses a field outside its bounds without sending anything', async () => {
		const store = useSettingsStore()
		const wrapper = await mounted()

		wrapper.vm.values.breakerFailurePercent = '150'
		wrapper.vm.values.pressureSlowMs = '2.5'
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(store.updateLoadSheddingSettings).not.toHaveBeenCalled()
		const percent = field(wrapper, 'Share of failed calls that opens a breaker (%)')
		expect(percent.attributes('helpertext')).toBe('Must be a whole number from 1 to 100.')
		expect(percent.attributes('error')).toBe('true')
		expect(field(wrapper, 'A request counts as slow after (milliseconds)').attributes('error')).toBe('true')
		expect(field(wrapper, 'Calls a breaker needs before it can open').attributes('error')).toBe('false')
	})

	it('keeps what was typed when the store refuses', async () => {
		const store = useSettingsStore()
		store.updateLoadSheddingSettings.mockRejectedValue(new Error('400'))
		const wrapper = await mounted()

		wrapper.vm.values.breakerCoolDown = '90'
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(store.updateLoadSheddingSettings).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.values.breakerCoolDown).toBe('90')
	})

	it('says so when the settings cannot be read, and offers no form', async () => {
		const wrapper = await mounted(undefined)

		expect(wrapper.find('[role="alert"]').text()).toBe('The load shedding settings could not be read.')
		expect(wrapper.find('button').exists()).toBe(false)
	})
})
