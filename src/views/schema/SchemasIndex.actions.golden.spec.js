/**
 * The row menus of the registers and schemas lists: which entries they hold,
 * when edit and delete are locked, the lock reasons, and what each entry runs.
 *
 * @spec exclude Client-side menu composition.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import SchemasIndex from './SchemasIndex.vue'
import {
	configurationStore,
	navigationStore,
	schemaStore,
} from '../../store/store.js'


jest.mock('@nextcloud/axios', () => ({ post: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	translatePlural: (app, one, many) => many,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Api.vue', () => ({}))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/Download.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('../../components/shared/ActionMenu.vue', () => ({}))
jest.mock('../../components/cards/RegisterSchemaCard.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationList: [] },
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	registerStore: { setRegisterItem: jest.fn() },
	schemaStore: { setSchemaItem: jest.fn() },
}))

const EXTERNAL = { id: 9, title: 'Remote config', sourceType: 'github', schemas: [{ id: 1 }] }

/**
 * A list instance with the page's methods.
 *
 * @return {object} The instance
 */
function page() {
	const vm = {}
	for (const [name, method] of Object.entries(SchemasIndex.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

/**
 * The entries without their icons and run functions.
 *
 * @param {Array<object>} entries The menu
 * @return {Array<object>} The entries' shown state
 */
function shown(entries) {
	return entries.map(({ label, disabled, title }) => ({ label, disabled, title }))
}

beforeEach(() => {
	jest.clearAllMocks()
	configurationStore.configurationList = []
})

describe('SchemasIndex.schemaActions', () => {
	it('leaves edit and delete open for a free, empty schema', () => {
		expect(shown(page().schemaActions({ id: 1, title: 'S' }))).toMatchSnapshot()
	})

	it('locks edit for an externally managed schema and names the configuration', () => {
		configurationStore.configurationList = [EXTERNAL]
		expect(shown(page().schemaActions({ id: 1, title: 'S' }))).toMatchSnapshot()
	})

	it('names no configuration when the managing one has no title', () => {
		configurationStore.configurationList = [{ ...EXTERNAL, title: undefined }]
		expect(shown(page().schemaActions({ id: 1, title: 'S' }))).toMatchSnapshot()
	})

	it('leaves edit open for a locally managed schema', () => {
		configurationStore.configurationList = [{ ...EXTERNAL, sourceType: 'local' }]
		expect(shown(page().schemaActions({ id: 1, title: 'S' }))).toMatchSnapshot()
	})

	it('locks delete while objects are attached', () => {
		expect(
			shown(page().schemaActions({ id: 1, stats: { objects: { total: 2 } } })),
		).toMatchSnapshot()
	})

	it('selects the schema, then opens the edit modal or the delete dialog', () => {
		const schema = { id: 1, title: 'S' }
		page()
			.schemaActions(schema)
			.forEach((entry) => entry.run())
		expect(schemaStore.setSchemaItem.mock.calls).toEqual([[schema], [schema]])
		expect(navigationStore.setModal.mock.calls).toEqual([['editSchema']])
		expect(navigationStore.setDialog.mock.calls).toEqual([['deleteSchema']])
	})
})
