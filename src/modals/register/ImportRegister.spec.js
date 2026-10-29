import ImportRegister from './ImportRegister.vue'

// The import modal names the kind of file the user picked.

jest.mock('../../store/store.js', () => ({}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({}))
jest.mock('vue-material-design-icons/Import.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))

const ctx = { getFileExtension: ImportRegister.methods.getFileExtension }

describe('ImportRegister.getFileType', () => {
	it.each([
		['register.json', 'JSON Configuration'],
		['sheet.xlsx', 'Excel Spreadsheet'],
		['sheet.xls', 'Excel Spreadsheet'],
		['rows.csv', 'CSV Data'],
		['notes.txt', 'Unknown'],
		['noextension', 'Unknown'],
		['odd.constructor', 'Unknown'],
	])('%s is %s', (filename, label) => {
		expect(ImportRegister.methods.getFileType.call(ctx, filename)).toBe(label)
	})
})
