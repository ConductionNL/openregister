/**
 * The schema property modal when the property's type changes: which
 * type-specific fields reset. Also the register and schema option labels and
 * the help-text annotation. Runs against the component's options with stub
 * stores.
 *
 * @spec exclude Client-side schema property form handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditSchemaProperty from './EditSchemaProperty.vue'

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { setModal: jest.fn() },
	registerStore: {},
	schemaStore: { schemaList: [], setSchemaPropertyKey: jest.fn(), schemaItem: null, schemaPropertyKey: null },
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (p) => p }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('../../composables/registerSchemaLists.js', () => ({ ensureRegisterAndSchemaLists: jest.fn() }))

const { registerStore, schemaStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = EditSchemaProperty

const onTypeChange = EditSchemaProperty.watch.schemaProperty.handler

/**
 * A form state whose type-specific fields all hold a value.
 *
 * @return {object} The context
 */
function filled() {
	return {
		properties: {
			default: 'x',
			minimum: 5,
			maximum: 9,
			multipleOf: 2,
			exclusiveMin: 1,
			exclusiveMax: 8,
			minItems: 3,
			maxItems: 7,
		},
	}
}

describe('EditSchemaProperty — changing the type', () => {
	it('leaves every field alone while the type stays the same', () => {
		const ctx = filled()

		onTypeChange.call(ctx, { type: 'array' }, { type: 'array' })

		expect(ctx.properties).toEqual(filled().properties)
	})

	it('turns the default into a boolean when switching to boolean, and back to text after', () => {
		const ctx = filled()

		onTypeChange.call(ctx, { type: 'boolean' }, { type: 'string' })
		expect(ctx.properties.default).toBe(false)

		onTypeChange.call(ctx, { type: 'string' }, { type: 'boolean' })
		expect(ctx.properties.default).toBe('')
	})

	it('keeps a text default when switching between two non-boolean types', () => {
		const ctx = filled()

		onTypeChange.call(ctx, { type: 'number' }, { type: 'string' })

		expect(ctx.properties.default).toBe('x')
	})

	it('zeroes the numeric bounds when switching to integer', () => {
		const ctx = filled()

		onTypeChange.call(ctx, { type: 'integer' }, { type: 'string' })

		expect(ctx.properties).toMatchObject({ minimum: 0, maximum: 0, multipleOf: 0, exclusiveMin: 0, exclusiveMax: 0 })
	})

	it('keeps the numeric bounds when switching to number', () => {
		const ctx = filled()

		onTypeChange.call(ctx, { type: 'number' }, { type: 'integer' })

		expect(ctx.properties).toMatchObject({ minimum: 5, maximum: 9, multipleOf: 2, exclusiveMin: 1, exclusiveMax: 8 })
	})

	it('zeroes the item counts when leaving array, and keeps them on array', () => {
		const leaving = filled()
		onTypeChange.call(leaving, { type: 'string' }, { type: 'array' })
		expect(leaving.properties).toMatchObject({ minItems: 0, maxItems: 0 })

		const arriving = filled()
		onTypeChange.call(arriving, { type: 'array' }, { type: 'string' })
		expect(arriving.properties).toMatchObject({ minItems: 3, maxItems: 7 })
	})
})

describe('EditSchemaProperty — option labels', () => {
	it('labels a register and a schema by title, else name, else id', () => {
		registerStore.registerList = [{ id: 1, title: 'Reg' }, { id: 2, name: 'reg-two' }, { id: 3 }]
		schemaStore.schemaList = [{ id: 4, title: 'Sch' }, { id: 5, name: 'sch-five' }, { id: 6 }]

		expect(computed.availableRegisters.call({})).toEqual([
			{ id: 1, label: 'Reg' },
			{ id: 2, label: 'reg-two' },
			{ id: 3, label: 3 },
		])
		expect(computed.availableSchemas.call({})).toEqual([
			{ id: 4, label: 'Sch' },
			{ id: 5, label: 'sch-five' },
			{ id: 6, label: 6 },
		])
	})
})

describe('EditSchemaProperty — help text', () => {
	it('stores the trimmed help text per language, and nothing without any', () => {
		const vm = EditSchemaProperty.data.call({})
		vm.codedConfig = { scheme: '' }

		vm.helpTextNl = '  Vul in  '
		vm.helpTextEn = ''
		expect(methods.codeListAnnotations.call(vm)['x-openregister-help']).toEqual({ nl: 'Vul in' })

		vm.helpTextEn = ' Fill in '
		expect(methods.codeListAnnotations.call(vm)['x-openregister-help']).toEqual({ nl: 'Vul in', en: 'Fill in' })

		vm.helpTextNl = '   '
		vm.helpTextEn = ''
		expect(methods.codeListAnnotations.call(vm)['x-openregister-help']).toBeUndefined()
	})
})
