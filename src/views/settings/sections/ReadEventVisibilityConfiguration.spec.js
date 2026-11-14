/**
 * ReadEventVisibilityConfiguration: shows the groups that see who read a
 * record and stores the chosen group ids.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
 */
import { flushPromises, mount } from '@vue/test-utils'
import ReadEventVisibilityConfiguration from './ReadEventVisibilityConfiguration.vue'
import { useSettingsStore } from '../../../store/settings.js'

jest.mock('../../../store/settings.js', () => {
	const { reactive } = require('vue')
	const store = reactive({ saving: false, loadAuditSettings: jest.fn(), updateAuditSettings: jest.fn() })
	return { useSettingsStore: () => store }
})
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
	return { __esModule: true, NcButton: stub('NcButton'), NcSelect: stub('NcSelect') }
})

const groups = [
	{ id: 'admin', label: 'admin' },
	{ id: 'jeugd', label: 'Jeugdteam' },
	{ id: 'wmo', label: 'Wmo-consulenten' },
]

/**
 * Mount the section.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountIt() {
	const wrapper = mount(ReadEventVisibilityConfiguration, { global: { mocks: { t: (app, text) => text } } })
	await flushPromises()
	return wrapper
}

describe('ReadEventVisibilityConfiguration', () => {
	let store
	beforeEach(() => {
		store = useSettingsStore()
		store.loadAuditSettings.mockReset()
		store.updateAuditSettings.mockReset()
		store.saving = false
	})

	it('shows the stored groups as chosen, by their names', async () => {
		store.loadAuditSettings.mockResolvedValue({ readEventsVisibleTo: ['jeugd'], groups })
		const wrapper = await mountIt()

		expect(wrapper.vm.groups).toEqual(groups)
		expect(wrapper.vm.selected).toEqual([{ id: 'jeugd', label: 'Jeugdteam' }])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('keeps a stored group that no longer exists, so saving does not drop it unseen', async () => {
		store.loadAuditSettings.mockResolvedValue({ readEventsVisibleTo: ['gone'], groups })
		const wrapper = await mountIt()

		expect(wrapper.vm.selected).toEqual([{ id: 'gone', label: 'gone' }])
	})

	it('saves the chosen group ids', async () => {
		store.loadAuditSettings.mockResolvedValue({ readEventsVisibleTo: [], groups })
		const wrapper = await mountIt()
		wrapper.vm.selected = [groups[1], groups[2]]

		await wrapper.vm.save()

		expect(store.updateAuditSettings).toHaveBeenCalledWith({ readEventsVisibleTo: ['jeugd', 'wmo'] })
	})

	it('saves an empty list when no group is chosen: only administrators', async () => {
		store.loadAuditSettings.mockResolvedValue({ readEventsVisibleTo: ['jeugd'], groups })
		const wrapper = await mountIt()
		wrapper.vm.selected = []

		await wrapper.vm.save()

		expect(store.updateAuditSettings).toHaveBeenCalledWith({ readEventsVisibleTo: [] })
	})

	it('starts empty when the settings cannot be read', async () => {
		store.loadAuditSettings.mockResolvedValue(undefined)
		const wrapper = await mountIt()

		expect(wrapper.vm.groups).toEqual([])
		expect(wrapper.vm.selected).toEqual([])
	})
})
