import { showError, showSuccess } from '@nextcloud/dialogs'
import OrganisationsIndex from './OrganisationsIndex.vue'
import { reloadAppData } from '../../services/AppInitializationService.js'
import { organisationStore } from '../../store/store.js'

/**
 * Golden record of switching and leaving an organisation from the index:
 * the store call, the app reload after a switch, the question before
 * leaving, and the toast for an allowed and a refused change.
 *
 * @spec exclude Golden record of client-side tenancy actions.
 */

jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true, CnIndexPage: {} }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text) }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/AccountMultiplePlus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Check.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/OfficeBuilding.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/SwapHorizontal.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../modals/organisation/SwitchOrganisationModal.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../IndexRowDescription.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../services/AppInitializationService.js', () => ({ __esModule: true, reloadAppData: jest.fn() }))
jest.mock('../../services/menuActions.js', () => ({ __esModule: true, deleteAction: () => ({}), editAction: () => ({}), viewAction: () => ({}) }))
jest.mock('../../store/store.js', () => ({ __esModule: true, navigationStore: {}, organisationStore: {} }))

let events

/**
 * A recorder that logs its call and answers.
 *
 * @param {string} name The call
 * @param {Function} answer The answer
 * @return {Function} The implementation
 */
function recorded(name, answer = () => undefined) {
	return (...args) => {
		events.push([name, ...args])
		return answer()
	}
}

beforeEach(() => {
	events = []
	showSuccess.mockImplementation(recorded('showSuccess'))
	showError.mockImplementation(recorded('showError'))
	reloadAppData.mockImplementation(recorded('reloadAppData', () => Promise.resolve()))
})

describe('OrganisationsIndex.setActiveOrganisation', () => {
	it.each([
		['allowed', () => Promise.resolve({})],
		['refused', () => Promise.reject(new Error('Not a member'))],
	])('switches when %s', async (label, answer) => {
		organisationStore.setActiveOrganisationById = recorded('setActiveOrganisationById', answer)
		await OrganisationsIndex.methods.setActiveOrganisation.call({}, 'org-1')
		expect(events).toMatchSnapshot()
	})

	it('reports a failing app reload as a failed switch', async () => {
		organisationStore.setActiveOrganisationById = recorded('setActiveOrganisationById', () => Promise.resolve())
		reloadAppData.mockImplementation(recorded('reloadAppData', () => Promise.reject(new Error('reload failed'))))
		await OrganisationsIndex.methods.setActiveOrganisation.call({}, 'org-1')
		expect(events).toMatchSnapshot()
	})
})

describe('OrganisationsIndex.leaveOrganisation', () => {
	it.each([
		['confirmed and allowed', true, () => Promise.resolve({})],
		['confirmed and refused', true, () => Promise.reject(new Error('Last owner'))],
		['declined', false, () => Promise.resolve({})],
	])('leaves when %s', async (label, answer, leave) => {
		global.confirm = jest.fn((text) => {
			events.push(['confirm', text])
			return answer
		})
		organisationStore.leaveOrganisation = recorded('leaveOrganisation', leave)
		await OrganisationsIndex.methods.leaveOrganisation.call({}, { uuid: 'org-1', name: 'Acme' })
		expect(events).toMatchSnapshot()
	})
})
