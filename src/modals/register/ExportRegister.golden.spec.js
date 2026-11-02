import axios from '@nextcloud/axios'
import ExportRegister from './ExportRegister.vue'
import { registerStore, schemaStore } from '../../store/store.js'

// The export action: the request it sends and the file it hands to the browser.

jest.mock('@nextcloud/axios', () => jest.fn())
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	registerStore: { registerItem: null },
	schemaStore: { schemaItem: null },
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Export.vue', () => ({}))

const SCOPES = {
	slugs: [
		{ id: 1, slug: 'reg' },
		{ id: 2, slug: 'sch' },
	],
	ids: [{ id: 3 }, { id: 'four' }],
	noRegisterId: [{ slug: 'reg' }, { id: 2 }],
	noSchema: [{ id: 1 }, null],
}

const ANSWERS = {
	named: () =>
		Promise.resolve({
			data: 'xlsx-bytes',
			headers: {
				'content-type': 'application/vnd.ms-excel',
				'content-disposition': 'attachment; filename="export.xlsx"',
			},
		}),
	unnamed: () =>
		Promise.resolve({
			data: 'csv-bytes',
			headers: { 'content-type': 'text/csv' },
		}),
	serverError: () =>
		Promise.reject({ response: { data: { error: 'No access' } }, message: 'x' }),
	oddDisposition: () =>
		Promise.resolve({
			data: 'bytes',
			headers: { 'content-type': 'text/csv', 'content-disposition': 'inline' },
		}),
	plainError: () => Promise.reject(new Error('Network')),
	silentError: () => Promise.reject({}),
}

/**
 * A bare modal context with the modal's methods that records closing.
 *
 * @param {string} exportFormat The chosen format
 * @return {object} The context
 */
function context(exportFormat) {
	const ctx = { ...ExportRegister.data.call({}), exportFormat, closed: 0 }
	for (const [name, method] of Object.entries(ExportRegister.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.closeModal = () => ctx.closed++
	return ctx
}

describe('ExportRegister.exportObjects', () => {
	let browser

	beforeEach(() => {
		jest.clearAllMocks()
		jest.useFakeTimers().setSystemTime(new Date('2026-03-04T05:06:07Z'))
		browser = []
		const link = {
			click: () => browser.push(['click', link.href, link.download]),
		}
		jest.spyOn(document, 'createElement').mockImplementation((tag) => {
			browser.push(['create', tag])
			return link
		})
		jest.spyOn(document.body, 'appendChild').mockImplementation(() =>
			browser.push(['append']),
		)
		jest.spyOn(document.body, 'removeChild').mockImplementation(() =>
			browser.push(['remove']),
		)
		window.URL.createObjectURL = (blob) => {
			browser.push(['blob', blob.type, blob.size])
			return 'blob:url'
		}
		window.URL.revokeObjectURL = (url) => browser.push(['revoke', url])
	})

	afterEach(() => {
		jest.useRealTimers()
		jest.restoreAllMocks()
	})

	for (const [scopeName, [register, schema]] of Object.entries(SCOPES)) {
		for (const [answerName, answer] of Object.entries(ANSWERS)) {
			for (const format of ['excel', 'csv']) {
				it(`exports ${scopeName} as ${format}, answering ${answerName}`, async () => {
					registerStore.registerItem = register
					schemaStore.schemaItem = schema
					axios.mockImplementation(answer)
					const ctx = context(format)
					await ctx.exportObjects()
					expect({
						requests: axios.mock.calls,
						browser,
						state: JSON.stringify(ctx),
					}).toMatchSnapshot()
				})
			}
		}
	}
})
