/**
 * The export dialog's own state: the titles it shows and what closing resets.
 *
 * @spec exclude Unit tests of client-side export dialog state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ExportRegister from './ExportRegister.vue'
import { navigationStore, registerStore, schemaStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => jest.fn())
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	registerStore: { registerItem: null },
	schemaStore: { schemaItem: null },
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Export.vue', () => ({}))

/**
 * A modal context with the modal's methods and computeds bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function context(state = {}) {
	const ctx = { ...ExportRegister.data.call({}), ...state }
	for (const [name, method] of Object.entries(ExportRegister.methods)) {
		ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(ExportRegister.computed)) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx) })
	}
	return ctx
}

describe('ExportRegister state', () => {
	beforeEach(() => {
		navigationStore.setModal.mockClear()
		registerStore.registerItem = null
		schemaStore.schemaItem = null
	})

	it('starts with Excel selected and both formats offered', () => {
		const ctx = context()

		expect(ctx.exportFormat).toBe('excel')
		expect(ctx.exportFormats.map((format) => format.value)).toEqual(['excel', 'csv'])
		expect(ctx.loading).toBe(false)
		expect(ctx.error).toBeNull()
	})

	it('shows the register and schema titles', () => {
		registerStore.registerItem = { title: 'Burgerzaken' }
		schemaStore.schemaItem = { title: 'Persoon' }
		const ctx = context()

		expect(ctx.registerTitle).toBe('Burgerzaken')
		expect(ctx.schemaTitle).toBe('Persoon')
	})

	it('shows Unknown for a missing or untitled register and schema', () => {
		const ctx = context()
		expect(ctx.registerTitle).toBe('Unknown')
		expect(ctx.schemaTitle).toBe('Unknown')

		registerStore.registerItem = { title: '' }
		schemaStore.schemaItem = { id: 3 }
		expect(ctx.registerTitle).toBe('Unknown')
		expect(ctx.schemaTitle).toBe('Unknown')
	})

	it('closes the modal and resets the form', () => {
		const ctx = context({ loading: true, error: 'failed', exportFormat: 'csv' })

		ctx.closeModal()

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(ctx.loading).toBe(false)
		expect(ctx.error).toBeNull()
		expect(ctx.exportFormat).toBe('excel')
	})
})
