/**
 * The record form's per-property helpers: which editor and input type a
 * property gets, its enum options, translation values, picking a file, its
 * display name, tooltip, bounds and step, and the value it shows. Runs on the
 * options object against a synthetic `this`, with the stores mocked.
 *
 * @spec exclude Client-side record form property helpers.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ViewObject from './ViewObject.vue'

jest.mock('../../store/store.js', () => ({
	objectStore: {
		objectItem: null,
		objectTypes: [],
		files: null,
		registerObjectType: jest.fn(),
		fetchFiles: jest.fn(),
		setObjectItem: jest.fn(),
	},
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	registerStore: { registerItem: null, setRegisterItem: jest.fn(), getRegister: jest.fn() },
	schemaStore: { schemaItem: null, schemaList: [], setSchemaItem: jest.fn(), getSchema: jest.fn() },
	dashboardStore: {},
	organisationStore: {},
}))
jest.mock('@codemirror/lang-json', () => ({ json: jest.fn(), jsonParseLinter: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({ showWarning: jest.fn() }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
jest.mock('vue-codemirror6', () => ({}))
jest.mock('vue-material-design-icons/Alert.vue', () => ({}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowRight.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/ExclamationThick.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FileOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FormatListChecks.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Tag.vue', () => ({}))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('../../components/i18n/TranslationFieldEditor.vue', () => ({}))
jest.mock('../../components/object-relations/ContactsTab.vue', () => ({}))
jest.mock('../../components/object-relations/DeckTab.vue', () => ({}))
jest.mock('../../components/object-relations/EmailsTab.vue', () => ({}))
jest.mock('../../components/object-relations/EventsTab.vue', () => ({}))
jest.mock('../../components/object-relations/RelationsTab.vue', () => ({}))
jest.mock('../../components/PaginationComponent.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))

const { objectStore } = jest.requireMock('../../store/store.js')

const { methods } = ViewObject

const SCHEMA = {
	properties: {
		name: { type: 'string', title: 'Name', description: 'The full name' },
		email: { type: 'string', format: 'email', description: 'Where we write' },
		age: { type: 'integer', minimum: 0, maximum: 130 },
		score: { type: 'number' },
		active: { type: 'boolean' },
		born: { type: 'string', format: 'date' },
		level: { type: 'string', enum: ['low', 'high'] },
		kind: { oneOf: [{ const: 'a', title: 'Alpha' }, { const: 'b' }] },
		photo: { type: 'file' },
		label: { type: 'string', translatable: true },
		fixed: { type: 'string', const: 'v1' },
		code: { type: 'string', immutable: true },
	},
}

/**
 * A form context over SCHEMA with the helpers bound to it.
 *
 * @param {object} state Extra state
 * @return {object} The context
 */
function form(state = {}) {
	const ctx = { currentSchema: SCHEMA, registerLanguages: ['nl', 'en'], formData: {}, updatePropertyValue: jest.fn(), ...state }
	for (const [name, fn] of Object.entries(methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	objectStore.objectItem = null
})

describe('ViewObject — the editor each property gets', () => {
	it('picks the component from the property', () => {
		const ctx = form()
		const component = (key) => ctx.getPropertyInputComponent(key)

		expect(component('active')).toBe('NcCheckboxRadioSwitch')
		expect(component('born')).toBe('NcDateTimePickerNative')
		expect(component('level')).toBe('NcSelect')
		expect(component('kind')).toBe('NcSelect')
		expect(component('photo')).toBe('file')
		expect(component('label')).toBe('TranslationFieldEditor')
		expect(component('name')).toBe('NcTextField')
		expect(component('free')).toBe('NcTextField')
		expect(form({ registerLanguages: [] }).getPropertyInputComponent('label')).toBe('NcTextField')
	})

	it('picks the HTML input type from the type and format', () => {
		const ctx = form()

		expect(['email', 'age', 'score', 'active', 'born', 'name', 'free'].map((key) => ctx.getPropertyInputType(key)))
			.toEqual(['email', 'number', 'number', 'checkbox', 'date', 'text', 'text'])
	})

	it('lists enum and oneOf options, and finds the current one', () => {
		const ctx = form()

		expect(ctx.enumOptionsFor('level')).toEqual([{ value: 'low', label: 'low' }, { value: 'high', label: 'high' }])
		expect(ctx.enumOptionsFor('kind')).toEqual([{ value: 'a', label: 'Alpha' }, { value: 'b', label: 'b' }])
		expect(ctx.enumOptionFor('kind', 'a')).toEqual({ value: 'a', label: 'Alpha' })
		expect(ctx.enumOptionFor('kind', 'z')).toBeNull()
		expect(ctx.enumOptionsFor('name')).toEqual([])
	})

	it('gives numbers their bounds and step', () => {
		const ctx = form()

		expect([ctx.getPropertyMinimum('age'), ctx.getPropertyMaximum('age')]).toEqual([0, 130])
		expect([ctx.getPropertyMinimum('name'), ctx.getPropertyMaximum('free')]).toEqual([undefined, undefined])
		expect(['age', 'score', 'name'].map((key) => ctx.getPropertyStep(key))).toEqual(['1', 'any', undefined])
	})
})

describe('ViewObject — values', () => {
	it('turns a stored value into per-language translations', () => {
		const ctx = form()

		expect(ctx.translationValueFor({ nl: 'Hallo' })).toEqual({ nl: 'Hallo' })
		expect(ctx.translationValueFor('Hallo')).toEqual({ nl: 'Hallo' })
		expect(ctx.translationValueFor(12)).toEqual({ nl: '12' })
		expect(ctx.translationValueFor('')).toEqual({})
		expect(ctx.translationValueFor(null)).toEqual({})
		expect(ctx.translationValueFor(['a'])).toEqual({ nl: 'a' })
		expect(form({ registerLanguages: [] }).translationValueFor('Hallo')).toEqual({})
	})

	it('stores a picked file as a data URI, and nothing when none was picked', async () => {
		const ctx = form()

		await ctx.onPropertyFilePicked('photo', { target: { files: [] } })
		expect(ctx.updatePropertyValue).not.toHaveBeenCalled()

		await ctx.onPropertyFilePicked('photo', { target: { files: [new File(['hi'], 'a.txt', { type: 'text/plain' })] } })
		expect(ctx.updatePropertyValue).toHaveBeenCalledWith('photo', 'data:text/plain;base64,aGk=')
	})

	it('shows the constant, then an edit, then the stored value', () => {
		expect(form().getDisplayValue('fixed', 'other')).toBe('v1')
		expect(form({ formData: { name: 'Edited' } }).getDisplayValue('name', 'Stored')).toBe('Edited')
		expect(form().getDisplayValue('name', 'Stored')).toBe('Stored')

		objectStore.objectItem = { id: 1 }
		expect(form().getDisplayValue('name', 'Default')).toBe('Default')
	})

	it('parses a date string the way the date picker expects', () => {
		const date = form().stringToDate('2026-03-15', 'date')

		expect(date).toBeInstanceOf(Date)
		expect(date.getFullYear()).toBe(2026)
	})
})

describe('ViewObject — labels and editability', () => {
	it('names a property by its title, else its key', () => {
		expect(form().getPropertyDisplayName('name')).toBe('Name')
		expect(form().getPropertyDisplayName('free')).toBe('free')
	})

	it('explains a property with its title and description, its description alone, or its key', () => {
		const ctx = form()

		expect(ctx.getPropertyTooltip('name')).toBe('Name: The full name')
		expect(ctx.getPropertyTooltip('email')).toBe('Where we write')
		expect(ctx.getPropertyTooltip('age')).toBe('Property: age')
	})

	it('locks a constant and a filled immutable property, with a warning saying why', () => {
		const ctx = form()

		expect(ctx.isPropertyEditable('free', 'x')).toBe(true)
		expect(ctx.isPropertyEditable('fixed', 'v1')).toBe(false)
		expect(ctx.isPropertyEditable('code', 'C-1')).toBe(false)
		expect(['', null, undefined].map((value) => ctx.isPropertyEditable('code', value))).toEqual([true, true, true])

		expect(ctx.getEditabilityWarning('fixed', 'v1')).toContain('constant and must always be \'v1\'')
		expect(ctx.getEditabilityWarning('code', 'C-1')).toContain('Current value: \'C-1\'')
		expect(ctx.getEditabilityWarning('code', '')).toBeNull()
		expect(ctx.getEditabilityWarning('name', 'x')).toBeNull()
	})
})

describe('ViewObject — keeping the form in step', () => {
	it('re-initialises when the store changes, but not when it is cleared', () => {
		const ctx = { initializeData: jest.fn() }

		ViewObject.watch.objectStore.handler.call(ctx, { objectItem: {} })
		ViewObject.watch.objectStore.handler.call(ctx, null)

		expect(ctx.initializeData).toHaveBeenCalledTimes(1)
	})

	it('copies valid JSON typed by the user into the form, ignoring its own updates and invalid JSON', () => {
		const ctx = { isInternalUpdate: false, isValidJson: (value) => value.startsWith('{'), updateFormFromJson: jest.fn() }

		ViewObject.watch.jsonData.handler.call(ctx, '{"a":1}')
		ViewObject.watch.jsonData.handler.call(ctx, '{"a"')
		ViewObject.watch.jsonData.handler.call({ ...ctx, isInternalUpdate: true }, '{"a":1}')
		ViewObject.watch.jsonData.handler.call(ctx, 'nope')

		expect(ctx.updateFormFromJson).toHaveBeenCalledTimes(2)
	})

	it('tells string properties apart', () => {
		const ctx = form()

		expect(['name', 'age', 'free'].map((key) => ctx.isStringProperty(key))).toEqual([true, false, false])
	})

	it('focuses and selects the value input of the property clicked', () => {
		const input = { focus: jest.fn(), select: jest.fn() }
		const ctx = {
			selectedProperty: null,
			$refs: { propertyValueInput: [{ $el: { querySelector: () => input } }] },
			$nextTick: (fn) => fn(),
		}

		methods.selectProperty.call(ctx, 'name')

		expect(ctx.selectedProperty).toBe('name')
		expect(input.focus).toHaveBeenCalled()
		expect(input.select).toHaveBeenCalled()
	})

	it('selects the property even when there is no input to focus', () => {
		const ctx = { selectedProperty: null, $refs: { propertyValueInput: [{ $el: { querySelector: () => null } }] }, $nextTick: (fn) => fn() }

		methods.selectProperty.call(ctx, 'age')
		methods.selectProperty.call({ ...ctx, $refs: {} }, 'age')

		expect(ctx.selectedProperty).toBe('age')
	})

	it('opens with the resolved schema, or the listed one when the fetch fails', async () => {
		const { schemaStore } = jest.requireMock('../../store/store.js')
		schemaStore.schemaItem = { id: 9 }
		schemaStore.getSchema.mockRejectedValueOnce(new Error('down'))
		const ctx = { initializeData: jest.fn(), syncLiveSubscription: jest.fn(), loadRegisterLanguages: jest.fn() }
		for (const name of Object.keys(methods)) {
			if (!(name in ctx)) ctx[name] = jest.fn()
		}

		await ViewObject.mounted.call(ctx)

		expect(schemaStore.getSchema).toHaveBeenCalledWith(9, { setItem: true })
		expect(ctx.initializeData).toHaveBeenCalledTimes(1)
		schemaStore.schemaItem = null
	})
})
