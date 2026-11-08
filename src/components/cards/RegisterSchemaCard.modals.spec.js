/**
 * The register card's menu entries that open a register modal: they select
 * the card's register (and the row's schema) before opening the modal.
 *
 * @spec exclude Client-side modal dispatch of the register card.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
const ICONS = [
	'Api',
	'CheckCircle',
	'ChevronDown',
	'ChevronUp',
	'CloudUploadOutline',
	'CogOutline',
	'DatabaseOutline',
	'DeleteOutline',
	'DotsHorizontal',
	'Download',
	'Export',
	'FileTreeOutline',
	'InformationOutline',
	'Pencil',
	'Sync',
	'Table',
	'TrashCanOutline',
	'Upload',
]
for (const icon of ICONS) {
	jest.mock(`vue-material-design-icons/${icon}.vue`, () => ({}))
}

const mockCalls = []

jest.mock('@conduction/nextcloud-vue', () => ({ CnFormDialog: {} }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('../../store/store.js', () => {
	const record =
		(name) =>
		(...args) =>
			mockCalls.push([name, ...args])
	return {
		configurationStore: {},
		dashboardStore: {},
		navigationStore: { setModal: record('setModal') },
		registerStore: { setRegisterItem: record('setRegisterItem') },
		schemaStore: { setSchemaItem: record('setSchemaItem') },
	}
})

const RegisterSchemaCard = require('./RegisterSchemaCard.vue').default

const register = { id: 7, title: 'Register' }
const schema = { id: 3, title: 'Schema' }

describe('RegisterSchemaCard modals', () => {
	beforeEach(() => {
		mockCalls.length = 0
	})

	it('selects the register before opening a register modal', () => {
		RegisterSchemaCard.methods.openRegisterModal.call(
			{ item: register },
			'publishRegister',
		)
		expect(mockCalls).toEqual([
			['setRegisterItem', register],
			['setModal', 'publishRegister'],
		])
	})

	it('selects the register and schema before opening a schema modal', () => {
		RegisterSchemaCard.methods.openSchemaModal.call(
			{ item: register },
			schema,
			'exportRegister',
		)
		expect(mockCalls).toEqual([
			['setRegisterItem', register],
			['setSchemaItem', schema],
			['setModal', 'exportRegister'],
		])
	})
})
