/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Files sidebar register objects tab: the objects that reference a file.
 *
 * @spec exclude Unit test of the client-side Files sidebar objects tab.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import RegisterObjectsTab from './RegisterObjectsTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name'],
		template: '<div class="empty">{{ name }}</div>',
	},
}))
jest.mock('vue-material-design-icons/DatabaseOffOutline.vue', () => ({
	name: 'DatabaseOffOutline',
	render: () => null,
}))
jest.mock('../shared/TabErrorState.vue', () => ({
	name: 'TabErrorState',
	props: ['name', 'description'],
	render: () => null,
}))

const OBJ = {
	uuid: 'u-1',
	title: 'Contract',
	register: { id: 2, title: 'Legal' },
	schema: { id: 3, title: 'Contracts' },
}

/**
 * Mount the tab for file 9 and let it load.
 *
 * @return {Promise<object>} The wrapper
 */
async function mountTab() {
	const wrapper = mount(RegisterObjectsTab, { props: { fileId: 9 } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

describe('RegisterObjectsTab', () => {
	it('links each object that references the file', async () => {
		axios.get.mockResolvedValue({ data: { success: true, data: [OBJ] } })
		const wrapper = await mountTab()
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/files/9/objects',
		)
		const link = wrapper.find('.register-objects-tab__link')
		expect(link.attributes('href')).toBe(
			'/index.php/apps/openregister/registers/2/schemas/3/objects/u-1',
		)
		expect(link.attributes('aria-label')).toBe('Contract in Legal / Contracts')
		expect(wrapper.find('.register-objects-tab__title').text()).toBe('Contract')
	})

	it('says so when no object references the file', async () => {
		axios.get.mockResolvedValue({ data: { success: true, data: null } })
		const wrapper = await mountTab()
		expect(wrapper.find('.empty').text()).toBe(
			'No register objects reference this file',
		)
	})

	it('shows the error from an unsuccessful response', async () => {
		axios.get.mockResolvedValue({ data: { success: false } })
		const wrapper = await mountTab()
		expect(wrapper.findComponent({ name: 'TabErrorState' }).props()).toEqual({
			name: 'Failed to load register data',
			description: 'Unknown error',
		})
		expect(console.error).not.toHaveBeenCalled()
	})

	it('logs a failed request', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = await mountTab()
		expect(wrapper.findComponent({ name: 'TabErrorState' }).props('description')).toBe(
			'offline',
		)
		expect(console.error).toHaveBeenCalledWith(
			'[RegisterObjectsTab] Failed to fetch objects:',
			expect.any(Error),
		)
	})

	it('shows the spinner while loading', () => {
		axios.get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(RegisterObjectsTab, { props: { fileId: 9 } })
		expect(wrapper.find('.register-objects-tab__loading').exists()).toBe(true)
	})
})
