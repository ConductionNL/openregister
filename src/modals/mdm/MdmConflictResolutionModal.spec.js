import { flushPromises, mount } from '@vue/test-utils'
/**
 * MdmConflictResolutionModal: which attributes count as conflicts, when save
 * is enabled, and what save writes for persistent and one-off outcomes.
 *
 * @spec exclude Unit tests of the MDM conflict resolution modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import MdmConflictResolutionModal from './MdmConflictResolutionModal.vue'
import { qualityStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	qualityStore: {
		setAttributeOverride: jest.fn(),
		persistTrustRule: jest.fn(),
		touchObject: jest.fn(),
	},
}))

const OBJECT = {
	id: 'master-1',
	'@self': { register: 'reg', schema: 'person' },
	sources: [
		{ sourceSystem: 'crm', values: { name: 'Ann', city: 'Utrecht', tags: ['a'], empty: '' } },
		{ sourceSystem: 'erp', mappedAttributes: { name: 'Anne', city: 'Utrecht', tags: ['b'] } },
		{ sourceSystem: 'hr', values: null },
		null,
	],
}

/**
 * Mount the modal over one object.
 *
 * @param {object} object The master object.
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function mountModal(object = OBJECT, props = {}) {
	return mount(MdmConflictResolutionModal, {
		props: { object, ...props },
		global: { mocks: { t: (app, text) => text } },
	})
}

/**
 * Select the first option of every conflict.
 *
 * @param {object} vm The component instance.
 * @param {string} outcome The outcome for every conflict.
 * @return {void}
 */
function pickFirst(vm, outcome = 'persistent') {
	for (const conflict of vm.conflicts) {
		vm.selections[conflict.attribute] = conflict.options[0]
		vm.outcomes[conflict.attribute] = outcome
	}
}

beforeEach(() => {
	jest.clearAllMocks()
	qualityStore.setAttributeOverride.mockResolvedValue({})
	qualityStore.persistTrustRule.mockResolvedValue({})
	qualityStore.touchObject.mockResolvedValue({})
})

describe('MdmConflictResolutionModal conflicts', () => {
	it('lists only attributes whose sources disagree', () => {
		const wrapper = mountModal()

		expect(wrapper.vm.conflicts.map((c) => c.attribute)).toEqual(['name', 'tags'])
		expect(wrapper.vm.conflicts[0].options.map((o) => o.label)).toEqual(['Ann (crm)', 'Anne (erp)'])
		expect(wrapper.vm.conflicts[1].options.map((o) => o.label)).toEqual(['["a"] (crm)', '["b"] (erp)'])
		expect(wrapper.findAll('[data-testid="conflict-row"]')).toHaveLength(2)
		expect(wrapper.vm.outcomes).toEqual({ name: 'persistent', tags: 'persistent' })
	})

	it('finds source records in another array field', () => {
		const wrapper = mountModal({
			id: 'm',
			links: [
				{ sourceSystem: 'a', values: { x: 1 } },
				{ sourceSystem: 'b', values: { x: 2 } },
			],
			other: [],
		})

		expect(wrapper.vm.conflicts.map((c) => c.attribute)).toEqual(['x'])
	})

	it('shows the empty state when nothing disagrees', () => {
		const wrapper = mountModal({ id: 'm', title: 'No sources' })

		expect(wrapper.vm.sourceRecords).toEqual([])
		expect(wrapper.vm.canSave).toBe(false)
		expect(wrapper.find('[data-stub="empty"]').text()).toContain('No conflicts to resolve')
	})

	it('enables save only once every conflict has a winner', () => {
		const wrapper = mountModal()
		expect(wrapper.vm.canSave).toBe(false)

		wrapper.vm.selections.name = wrapper.vm.conflicts[0].options[0]
		expect(wrapper.vm.canSave).toBe(false)

		wrapper.vm.selections.tags = wrapper.vm.conflicts[1].options[1]
		expect(wrapper.vm.canSave).toBe(true)
		wrapper.vm.handleSelectionChange()
	})
})

describe('MdmConflictResolutionModal save', () => {
	it('writes a trust rule per persistent outcome and touches the master object', async () => {
		const wrapper = mountModal()
		pickFirst(wrapper.vm)
		wrapper.vm.rationale = 'CRM is leading'

		await wrapper.vm.save()

		expect(qualityStore.persistTrustRule).toHaveBeenCalledWith({
			entityType: 'person',
			attribute: 'name',
			sourceSystem: 'crm',
			trustTier: 'gold',
			rationale: 'CRM is leading',
		})
		expect(qualityStore.persistTrustRule).toHaveBeenCalledTimes(2)
		expect(qualityStore.touchObject).toHaveBeenCalledWith('reg', 'person', 'master-1')
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('pins one-off outcomes without a trust rule or touch', async () => {
		const wrapper = mountModal()
		pickFirst(wrapper.vm, 'one-off')

		await wrapper.vm.save()

		expect(qualityStore.setAttributeOverride).toHaveBeenCalledWith('master-1', 'name', 'Ann', undefined)
		expect(qualityStore.persistTrustRule).not.toHaveBeenCalled()
		expect(qualityStore.touchObject).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('uses the entity type prop and skips the touch without a register', async () => {
		const wrapper = mountModal({ ...OBJECT, '@self': {} }, { entityType: 'contact' })
		pickFirst(wrapper.vm)

		await wrapper.vm.save()

		expect(qualityStore.persistTrustRule.mock.calls[0][0].entityType).toBe('contact')
		expect(qualityStore.persistTrustRule.mock.calls[0][0].rationale).toBeUndefined()
		expect(qualityStore.touchObject).not.toHaveBeenCalled()
	})

	it('reads register and schema off the object itself', async () => {
		const wrapper = mountModal({ ...OBJECT, '@self': undefined, register: 'r2', schema: 's2' })
		pickFirst(wrapper.vm)

		await wrapper.vm.save()

		expect(qualityStore.touchObject).toHaveBeenCalledWith('r2', 's2', 'master-1')
	})

	it('falls back to the object entity type', () => {
		const wrapper = mountModal({ id: 'm', entityType: 'org' })
		expect(wrapper.vm.resolveEntityType()).toBe('org')
		expect(mountModal({ id: 'm' }).vm.resolveEntityType()).toBe('')
	})

	it.each([
		['the server error', { response: { data: { error: 'Forbidden' } } }, 'Forbidden'],
		['the generic message', new Error('network'), 'The conflict resolution could not be saved.'],
	])('keeps the modal open with %s when save fails', async (label, failure, message) => {
		qualityStore.persistTrustRule.mockRejectedValue(failure)
		const wrapper = mountModal()
		pickFirst(wrapper.vm)

		await wrapper.vm.save()
		await flushPromises()

		expect(wrapper.vm.error).toBe(message)
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(wrapper.text()).toContain(message)
	})

	it('does nothing while save is not allowed', async () => {
		const wrapper = mountModal()

		await wrapper.vm.save()

		expect(qualityStore.persistTrustRule).not.toHaveBeenCalled()
	})
})
