/**
 * Golden record of the record modal's property edits: the converted value
 * it stores, the debounced change notice and the toast it shows.
 *
 * @spec exclude Golden record of client-side record edit notices.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ViewObject from './ViewObject.vue'

const mockLog = []

jest.mock('../../store/store.js', () => ({
	objectStore: { objectItem: null },
	navigationStore: {},
	registerStore: { registerItem: null },
	schemaStore: {
		schemaItem: null,
		schemaList: [],
		getSchema: jest.fn(),
	},
	dashboardStore: {},
	organisationStore: {},
}))
jest.mock('@codemirror/lang-json', () => ({
	json: jest.fn(),
	jsonParseLinter: jest.fn(),
}))
jest.mock('@nextcloud/dialogs', () => ({
	showWarning: (text) => mockLog.push(['warning', text]),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s) => s,
	translatePlural: (app, s) => s,
}))
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

/**
 * A modal context with the modal's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function context(state) {
	const ctx = {
		formData: {},
		objectProperties: [],
		pendingNotifications: {},
		propertyChangeDebounceTimers: {},
		currentSchema: { properties: { count: { type: 'integer' }, name: { type: 'string' } } },
		...state,
	}
	for (const [name, method] of Object.entries(ViewObject.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.useFakeTimers()
	document.body.innerHTML = ''
})
afterEach(() => jest.useRealTimers())

describe('ViewObject property changes (golden)', () => {
	it('stores the converted value and notices the change once, after 500 ms', () => {
		const shown = []
		const ctx = context({ objectProperties: [['count', 1], ['name', 'Old']] })
		ctx.showPropertyChangeNotification = (...args) => shown.push(args)
		ctx.updatePropertyValue('count', '2')
		ctx.updatePropertyValue('count', '3')
		ctx.updatePropertyValue('name', 'Old')
		const before = JSON.stringify({ formData: ctx.formData, pending: ctx.pendingNotifications, timers: Object.keys(ctx.propertyChangeDebounceTimers) })
		jest.advanceTimersByTime(499)
		const early = shown.length
		jest.advanceTimersByTime(1)
		expect({
			before,
			early,
			shown,
			pending: ctx.pendingNotifications,
			timers: Object.keys(ctx.propertyChangeDebounceTimers),
		}).toMatchSnapshot()
	})

	it('compares with the edited value once there is one', () => {
		const shown = []
		const ctx = context({ formData: { name: 'Edited' }, objectProperties: [['name', 'Stored']] })
		ctx.showPropertyChangeNotification = (...args) => shown.push(args)
		ctx.updatePropertyValue('name', 'Edited')
		ctx.updatePropertyValue('free', 'x')
		jest.advanceTimersByTime(500)
		expect({ formData: ctx.formData, shown }).toMatchSnapshot()
	})

	it('shows one toast at a time and removes it after 3 seconds', () => {
		const ctx = context({})
		ctx.showPropertyChangeNotification('a', 1, 2)
		ctx.showPropertyChangeNotification('b', 'x', 'y')
		const toasts = document.querySelectorAll('.property-change-toast')
		const shown = {
			count: toasts.length,
			text: toasts[0]?.textContent,
			style: toasts[0]?.style.cssText,
		}
		jest.advanceTimersByTime(3000)
		expect({ shown, left: document.querySelectorAll('.property-change-toast').length }).toMatchSnapshot()
	})
})
