/**
 * GoldenRecordDetail: the provenance rows it derives, and the conflict-resolution round trip.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import GoldenRecordDetail from './GoldenRecordDetail.vue'
import { qualityStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text),
}))
jest.mock('vue-material-design-icons/AccountBoxOutline.vue', () => ({ __esModule: true, default: { name: 'AccountBoxOutline', render: () => null } }))
jest.mock('../../modals/mdm/MdmConflictResolutionModal.vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		default: { name: 'MdmConflictResolutionModal', props: { object: Object }, emits: ['close', 'saved'], render: () => h('div', { class: 'conflict-modal' }) },
	}
})
jest.mock('../../store/store.js', () => ({
	qualityStore: { selectedRegister: null, selectedSchema: null, fetchMasterSources: jest.fn(), fetchGoldenRecord: jest.fn() },
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())

/**
 * A stand-in component that renders its attributes and every slot.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function mockStub(name) {
	const { h } = require('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const slots = Object.values(this.$slots).map((s) => s({}))
			return h('div', { ...this.$attrs, class: [`stub-${name}`, this.$attrs.class] }, slots)
		},
	}
}

/**
 * A module whose every named export is a stand-in component.
 *
 * @return {object} The module.
 */
function mockLibrary() {
	const made = {}
	return new Proxy({ __esModule: true }, {
		get(target, key) {
			if (key in target || typeof key !== 'string') return target[key]
			made[key] = made[key] || mockStub(key)
			return made[key]
		},
		has: () => true,
	})
}

/**
 * Mount with an object.
 *
 * @param {object|null} object The master entity.
 * @return {object} The wrapper.
 */
function mountWith(object) {
	return mount(GoldenRecordDetail, { props: { object }, global: { mocks: { t: (app, text) => text } } })
}

describe('GoldenRecordDetail', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		qualityStore.selectedRegister = null
		qualityStore.selectedSchema = null
	})

	it('derives a row per provenance entry for overrides, objects and plain values', () => {
		const wrapper = mountWith({
			id: 'm1',
			attributeProvenance: {
				name: { override: true, overriddenBy: 'alice', rationale: 'checked' },
				city: { override: true },
				email: { source: 'crm', confidence: 0.9, timestamp: 't1' },
				phone: { winningSource: 'erp', at: 't2' },
				zip: { sourceSystem: 'brp' },
				fax: {},
				note: 'legacy',
			},
		})
		expect(wrapper.vm.provenanceEntries).toEqual([
			{ attribute: 'name', source: 'Manual override (alice)', confidence: 'checked', timestamp: undefined },
			{ attribute: 'city', source: 'Manual override (unknown)', confidence: undefined, timestamp: undefined },
			{ attribute: 'email', source: 'crm', confidence: 0.9, timestamp: 't1' },
			{ attribute: 'phone', source: 'erp', confidence: undefined, timestamp: 't2' },
			{ attribute: 'zip', source: 'brp', confidence: undefined, timestamp: undefined },
			{ attribute: 'fax', source: '—', confidence: undefined, timestamp: undefined },
			{ attribute: 'note', source: 'legacy', confidence: undefined, timestamp: undefined },
		])
		expect(wrapper.find('.stub-CnDataTable').exists()).toBe(true)
	})

	it('treats only override === true as a manual override', () => {
		const wrapper = mountWith({ id: 'm', attributeProvenance: { name: { override: 'yes', source: 'crm' } } })
		expect(wrapper.vm.provenanceEntries[0].source).toBe('crm')
	})

	it('shows the empty provenance state for a missing or non-object map', () => {
		expect(mountWith({ id: 'm', attributeProvenance: 'x' }).vm.provenanceEntries).toEqual([])
		const wrapper = mountWith({ id: 'm' })
		expect(wrapper.find('.stub-CnDataTable').exists()).toBe(false)
		expect(wrapper.find('.stub-NcEmptyContent').attributes('name')).toBe('No golden-record provenance')
	})

	it('shows no conflict action and no modal object without an object, and emits close', async () => {
		const wrapper = mountWith(null)
		expect(wrapper.vm.objectForModal).toBeNull()
		expect(wrapper.find('[data-testid="mdm-resolve-conflicts"]').exists()).toBe(false)
		await wrapper.find('.stub-NcButton').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('loads the master sources and opens the conflict modal with them', async () => {
		qualityStore.fetchMasterSources.mockResolvedValue([{ id: 's1' }])
		const wrapper = mountWith({ id: 'm1' })
		await wrapper.find('[data-testid="mdm-resolve-conflicts"]').trigger('click')
		await flushPromises()
		expect(qualityStore.fetchMasterSources).toHaveBeenCalledWith('m1')
		const modal = wrapper.findComponent({ name: 'MdmConflictResolutionModal' })
		expect(modal.props('object')).toEqual({ id: 'm1', sources: [{ id: 's1' }] })
		modal.vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.conflict-modal').exists()).toBe(false)
	})

	it('opens the modal with no sources for an object without an id', async () => {
		const wrapper = mountWith({ name: 'x' })
		await wrapper.vm.openConflicts()
		expect(qualityStore.fetchMasterSources).not.toHaveBeenCalled()
		expect(wrapper.vm.resolvedSources).toEqual([])
		expect(wrapper.vm.showConflictResolution).toBe(true)
	})

	it('after saving, refetches the golden record from @self, else the object, else the selection', async () => {
		const wrapper = mountWith({ id: 'm1', '@self': { register: 'r1', schema: 's1' }, register: 'rx', schema: 'sx' })
		wrapper.vm.showConflictResolution = true
		await wrapper.vm.handleConflictsResolved()
		expect(wrapper.vm.showConflictResolution).toBe(false)
		expect(qualityStore.fetchGoldenRecord).toHaveBeenLastCalledWith('r1', 's1', 'm1')
		await wrapper.setProps({ object: { id: 'm2', register: 'r2', schema: 's2' } })
		await wrapper.vm.handleConflictsResolved()
		expect(qualityStore.fetchGoldenRecord).toHaveBeenLastCalledWith('r2', 's2', 'm2')
		qualityStore.selectedRegister = 'r3'
		qualityStore.selectedSchema = 's3'
		await wrapper.setProps({ object: { id: 'm3' } })
		await wrapper.vm.handleConflictsResolved()
		expect(qualityStore.fetchGoldenRecord).toHaveBeenLastCalledWith('r3', 's3', 'm3')
	})

	it('does not refetch without a register, schema or id', async () => {
		const wrapper = mountWith({ id: 'm1' })
		await wrapper.vm.handleConflictsResolved()
		await wrapper.setProps({ object: { register: 'r', schema: 's' } })
		await wrapper.vm.handleConflictsResolved()
		await wrapper.setProps({ object: { id: 'm4', register: 'r' } })
		await wrapper.vm.handleConflictsResolved()
		await wrapper.setProps({ object: { id: 'm5', schema: 's' } })
		await wrapper.vm.handleConflictsResolved()
		expect(qualityStore.fetchGoldenRecord).not.toHaveBeenCalled()
	})
})
