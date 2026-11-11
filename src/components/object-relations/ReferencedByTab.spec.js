/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The referenced-by tab and the relation tab mixin it is built on: the
 * request, the groups it lists, the failure and the date helper.
 *
 * @spec exclude Unit test of the client-side referenced-by tab.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import ReferencedByTab from './ReferencedByTab.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
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
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], template: '<div class="empty">{{ name }}</div>' },
	NcListItem: {
		name: 'NcListItem',
		props: ['name'],
		emits: ['click'],
		template: '<li class="item" @click="$emit(\'click\')">{{ name }}|<slot name="subname" /></li>',
	},
}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({ name: 'CubeOutline', render: () => null }))
jest.mock('vue-material-design-icons/LinkVariantOff.vue', () => ({ name: 'LinkVariantOff', render: () => null }))
jest.mock('../shared/TabErrorState.vue', () => ({
	name: 'TabErrorState',
	props: ['name', 'description'],
	render: () => null,
}))

const UPDATED = '2026-03-04T05:06:07Z'
const GROUPS = [
	{
		register: { id: 1, slug: 'zaken' },
		schema: { id: 2, slug: 'besluit', title: 'Besluit' },
		total: 3,
		results: [
			{ id: 'r1', title: 'Besluit 1', status: 'open', updated: UPDATED },
			{ id: 'r2' },
		],
	},
	{
		register: { id: 5 },
		schema: { id: 6, slug: 'notitie' },
		total: 1,
		results: [{ id: 'n1', title: 'Note', status: 'draft' }],
	},
]

/**
 * Mount the tab and let it load.
 *
 * @param {object} router A router stand-in, or none
 * @return {Promise<object>} The wrapper
 */
async function mountTab(router = { push: jest.fn() }) {
	const wrapper = mount(ReferencedByTab, {
		props: { register: 1, schema: 2, objectId: 'obj-1' },
		global: { mocks: { $router: router } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('ReferencedByTab', () => {
	it('lists the referencing records per schema', async () => {
		axios.get.mockResolvedValue({ data: { groups: GROUPS, total: 4 } })
		const wrapper = await mountTab()
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/1/2/obj-1/referenced-by',
		)
		const headings = wrapper.findAll('.referenced-by-tab__heading').map((h) => h.text())
		expect(headings).toEqual(['Besluit 3', 'notitie 1'])
		expect(wrapper.findAll('.item').map((i) => i.text())).toEqual([
			`Besluit 1|open · ${new Date(UPDATED).toLocaleString()}`,
			'r2|',
			'Note|draft',
		])
		expect(wrapper.findAll('.referenced-by-tab__more').map((m) => m.text())).toEqual([
			'and 1 more',
		])
	})

	it('opens a record by slug, or by id without one', async () => {
		axios.get.mockResolvedValue({ data: { groups: GROUPS } })
		const router = { push: jest.fn() }
		const wrapper = await mountTab(router)
		const items = wrapper.findAll('.item')
		await items[0].trigger('click')
		await items[2].trigger('click')
		expect(router.push.mock.calls).toEqual([
			['/objects/zaken/besluit/r1'],
			['/objects/5/notitie/n1'],
		])
	})

	it('shows the empty state when nothing points here', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = await mountTab()
		expect(wrapper.find('.empty').text()).toBe('Nothing points here yet')
		expect(wrapper.vm.total).toBe(0)
	})

	it('shows the server error, then clears it on a successful reload', async () => {
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'No access' } } })
		const wrapper = await mountTab()
		expect(wrapper.findComponent({ name: 'TabErrorState' }).props('description')).toBe('No access')
		axios.get.mockResolvedValueOnce({ data: { groups: GROUPS } })
		await wrapper.setProps({ objectId: 'obj-2' })
		await flushPromises()
		expect(wrapper.findComponent({ name: 'TabErrorState' }).exists()).toBe(false)
		expect(axios.get).toHaveBeenLastCalledWith(
			'/index.php/apps/openregister/api/objects/1/2/obj-2/referenced-by',
		)
	})

	it('falls back to the error message, then to nothing', async () => {
		axios.get.mockRejectedValueOnce(new Error('offline'))
		const wrapper = await mountTab()
		expect(wrapper.vm.errorMessage).toBe('offline')
		wrapper.vm.showFailure({})
		expect(wrapper.vm.errorMessage).toBe('')
	})

	it('does not load for an empty object id', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = await mountTab()
		await wrapper.setProps({ objectId: '' })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('formats a date, and passes through what is not one', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = await mountTab()
		expect(wrapper.vm.formatDate('')).toBe('')
		expect(wrapper.vm.formatDate('soon')).toBe('soon')
		expect(wrapper.vm.formatDate(UPDATED)).toBe(new Date(UPDATED).toLocaleString())
	})
})
