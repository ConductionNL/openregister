/**
 * Golden record of the record modal's field rows, blank object and close
 * reset, for new and stored objects.
 *
 * @spec exclude Golden record of client-side record modal state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ViewObject from './ViewObject.vue'
import { navigationStore, objectStore } from '../../store/store.js'

const mockLog = []

jest.mock('../../store/store.js', () => ({
	objectStore: { objectItem: null },
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
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

const SCHEMA = {
	id: 9,
	properties: {
		title: { type: 'string', default: 'Untitled' },
		count: { type: 'integer' },
		tags: { type: 'array' },
		meta: { type: 'object' },
		flag: { type: 'boolean' },
		other: { type: 'unknown-kind' },
	},
}

/**
 * The field rows the modal shows.
 *
 * @param {object|null} item The stored object, or null for a new one
 * @param {object|undefined} schema The current schema
 * @return {string} The rows, serialised in order
 */
function rows(item, schema) {
	objectStore.objectItem = item
	return JSON.stringify(ViewObject.computed.objectProperties.call({ currentSchema: schema }))
}

describe('ViewObject state (golden)', () => {
	it.each([
		['a new object without a schema', null, undefined],
		['a new object without schema properties', null, { id: 1 }],
		['a new object', null, SCHEMA],
		['a stored object', { '@self': { id: 'x' }, id: 'x', title: 'Kept', extra: 1 }, SCHEMA],
		['a stored object without a schema', { id: 'x', title: 'Kept' }, undefined],
	])('lists the rows of %s', (name, item, schema) => {
		expect(rows(item, schema)).toMatchSnapshot()
	})

	it.each([
		['with a register and schema', { currentRegister: { id: 3 }, currentSchema: SCHEMA }],
		['without them', {}],
	])('builds a blank object %s', (name, state) => {
		expect(JSON.stringify(ViewObject.methods.blankObjectData.call(state))).toMatchSnapshot()
	})

	it('resets everything on close', () => {
		jest.useFakeTimers()
		const cleared = []
		const realClear = global.clearTimeout
		global.clearTimeout = (timer) => cleared.push(timer)
		objectStore.availableRegistersForNewObject = ['r']
		objectStore.availableSchemasForNewObject = ['s']
		const ctx = {
			isUpdated: true,
			registerTitle: 'R',
			schemaTitle: 'S',
			activeTab: 2,
			selectedAttachments: [1],
			activeAttachment: 1,
			success: true,
			error: 'e',
			isCopied: true,
			selectedRegisterForNewObject: 1,
			selectedSchemaForNewObject: 2,
			registerSchemaSelectionConfirmed: true,
			propertyChangeDebounceTimers: { a: 'timer-a', b: null, c: 'timer-c' },
			pendingNotifications: { a: 1 },
			closeModalTimeout: 'close-timer',
		}
		ViewObject.methods.closeModal.call(ctx)
		global.clearTimeout = realClear
		jest.useRealTimers()
		expect({
			ctx,
			cleared,
			registers: objectStore.availableRegistersForNewObject,
			schemas: objectStore.availableSchemasForNewObject,
			modal: navigationStore.setModal.mock.calls,
			dialog: navigationStore.setDialog.mock.calls,
		}).toMatchSnapshot()
	})
})
