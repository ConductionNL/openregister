/**
 * The export dialog offers the open formats and names a download after the
 * format chosen when the server gives no file name
 * (export-open-formats-and-public-download, task 3.1).
 *
 * @spec openspec/changes/export-open-formats-and-public-download/tasks.md#3.1
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ExportRegister, { exportExtension } from './ExportRegister.vue'

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
 * The dialog's exportFileName() with a chosen format.
 *
 * @param {string} format The format chosen
 * @param {object} headers The response headers
 * @return {string} The file name
 */
function fileName(format, headers = {}) {
	const ctx = { ...ExportRegister.data.call({}), exportFormat: format }
	return ExportRegister.methods.exportFileName.call(ctx, { headers }, ['burgerzaken', 'persoon'])
}

describe('ExportRegister formats', () => {
	it('offers CSV, TSV, JSON, XML and Excel, each by its own name', () => {
		const formats = ExportRegister.data.call({}).exportFormats

		expect(formats).toEqual([
			{ label: 'Excel', value: 'excel' },
			{ label: 'CSV', value: 'csv' },
			{ label: 'TSV', value: 'tsv' },
			{ label: 'JSON', value: 'json' },
			{ label: 'XML', value: 'xml' },
		])
	})

	it('names a download without a server file name after the format chosen', () => {
		const today = new Date().toISOString().split('T')[0]

		expect(fileName('tsv')).toBe(`burgerzaken_persoon_${today}.tsv`)
		expect(fileName('xml')).toBe(`burgerzaken_persoon_${today}.xml`)
		expect(fileName('json')).toBe(`burgerzaken_persoon_${today}.json`)
		expect(fileName('csv')).toBe(`burgerzaken_persoon_${today}.csv`)
		expect(fileName('excel')).toBe(`burgerzaken_persoon_${today}.xlsx`)
	})

	it('keeps the file name the server gives', () => {
		expect(fileName('xml', { 'content-disposition': 'attachment; filename="persoon-2026.xml"' })).toBe('persoon-2026.xml')
	})

	it('uses Excel for a format it does not know, as the server does', () => {
		expect(exportExtension('ods-unknown')).toBe('xlsx')
	})
})
