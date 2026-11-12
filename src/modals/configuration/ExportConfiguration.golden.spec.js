import axios from '@nextcloud/axios'
import ExportConfiguration from './ExportConfiguration.vue'
import { configurationStore } from '../../store/store.js'

// The export action: the request it sends and the file it hands to the browser.

jest.mock('@nextcloud/axios', () => jest.fn())
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationItem: null },
	navigationStore: {},
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Export.vue', () => ({}))

/**
 * A bare modal context with every method bound and closing recorded.
 *
 * @param {boolean} includeObjects Whether objects are included
 * @return {object} The context
 */
function context(includeObjects) {
	const ctx = {
		...ExportConfiguration.data.call({}),
		includeObjects,
		error: 'old error',
		closed: 0,
	}
	for (const [name, method] of Object.entries(ExportConfiguration.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.closeModal = () => ctx.closed++
	return ctx
}

const ITEMS = {
	none: null,
	noId: { title: 'x' },
	numbered: { id: 12 },
	uuid: { id: 'a-b-c' },
}

const ANSWERS = {
	named: () =>
		Promise.resolve({
			data: '{"a":1}',
			headers: { 'content-disposition': 'attachment; filename="conf.json"' },
		}),
	bareName: () =>
		Promise.resolve({
			data: '{}',
			headers: { 'content-disposition': 'attachment; filename=plain.json' },
		}),
	unnamed: () => Promise.resolve({ data: '{}', headers: {} }),
	serverError: () =>
		Promise.reject({ response: { data: { error: 'No access' } }, message: 'x' }),
	plainError: () => Promise.reject(new Error('Network')),
	silentError: () => Promise.reject({}),
}

describe('ExportConfiguration.exportConfiguration', () => {
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

	for (const [itemName, item] of Object.entries(ITEMS)) {
		for (const [answerName, answer] of Object.entries(ANSWERS)) {
			for (const includeObjects of [false, true]) {
				it(`exports ${itemName}, answering ${answerName}, objects ${includeObjects}`, async () => {
					configurationStore.configurationItem = item
					const ctx = context(includeObjects)
					axios.mockImplementation((request) => {
						browser.push(['loading', ctx.loading, ctx.error])
						return answer(request)
					})
					await ctx.exportConfiguration()
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
