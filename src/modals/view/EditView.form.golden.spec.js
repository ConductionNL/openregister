/**
 * Golden record of the view edit modal's starting state and of how it loads
 * a view into its form: the fields, the shared groups with their mode, the
 * shared users and the reset of the tab and outcome.
 *
 * @spec exclude Golden record of client-side view form hydration.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditView from './EditView.vue'

jest.mock('../../components/RbacTable.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))
jest.mock('../organisation/RemoveUserDialog.vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountMinus.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountMultiple.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/ShareVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/Shield.vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
	t: (app, text) => text,
	n: (app, one) => one,
}))
jest.mock('../../store/store.js', () => ({
	applicationStore: {},
	navigationStore: {},
	organisationStore: {},
	viewsStore: {},
}))

describe('EditView form (golden)', () => {
	it('starts with an empty, private view', () => {
		expect(EditView.data.call({})).toMatchSnapshot()
	})

	it.each([
		['nothing', null],
		['a bare view', {}],
		[
			'a shared view',
			{
				name: 'Mine',
				description: 'D',
				isPublic: true,
				isDefault: true,
				query: { a: 1 },
				configuration: { ignored: true },
				sharedWith: [{ group: 'staff', mode: 'write' }, { group: 'all' }],
				sharedUsers: ['ann', 'bob'],
			},
		],
		['a view with only a configuration', { name: 'C', configuration: { b: 2 } }],
	])('loads %s into the form', (name, view) => {
		const ctx = {
			...EditView.data.call({}),
			activeTab: 'sharing',
			nameTouched: true,
			success: true,
			error: 'old',
		}
		EditView.watch.view.handler.call(ctx, view)
		expect(JSON.stringify(ctx)).toMatchSnapshot()
	})
})
