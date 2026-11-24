/**
 * The organisation modal's remove-member confirmation: opening it for a
 * member, refusing without an organisation or member, and cancelling it.
 * Runs on the options object against a synthetic `this`.
 *
 * @spec exclude Client-side organisation member removal dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditOrganisation from './EditOrganisation.vue'

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	organisationStore: { organisationItem: null, saveOrganisation: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountMinus.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountMultiple.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Shield.vue', () => ({}))
jest.mock('../../components/RbacTable.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))
jest.mock('./RemoveUserDialog.vue', () => ({}))

const { removeUser, cancelRemoveUser } = EditOrganisation.methods

/**
 * A modal context with no removal pending.
 *
 * @param {object} organisationItem The organisation in the form
 * @return {object} The context
 */
function modal(organisationItem = { uuid: 'org-1' }) {
	return { organisationItem, userToRemove: null, showRemoveUserDialog: false }
}

describe('EditOrganisation — removing a member', () => {
	it('asks for confirmation before removing a member', () => {
		const ctx = modal()

		removeUser.call(ctx, 'anna')

		expect(ctx).toMatchObject({ userToRemove: 'anna', showRemoveUserDialog: true })
	})

	it('asks nothing for an unsaved organisation or without a member', () => {
		const unsaved = modal({})
		removeUser.call(unsaved, 'anna')
		expect(unsaved).toMatchObject({ userToRemove: null, showRemoveUserDialog: false })

		const noMember = modal()
		removeUser.call(noMember, '')
		expect(noMember).toMatchObject({ userToRemove: null, showRemoveUserDialog: false })
	})

	it('cancelling closes the confirmation and forgets the member', () => {
		const ctx = modal()
		removeUser.call(ctx, 'anna')

		cancelRemoveUser.call(ctx)

		expect(ctx).toMatchObject({ userToRemove: null, showRemoveUserDialog: false })
	})
})
