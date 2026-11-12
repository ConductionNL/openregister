import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
/**
 * Golden record of the retention table: its markup and how each input writes
 * to the retention options of the settings store.
 *
 * @spec exclude Golden record of client-side settings markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import RetentionConfiguration from './RetentionConfiguration.vue'

jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: { template: '<button />' },
	NcCheckboxRadioSwitch: { template: '<i />' },
	NcLoadingIcon: { template: '<i />' },
	NcSelect: { template: '<i />' },
}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({ __esModule: true, default: { template: '<i />' } }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: { template: '<i />' } }))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({
	__esModule: true,
	default: { template: '<section><slot /></section>' },
}))
jest.mock('../../../store/settings.js', () => {
	const useSettingsStore = () => globalThis.settingsStore
	useSettingsStore.$id = 'settings'
	return { useSettingsStore }
})

const OPTIONS = {
	objectArchiveRetention: 31536000000,
	objectDeleteRetention: 63072000000,
	searchTrailRetention: 2592000000,
	createLogRetention: 86400000,
	readLogRetention: 3600000,
	updateLogRetention: 604800000,
	deleteLogRetention: 60000,
	webhookLogRetention: 0,
}

/**
 * Mounts the section on a stand-in store.
 *
 * @param {object} state The store flags
 * @return {object} The wrapper
 */
function section(state = {}) {
	globalThis.settingsStore = reactive({
		retentionOptions: { ...OPTIONS },
		loading: false,
		saving: false,
		rebasing: false,
		...state,
	})
	return mount(
		{ ...RetentionConfiguration, setup: () => ({ t: (app, text) => `t:${text}` }) },
		{ global: { config: { warnHandler: () => {} } } },
	)
}

/**
 * The markup of the retention table, proven not empty.
 *
 * @param {object} wrapper The mounted section
 * @return {string} The markup
 */
function table(wrapper) {
	const html = wrapper
		.find('.retention-table')
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/> +/g, '>')
		.replace(/ +</g, '<')
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '')
		.join('\n')
	expect(html).toContain('retention-input-field')
	return html
}

describe('retention table', () => {
	it('shows every row', () => {
		expect(table(section())).toMatchSnapshot()
	})

	it('disables its inputs while saving', () => {
		const inputs = section({ saving: true }).findAll('input')
		expect(inputs).toHaveLength(8)
		expect(inputs.every((input) => input.element.disabled)).toBe(true)
	})

	it('disables its inputs while loading', () => {
		const inputs = section({ loading: true }).findAll('input')
		expect(inputs).toHaveLength(8)
		expect(inputs.every((input) => input.element.disabled)).toBe(true)
	})

	it.each(Object.keys(OPTIONS))('writes %s as a number', async (key) => {
		const wrapper = section()
		const index = Object.keys(OPTIONS).indexOf(key)
		const expected = { ...OPTIONS, [key]: 7200000 }
		await wrapper.findAll('input')[index].setValue('7200000')
		expect(globalThis.settingsStore.retentionOptions).toEqual(expected)
		expect(wrapper.findAll('.retention-display')[index].text()).toMatchSnapshot()
	})
})
