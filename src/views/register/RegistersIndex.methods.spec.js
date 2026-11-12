/**
 * The registers list's own handlers: refreshing, paging, selecting, which
 * configuration manages a row and how the row is marked, saving the form,
 * opening a register and the API documentation, and a failed OAS download.
 * Each method runs against a plain `this` and stub stores.
 *
 * @spec exclude Client-side list handlers of the registers index.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { showError } from '@nextcloud/dialogs'
import RegistersIndex from './RegistersIndex.vue'
import { downloadRegisterOas, viewRegisterOas } from '../../services/registerOas.js'
import { configurationStore, registerStore } from '../../store/store.js'

jest.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {} }))
jest.mock('@nextcloud/axios', () => ({ post: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many) => many,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Api.vue', () => ({}))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Download.vue', () => ({}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('../../components/cards/RegisterSchemaCard.vue', () => ({}))
jest.mock('../../components/register/RegisterFormFields.vue', () => ({}))
jest.mock('../../components/shared/ActionMenu.vue', () => ({}))
jest.mock('../../components/shared/ManagedBadge.vue', () => ({}))
jest.mock('../IndexRowDescription.vue', () => ({}))
jest.mock('../../services/registerOas.js', () => ({
	downloadRegisterOas: jest.fn(),
	viewRegisterOas: jest.fn(),
}))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationList: [] },
	navigationStore: {},
	registerStore: {
		pagination: { limit: 20 },
		refreshRegisterList: jest.fn(),
		setPagination: jest.fn(),
		setRegisterItem: jest.fn(),
		saveRegister: jest.fn(),
	},
	schemaStore: {},
}))

const { methods } = RegistersIndex

/**
 * A plain list instance: the methods bound to a state object.
 *
 * @param {object} state Extra data
 * @return {object} The instance
 */
function page(state = {}) {
	const vm = {
		selectedRegisters: [],
		isRefreshing: false,
		$refs: { indexPage: { setFormResult: jest.fn() } },
		$router: { push: jest.fn() },
		...state,
	}
	for (const [name, fn] of Object.entries(methods)) vm[name] = fn.bind(vm)
	return vm
}

const GITHUB = { id: 1, title: 'Remote', sourceType: 'github', registers: [5] }
const LOCAL = { id: 2, title: 'Here', sourceType: 'local', registers: [6] }

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	configurationStore.configurationList = [GITHUB, LOCAL, { id: 3 }]
})

afterEach(() => {
	console.error.mockRestore()
})

describe('RegistersIndex refresh, paging and selection', () => {
	it('flags the refresh while the list reloads', async () => {
		const vm = page()
		let during
		registerStore.refreshRegisterList.mockImplementation(async () => {
			during = vm.isRefreshing
		})

		await vm.handleRefresh()

		expect(during).toBe(true)
		expect(vm.isRefreshing).toBe(false)
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
	})

	it('clears the refresh flag when the reload fails', async () => {
		registerStore.refreshRegisterList.mockRejectedValue(new Error('down'))
		const vm = page()

		await expect(vm.handleRefresh()).rejects.toThrow('down')
		expect(vm.isRefreshing).toBe(false)
	})

	it('keeps the page size when the page changes', () => {
		page().onPageChanged(3)

		expect(registerStore.setPagination).toHaveBeenCalledWith(3, 20)
	})

	it('goes back to the first page when the page size changes', () => {
		page().onPageSizeChanged(50)

		expect(registerStore.setPagination).toHaveBeenCalledWith(1, 50)
	})

	it('records the selected rows', () => {
		const vm = page()

		vm.onSelect([5, 6])

		expect(vm.selectedRegisters).toEqual([5, 6])
	})
})

describe('RegistersIndex managing configuration', () => {
	it('finds the configuration that lists the register', () => {
		const vm = page()

		expect(vm.getManagingConfiguration({ id: 5 })).toBe(GITHUB)
		expect(vm.getManagingConfiguration({ id: 6 })).toBe(LOCAL)
		expect(vm.getManagingConfiguration({ id: 7 })).toBe(null)
		expect(vm.getManagingConfiguration({})).toBe(null)
		expect(vm.getManagingConfiguration(null)).toBe(null)
	})

	it('tells external from local management', () => {
		const vm = page()

		expect(vm.isManagedByExternalConfig({ id: 5 })).toBe(true)
		expect(vm.isManagedByLocalConfig({ id: 5 })).toBe(false)
		expect(vm.isManagedByExternalConfig({ id: 6 })).toBe(false)
		expect(vm.isManagedByLocalConfig({ id: 6 })).toBe(true)
		expect(vm.isManagedByLocalConfig({ id: 7 })).toBe(false)
	})

	it('marks a row by how it is managed', () => {
		const vm = page()

		expect(vm.getRowClass({ id: 5 })).toBe('viewTableRow--managed')
		expect(vm.getRowClass({ id: 6 })).toBe('viewTableRow--local')
		expect(vm.getRowClass({ id: 7 })).toBe('')
	})
})

describe('RegistersIndex form, navigation and API documentation', () => {
	it('saves the form with schema ids and reports success', async () => {
		registerStore.saveRegister.mockResolvedValue({})
		const vm = page()

		await vm.onSaveRegister({ title: 'R', schemas: [{ id: 11 }, 12] })

		expect(registerStore.saveRegister).toHaveBeenCalledWith({
			title: 'R',
			schemas: [11, 12],
		})
		expect(vm.$refs.indexPage.setFormResult).toHaveBeenCalledWith({ success: true })
	})

	it('hands a failed save back to the form', async () => {
		registerStore.saveRegister.mockRejectedValue(new Error('Title taken'))
		const vm = page()

		await vm.onSaveRegister({ title: 'R' })

		expect(registerStore.saveRegister).toHaveBeenCalledWith({ title: 'R', schemas: [] })
		expect(vm.$refs.indexPage.setFormResult).toHaveBeenCalledWith({
			error: 'Title taken',
		})
	})

	it('opens the detail page of a register', () => {
		const vm = page()

		vm.viewRegisterDetails({ id: 8, title: 'Eight' })

		expect(registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 8 })
		expect(vm.$router.push).toHaveBeenCalledWith('/registers/8')
	})

	it('opens the API documentation of one register', () => {
		page().viewOasDoc({ id: 4 })

		expect(viewRegisterOas).toHaveBeenCalledWith(4)
	})

	it('downloads the API specification of a register', async () => {
		downloadRegisterOas.mockResolvedValue(undefined)

		await page().downloadOas({ id: 4 })

		expect(downloadRegisterOas).toHaveBeenCalledWith({ id: 4 })
		expect(showError).not.toHaveBeenCalled()
	})

	it('reports a failed API specification download', async () => {
		downloadRegisterOas.mockRejectedValue(new Error('404'))

		await page().downloadOas({ id: 4 })

		expect(showError).toHaveBeenCalledWith('Failed to download API specification')
	})

	it('opens the documentation of all registers in a new tab', () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)

		page().openAllApisDoc()

		const apiUrl = `${window.location.origin}/apps/openregister/api/registers/oas`
		expect(open).toHaveBeenCalledWith(
			`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`,
			'_blank',
		)
		open.mockRestore()
	})
})
