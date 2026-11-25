import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import EntityDetail from './EntityDetail.vue'

/**
 * The entity detail page's capacity confirm control (detection-knows-the-person task 2.3).
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.3
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), patch: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translate: (app, text) => `t:${text}`,
}))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('../../components/PaginationComponent.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/FilterToggleHeader.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ViewModeSwitch.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/CheckCircleOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => ({ __esModule: true, default: { name: 'EyeOutline' } }))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/LinkVariantOff.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('./EntityStatsTable.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../eventBus.js', () => ({ __esModule: true, default: { on: jest.fn(), off: jest.fn() } }))
jest.mock('../../services/menuActions.js', () => ({ __esModule: true, refreshAction() {} }))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { sidebarState: { entities: false }, setSidebarState: jest.fn() },
}))

/**
 * A bare page state carrying the component's methods and computed getters,
 * with the load replaced by a spy.
 *
 * @param {object} component The page component
 * @param {object} data The page data
 * @return {object} The page
 */
function page(component, data = {}) {
	const state = { loadEntities: jest.fn(), loadEntity: jest.fn(), $router: { push: jest.fn() }, ...data }
	for (const [name, fn] of Object.entries(component.methods)) {
		if (!(name in data) && name !== 'loadEntities' && name !== 'loadEntity') {
			state[name] = fn.bind(state)
		}
	}
	for (const [name, getter] of Object.entries(component.computed || {})) {
		Object.defineProperty(state, name, { get: getter.bind(state), configurable: true })
	}
	return state
}

describe('EntityDetail capacity confirmation', () => {
	beforeEach(() => {
		axios.patch.mockReset()
		showError.mockReset()
	})

	it('replaces the confirmed relation with what the server recorded', async () => {
		axios.patch.mockResolvedValue({ data: { id: 2, capacity: 'public-office', capacityApplied: 'public-office', skipAnonymization: true } })
		const other = { id: 1, capacity: 'private', capacityApplied: null }
		const state = page(EntityDetail, {
			confirmingCapacity: null,
			relations: [other, { id: 2, capacity: 'public-office', capacityApplied: null, context: 'De wethouder' }],
		})

		await state.confirmRelationCapacity(state.relations[1])

		expect(axios.patch).toHaveBeenCalledWith('/gen/apps/openregister/api/entity-relations/{id}', { capacity: 'public-office' })
		expect(state.relations[0]).toBe(other)
		expect(state.relations[1]).toEqual({
			id: 2, capacity: 'public-office', capacityApplied: 'public-office', skipAnonymization: true, context: 'De wethouder',
		})
		expect(state.confirmingCapacity).toBeNull()
		expect(state.canConfirmCapacity(state.relations[1])).toBe(false)
		expect(showError).not.toHaveBeenCalled()
	})

	it('shows the server refusal and leaves the relation unconfirmed', async () => {
		axios.patch.mockRejectedValue({ response: { data: { error: 'Decision taken by another reviewer' } } })
		const relation = { id: 3, capacity: 'professional', capacityApplied: null }
		const state = page(EntityDetail, { confirmingCapacity: null, relations: [relation] })

		await state.confirmRelationCapacity(relation)

		expect(showError).toHaveBeenCalledWith('Decision taken by another reviewer')
		expect(state.relations).toEqual([relation])
		expect(state.confirmingCapacity).toBeNull()
	})

	it('falls back to its own message when the refusal carries none', async () => {
		axios.patch.mockRejectedValue(new Error('network'))
		const state = page(EntityDetail, { confirmingCapacity: null, relations: [{ id: 4, capacity: 'private' }] })

		await state.confirmRelationCapacity(state.relations[0])

		expect(showError).toHaveBeenCalledWith('t:Could not confirm the capacity')
	})
})
