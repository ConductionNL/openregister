/**
 * EditView carries a view's presentation: the chosen presentation rides the
 * save payload, and a save refusal naming a presentation field is shown under
 * that field's picker without closing the modal (REQ-VIEW-PRES-06, D-4).
 *
 * @spec exclude Client-side view-editor presentation wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditView from './EditView.vue'
import { objectStore, viewsStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	viewsStore: { updateView: jest.fn(), fetchViews: jest.fn() },
	objectStore: { searchSchema: null },
}))
jest.mock('../../services/groupSearch.js', () => ({ searchGroupsDebounced: jest.fn() }))
jest.mock('@conduction/nextcloud-vue', () => ({ CnViewPresentationPicker: {} }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/ShareVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/ViewDashboardOutline.vue', () => ({}))
jest.mock('../../components/shared/GroupSelect.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))

/**
 * A bare EditView context with the methods bound.
 *
 * @param {object} state State on top of the data.
 * @return {object} The context.
 */
function context(state = {}) {
	const ctx = { ...EditView.data.call({}), view: { id: 7 }, selectedGroups: [], selectedUsers: [], ...state }
	for (const [name, method] of Object.entries(EditView.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.t = (app, s) => s
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
	objectStore.searchSchema = null
	jest.spyOn(console, 'error').mockImplementation(() => {})
})
afterEach(() => jest.restoreAllMocks())

describe('EditView presentation wiring', () => {
	it('loads the view type choice from the active search schema', () => {
		objectStore.searchSchema = { properties: { status: { enum: ['open'] } } }
		expect(EditView.computed.viewSchema.call({})).toEqual({
			properties: { status: { enum: ['open'] } },
		})
	})

	it('offers table only when no schema is active', () => {
		objectStore.searchSchema = null
		expect(EditView.computed.viewSchema.call({})).toBeNull()
	})

	it('records the picked presentation and clears a shown refusal', () => {
		const ctx = context({ presentationErrors: { 'kanban.groupByField': 'x' } })
		ctx.onPresentationInput({ viewType: 'kanban', kanban: { groupByField: 'status' } })
		expect(ctx.viewData.presentation).toEqual({ viewType: 'kanban', kanban: { groupByField: 'status' } })
		expect(ctx.presentationErrors).toEqual({})
	})

	it('sends the chosen presentation with the view', () => {
		const ctx = context()
		ctx.viewData = { ...ctx.viewData, name: 'Board', presentation: { viewType: 'kanban', kanban: { groupByField: 'status' } } }
		expect(ctx.viewUpdate().presentation).toEqual({ viewType: 'kanban', kanban: { groupByField: 'status' } })
	})

	it('defaults the sent presentation to a table when none was chosen', () => {
		const ctx = context()
		ctx.viewData = { ...ctx.viewData, name: 'Plain', presentation: undefined }
		expect(ctx.viewUpdate().presentation).toEqual({ viewType: 'table' })
	})

	it('persists the presentation through the store on save', async () => {
		viewsStore.updateView.mockResolvedValue({})
		viewsStore.fetchViews.mockResolvedValue()
		const ctx = context()
		ctx.viewData = { ...ctx.viewData, name: 'Board', presentation: { viewType: 'calendar', calendar: { dateField: 'ontvangst' } } }
		await ctx.saveView()
		expect(viewsStore.updateView).toHaveBeenCalledWith(7, expect.objectContaining({
			presentation: { viewType: 'calendar', calendar: { dateField: 'ontvangst' } },
		}))
	})

	it('puts a save refusal under the field it names and keeps the modal open', () => {
		const ctx = context()
		ctx.showSaveFailure(Object.assign(new Error('A calendar presentation requires a non-empty calendar.dateField'), {
			status: 400,
			body: { error: 'A calendar presentation requires a non-empty calendar.dateField' },
		}))
		expect(ctx.success).toBe(false)
		expect(ctx.presentationErrors).toEqual({
			'calendar.dateField': 'A calendar presentation requires a non-empty calendar.dateField',
		})
		expect(ctx.error).toContain('calendar.dateField')
	})

	it('shows a non-presentation refusal only as the general error', () => {
		const ctx = context()
		ctx.showSaveFailure(Object.assign(new Error('Name taken'), { status: 400, body: { error: 'Name taken' } }))
		expect(ctx.presentationErrors).toEqual({})
		expect(ctx.error).toBe('Name taken')
	})
})
