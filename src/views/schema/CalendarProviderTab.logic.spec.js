/**
 * The calendar provider tab's own logic: which schema properties it offers as
 * date and text fields, when the form counts as valid, how a stored provider
 * is read back with its defaults, and that a failed save still ends saving.
 * Each computed property and method runs against a plain `this`.
 *
 * @spec exclude Client-side form logic of the calendar provider tab.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import CalendarProviderTab from './CalendarProviderTab.vue'
import { schemaStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/Circle.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/SaveButton.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../store/store.js', () => ({ __esModule: true, schemaStore: { saveSchema: jest.fn() } }))

const { computed, methods } = CalendarProviderTab

/**
 * A plain tab instance for a schema, with its data and bound methods.
 *
 * @param {object|null} schema The schema prop
 * @return {object} The instance
 */
function tab(schema) {
	const vm = { schema, ...CalendarProviderTab.data() }
	for (const [name, fn] of Object.entries(methods)) vm[name] = fn.bind(vm)
	return vm
}

const SCHEMA = {
	id: 7,
	properties: {
		start: { type: 'string', format: 'date-time' },
		day: { type: 'string', format: 'date' },
		legacy: { type: 'date' },
		place: { type: 'string' },
		count: { type: 'integer' },
		odd: null,
	},
}

describe('CalendarProviderTab property options', () => {
	it('offers date, date-time and legacy date properties as dates, in schema order', () => {
		expect(computed.datePropertyOptions.call(tab(SCHEMA))).toEqual(['start', 'day', 'legacy'])
	})

	it('offers only string properties as text fields', () => {
		expect(computed.stringPropertyOptions.call(tab(SCHEMA))).toEqual(['start', 'day', 'place'])
	})

	it('lists every property name', () => {
		expect(computed.propertyNames.call(tab(SCHEMA))).toEqual(['start', 'day', 'legacy', 'place', 'count', 'odd'])
	})

	it.each([
		['no schema', null],
		['a schema without properties', { id: 1 }],
	])('offers nothing for %s', (label, schema) => {
		const vm = tab(schema)

		expect(computed.propertyNames.call(vm)).toEqual([])
		expect(computed.datePropertyOptions.call(vm)).toEqual([])
		expect(computed.stringPropertyOptions.call(vm)).toEqual([])
	})
})

describe('CalendarProviderTab validity', () => {
	it.each([
		['switched off, whatever is missing', { enabled: false }, true],
		['switched on without a start field', { enabled: true, titleTemplate: '{title}' }, false],
		['switched on without a title template', { enabled: true, dtstart: 'start', titleTemplate: '' }, false],
		['switched on with start field and title', { enabled: true, dtstart: 'start', titleTemplate: '{title}' }, true],
	])('is %s', (label, config, valid) => {
		const vm = tab(SCHEMA)
		vm.localConfig = { ...vm.localConfig, dtstart: null, ...config }

		expect(computed.isValid.call(vm)).toBe(valid)
	})
})

describe('CalendarProviderTab loading and saving', () => {
	it('fills the defaults for a schema without a stored provider', () => {
		const vm = tab({ id: 1 })

		vm.loadConfig(vm.schema)

		expect(vm.localConfig).toEqual({
			enabled: false,
			displayName: '',
			color: '#0082C9',
			dtstart: null,
			dtend: null,
			titleTemplate: '',
			descriptionTemplate: '',
			locationField: null,
			allDay: null,
		})
	})

	it('keeps an explicit all-day false rather than resetting it', () => {
		const vm = tab(null)

		vm.loadConfig({ configuration: { calendarProvider: { enabled: true, allDay: false, color: '#ff0000' } } })

		expect(vm.localConfig.allDay).toBe(false)
		expect(vm.localConfig.enabled).toBe(true)
		expect(vm.localConfig.color).toBe('#ff0000')
	})

	it('reloads the provider whenever the schema prop changes', () => {
		const vm = tab(null)

		CalendarProviderTab.watch.schema.handler.call(vm, {
			configuration: { calendarProvider: { displayName: 'Meetings' } },
		})
		expect(vm.localConfig.displayName).toBe('Meetings')

		CalendarProviderTab.watch.schema.handler.call(vm, null)
		expect(vm.localConfig.displayName).toBe('Meetings')
	})

	it('logs a failed save and leaves the saving state', async () => {
		jest.spyOn(console, 'error').mockImplementation(() => {})
		const failure = new Error('conflict')
		schemaStore.saveSchema.mockRejectedValueOnce(failure)
		const vm = tab({ id: 7, configuration: { other: 1 } })
		vm.localConfig.enabled = true

		await vm.save()

		expect(schemaStore.saveSchema).toHaveBeenCalledWith({
			id: 7,
			configuration: { other: 1, calendarProvider: expect.objectContaining({ enabled: true }) },
		})
		expect(vm.saving).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Failed to save calendar provider config:', failure)
		console.error.mockRestore()
	})
})
