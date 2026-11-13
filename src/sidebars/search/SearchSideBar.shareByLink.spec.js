/**
 * Sharing the active saved view by link: only its owner is offered the
 * action, and the dialog names the view by its id.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { getCurrentUser } from '@nextcloud/auth'
import ViewAccessLinksModal from '../../modals/view/ViewAccessLinksModal.vue'
import SearchSideBar from './SearchSideBar.vue'
import { viewsStore } from '../../store/store.js'

jest.mock('@conduction/nextcloud-vue', () => ({ CnIndexSidebar: {} }))
jest.mock('@nextcloud/auth', () => ({ getCurrentUser: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../modals/view/DeleteView.vue', () => ({}))
jest.mock('../../modals/view/EditView.vue', () => ({}))
jest.mock('../../components/access-links/SubjectAccessLinks.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
	viewsStore: { activeView: null },
}))

describe('SearchSideBar share by link', () => {
	it.each([
		['the owner of the active view', { owner: 'alice' }, 'alice', true],
		['someone else', { owner: 'alice' }, 'bob', false],
		['a view without an owner', { owner: null }, 'alice', false],
		['nobody signed in', { owner: 'alice' }, null, false],
		['no active view', null, 'alice', false],
	])('offers the action to %s: %s', (label, view, uid, offered) => {
		viewsStore.activeView = view
		getCurrentUser.mockReturnValue(uid === null ? null : { uid })
		expect(SearchSideBar.computed.ownsActiveView.call({})).toBe(offered)
	})

	it('starts with the dialog closed', () => {
		expect(SearchSideBar.data.call({}).showViewLinksDialog).toBe(false)
	})
})

describe('ViewAccessLinksModal', () => {
	it.each([
		[{ id: 12, uuid: 'v-uuid' }, '12'],
		[{ uuid: 'v-uuid' }, 'v-uuid'],
	])('names the view %j as %s', (view, id) => {
		expect(ViewAccessLinksModal.computed.viewId.call({ view })).toBe(id)
	})
})
