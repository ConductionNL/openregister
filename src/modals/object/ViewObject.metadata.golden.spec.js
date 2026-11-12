import ViewObject from './ViewObject.vue'
import { objectStore } from '../../store/store.js'

// The metadata rows of the record modal, for every shape of record.

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

const SELVES = {
	missing: undefined,
	empty: {},
	full: {
		version: '1.2.0',
		created: '2026-01-02T03:04:05Z',
		updated: '2026-02-03T04:05:06Z',
		validation: true,
		owner: 'alice',
		application: 'app-1',
		organisation: 'org-1',
	},
	invalid: { validation: false, created: 'not a date', updated: '' },
	notValidated: { validation: null, version: 0 },
	validationObject: { validation: { errors: [] } },
}

const RECORDS = {
	withId: (self) => ({ id: 'obj-1', '@self': self }),
	withoutId: (self) => ({ id: '', '@self': self }),
}

describe('ViewObject.metadataProperties', () => {
	beforeEach(() => {
		jest.spyOn(Date.prototype, 'toLocaleString').mockImplementation(function () {
			return `local:${this.getTime()}`
		})
	})

	afterEach(() => jest.restoreAllMocks())

	for (const [selfName, self] of Object.entries(SELVES)) {
		for (const [recordName, record] of Object.entries(RECORDS)) {
			for (const titles of [
				['Reg', 'Sch'],
				['', null],
			]) {
				for (const source of ['formData', 'store']) {
					it(`lists ${recordName} with @self ${selfName}, titles ${titles[0] || 'none'}, from ${source}`, () => {
						const obj = record(self)
						objectStore.objectItem =
							source === 'store' ? obj : { id: 'ignored' }
						const ctx = {
							formData: source === 'formData' ? obj : null,
							registerTitle: titles[0],
							schemaTitle: titles[1],
						}
						let rows
						try {
							rows = ViewObject.computed.metadataProperties.call(ctx)
						} catch (e) {
							rows = `throws ${e.constructor.name}: ${e.message}`
						}
						expect(rows).toMatchSnapshot()
					})
				}
			}
		}
	}

	it('lists nothing without a record', () => {
		objectStore.objectItem = null
		expect(
			ViewObject.computed.metadataProperties.call({ formData: null }),
		).toEqual([])
	})
})
