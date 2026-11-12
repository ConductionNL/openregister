import ViewObject from './ViewObject.vue'
import { objectStore } from '../../store/store.js'

// Saving the record modal: the payload it stores, the store calls and the state it leaves.

jest.mock('../../store/store.js', () => ({
	objectStore: {},
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
	dashboardStore: {},
	organisationStore: {},
}))
jest.mock('@codemirror/lang-json', () => ({
	json: jest.fn(),
	jsonParseLinter: jest.fn(),
}))
jest.mock('@nextcloud/dialogs', () => ({ showWarning: jest.fn() }))
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
 * A bare modal context with every method bound and the re-initialisation recorded.
 *
 * @param {Array} log Where calls are recorded
 * @param {object} state State to start from
 * @return {object} The context
 */
function context(log, state) {
	const ctx = { error: 'old error', success: null, isSaving: false, ...state }
	for (const [name, method] of Object.entries(ViewObject.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.initializeData = () => log.push(['initializeData', ctx.success])
	return ctx
}

const SCOPES = {
	scoped: { currentRegister: { id: 3 }, currentSchema: { id: 9 } },
	noRegister: { currentRegister: null, currentSchema: { id: 9 } },
	noSchema: { currentRegister: { id: 3 }, currentSchema: undefined },
}

const INPUTS = {
	form: {
		activeTab: 0,
		formData: { title: 'Form' },
		jsonData: '{"title":"Json"}',
	},
	otherTab: { activeTab: 2, formData: { title: 'Other' }, jsonData: '' },
	json: {
		activeTab: 1,
		formData: { title: 'Form' },
		jsonData: ' {"title":"Json"} ',
	},
	blankJson: { activeTab: 1, formData: {}, jsonData: '  \n ' },
	brokenJson: { activeTab: 1, formData: {}, jsonData: '{"title":' },
}

const SAVES = {
	saved: () => Promise.resolve({ id: 'new', title: 'Saved' }),
	empty: () => Promise.resolve(null),
	refused: () => Promise.reject(new Error('Validation failed')),
	silent: () => Promise.reject(new Error('')),
}

/**
 * The engine's own JSON.parse message for a text, so the snapshot does not
 * depend on the Node version.
 *
 * @param {string} text The text
 * @return {string} The message, or a marker when it parses
 */
function parseMessage(text) {
	try {
		JSON.parse(text)
		return '<parses>'
	} catch (e) {
		return e.message
	}
}

describe('ViewObject.saveObject', () => {
	let log

	beforeEach(() => {
		log = []
		jest.useFakeTimers()
	})

	afterEach(() => jest.useRealTimers())

	for (const [scopeName, scope] of Object.entries(SCOPES)) {
		for (const [inputName, input] of Object.entries(INPUTS)) {
			for (const [saveName, save] of Object.entries(SAVES)) {
				for (const refreshFails of [false, true]) {
					it(`saves ${scopeName}, ${inputName}, answering ${saveName}, refresh fails ${refreshFails}`, async () => {
						const ctx = context(log, { ...scope, ...input })
						objectStore.registerObjectType = (...args) =>
							log.push(['registerObjectType', ...args])
						objectStore.saveObject = (type, data) => {
							log.push([
								'saveObject',
								type,
								data,
								ctx.isSaving,
								ctx.error,
							])
							return save()
						}
						objectStore.setObjectItem = (item) =>
							log.push(['setObjectItem', item])
						objectStore.refreshObjectList = async (options) => {
							log.push(['refreshObjectList', options])
							if (refreshFails) {
								throw new Error('refresh down')
							}
						}
						objectStore.refetchSearchCollection = () =>
							log.push(['refetchSearchCollection'])
						await ctx.saveObject()
						const state = {
							error: String(ctx.error).replace(
								parseMessage(input.jsonData),
								'<parse error>',
							),
							success: ctx.success,
							isSaving: ctx.isSaving,
						}
						jest.advanceTimersByTime(1999)
						const before = ctx.success
						jest.advanceTimersByTime(1)
						expect({
							log,
							state,
							timer: [before, ctx.success],
						}).toMatchSnapshot()
					})
				}
			}
		}
	}
})
