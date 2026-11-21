/**
 * The row menus of the registers and schemas lists: which entries they hold,
 * when edit and delete are locked, the lock reasons, and what each entry runs.
 *
 * @spec exclude Client-side menu composition.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import RegistersIndex from './RegistersIndex.vue'
import {
	configurationStore,
	navigationStore,
	registerStore,
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
jest.mock('../../components/cards/RegisterSchemaCard.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationList: [] },
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	registerStore: { setRegisterItem: jest.fn() },
	schemaStore: { setSchemaItem: jest.fn() },
}))

const EXTERNAL = { id: 9, title: 'Remote config', sourceType: 'github', registers: [1] }

/**
 * A list instance with the page's methods and a spied form dialog.
 *
 * @return {object} The instance
 */
function page() {
	const vm = {
		...RegistersIndex.methods,
		$refs: { indexPage: { openFormDialog: jest.fn() } },
		$router: { push: jest.fn() },
		viewOasDoc: jest.fn(),
		downloadOas: jest.fn(),
	}
	for (const [name, method] of Object.entries(vm)) {
		if (typeof method === 'function' && RegistersIndex.methods[name] === method) {
			vm[name] = method.bind(vm)
		}
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

describe('RegistersIndex.registerActions', () => {
	it('leaves edit and delete open for a free, empty register', () => {
		expect(shown(page().registerActions({ id: 1, title: 'R' }))).toMatchSnapshot()
	})

	it('locks edit for an externally managed register and names the configuration', () => {
		configurationStore.configurationList = [EXTERNAL]
		expect(shown(page().registerActions({ id: 1, title: 'R' }))).toMatchSnapshot()
	})

	it('leaves edit open for a locally managed register', () => {
		configurationStore.configurationList = [{ ...EXTERNAL, sourceType: 'local' }]
		expect(shown(page().registerActions({ id: 1, title: 'R' }))).toMatchSnapshot()
	})

	it('locks delete while objects are attached', () => {
		expect(
			shown(page().registerActions({ id: 1, title: 'R', stats: { total: 3 } })),
		).toMatchSnapshot()
	})

	it('runs each entry against the clicked register', () => {
		const vm = page()
		const register = { id: 1, title: 'R' }
		const entries = vm.registerActions(register)
		entries.forEach((entry) => entry.run())
		expect(vm.$refs.indexPage.openFormDialog.mock.calls).toEqual([[register]])
		expect(registerStore.setRegisterItem.mock.calls).toEqual([
			[register],
			[register],
			[register],
			[register],
			[register],
			[{ id: 1 }],
		])
		expect(navigationStore.setModal.mock.calls).toEqual([
			['publishRegister'],
			['importRegister'],
		])
		expect(navigationStore.setDialog.mock.calls).toEqual([['deleteRegister']])
		expect(vm.viewOasDoc.mock.calls).toEqual([[register]])
		expect(vm.downloadOas.mock.calls).toEqual([[register]])
		expect(vm.$router.push.mock.calls).toEqual([['/registers/1']])
	})
})
