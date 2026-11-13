/**
 * Golden record of the initial component state that data() returns for the
 * components whose state is a plain literal, and proof that every call hands
 * out its own copy.
 *
 * @spec exclude Golden record of client-side initial form state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/CloudSync.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountMinus.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountMultiple.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Shield.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TestTube.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseCheckOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseImportOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Filter.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Star.vue', () => ({}))
jest.mock('vue-material-design-icons/StarOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ViewDashboardOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/ShareVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocument.vue', () => ({}))
jest.mock('vue-material-design-icons/Information.vue', () => ({}))
jest.mock('vue-material-design-icons/Key.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronUp.vue', () => ({}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocumentCheckOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Robot.vue', () => ({}))
jest.mock('vue-material-design-icons/VectorSquare.vue', () => ({}))
jest.mock('vue-material-design-icons/PlayCircle.vue', () => ({}))
jest.mock('../store/store.js', () => ({}))

import EditConfiguration from '../modals/configuration/EditConfiguration.vue'
import EditOrganisation from '../modals/organisation/EditOrganisation.vue'
import LLMConfigModal from '../modals/settings/LLMConfigModal.vue'
import EditSource from '../modals/source/EditSource.vue'
import SearchSideBar from '../sidebars/search/SearchSideBar.vue'
import FileConfiguration from '../views/settings/sections/FileConfiguration.vue'
import LlmConfiguration from '../views/settings/sections/LlmConfiguration.vue'

const COMPONENTS = {
	EditConfiguration,
	EditOrganisation,
	LLMConfigModal,
	EditSource,
	SearchSideBar,
	FileConfiguration,
	LlmConfiguration,
}

/**
 * Every nested object and array of a value, the value itself included.
 *
 * @param {*} value The value to walk
 * @return {Array<object>} The containers found
 */
function containers(value) {
	if (value === null || typeof value !== 'object') {
		return []
	}
	return [value, ...Object.values(value).flatMap(containers)]
}

describe('initial component data golden', () => {
	for (const [name, component] of Object.entries(COMPONENTS)) {
		it(`${name} starts from the recorded state`, () => {
			const t = (app, text) => `[${text}]`
			expect(component.data.call({ t })).toMatchSnapshot()
		})

		it(`${name} hands every instance its own state`, () => {
			const t = (app, text) => `[${text}]`
			const first = containers(component.data.call({ t }))
			const second = new Set(containers(component.data.call({ t })))
			expect(first.filter((part) => second.has(part))).toEqual([])
		})
	}
})
