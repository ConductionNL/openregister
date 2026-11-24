/**
 * Golden record of the objects tab's own requests: listing the objects linked
 * to the open message, unlinking one, and uploading a dropped attachment to an
 * object, with what each shows and emits.
 *
 * @spec exclude Golden record of client-side mail sidebar requests.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ObjectsTab from './ObjectsTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), delete: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('../icons.js', () => ({ schemaIconComponent: () => ({}) }))
jest.mock('../../dialogs/mail/RemoveConnectionDialog.vue', () => ({}))

/**
 * A tab context with the tab's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function context(state) {
	const emitted = []
	const ctx = { $emit: (...args) => emitted.push(args), emitted, ...state }
	for (const [name, method] of Object.entries(ObjectsTab.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})
afterEach(() => jest.restoreAllMocks())

describe('ObjectsTab requests (golden)', () => {
	it.each([
		['lists the linked objects', 'a1', 7, () => Promise.resolve({ data: { results: [{ uuid: 'o1' }] } })],
		['lists nothing on an answer without results', 'a1', 7, () => Promise.resolve({ data: {} })],
		['clears the list on a failure', 'a1', 7, () => Promise.reject(new Error('offline'))],
		['asks nothing without a message', 'a1', null, () => Promise.resolve({})],
	])('%s', async (name, accountId, messageId, answer) => {
		axios.get.mockImplementation(answer)
		const ctx = context({ accountId, messageId, objects: ['stale'], loading: false })
		await ctx.loadObjects()
		expect({
			requests: axios.get.mock.calls,
			objects: ctx.objects,
			loading: ctx.loading,
			emitted: ctx.emitted,
		}).toMatchSnapshot()
	})

	it.each([
		['unlinks and reloads', () => Promise.resolve({})],
		['reports a failed unlink', () => Promise.reject(new Error('denied'))],
	])('%s', async (name, answer) => {
		axios.delete.mockImplementation(answer)
		axios.get.mockResolvedValue({ data: { results: [] } })
		const ctx = context({ accountId: 'a1', messageId: 7, removeTarget: { uuid: 'o1' }, objects: [] })
		await ctx.confirmUnlink()
		expect({
			deleted: axios.delete.mock.calls,
			reloaded: axios.get.mock.calls.length,
			removeTarget: ctx.removeTarget,
			removing: ctx.removing,
			success: showSuccess.mock.calls,
			error: showError.mock.calls,
		}).toMatchSnapshot()
	})

	it('unlinks nothing without a target', async () => {
		const ctx = context({ removeTarget: null })
		await ctx.confirmUnlink()
		expect(axios.delete).not.toHaveBeenCalled()
	})

	it.each([
		['named and typed', { downloadUrl: '/d/1', fileName: 'a.pdf', mime: 'application/pdf', attachmentId: 1 }, { ok: true, type: 'text/plain' }],
		['typed by the download', { downloadUrl: '/d/2', attachmentId: 2 }, { ok: true, type: 'image/png' }],
		['without any type', { downloadUrl: '/d/3', attachmentId: 3 }, { ok: true, type: '' }],
	])('uploads an attachment %s', async (name, attachment, download) => {
		global.fetch = jest.fn(() =>
			Promise.resolve({ ok: download.ok, status: 200, blob: async () => new Blob(['x'], { type: download.type }) }),
		)
		axios.post.mockResolvedValue({})
		const ctx = context({})
		await ctx.uploadAttachmentToObject(attachment, { register: 'r', schema: 's', objectId: 'o' })
		const [url, form, options] = axios.post.mock.calls[0]
		const file = form.get('files[]')
		expect({
			fetched: global.fetch.mock.calls,
			url,
			options,
			file: { name: file.name, type: file.type },
		}).toMatchSnapshot()
	})

	it('refuses a failed download', async () => {
		global.fetch = jest.fn(() => Promise.resolve({ ok: false, status: 404 }))
		const ctx = context({})
		await expect(
			ctx.uploadAttachmentToObject({ downloadUrl: '/d/x' }, { register: 'r', schema: 's', objectId: 'o' }),
		).rejects.toThrow('Attachment download failed with status 404')
		expect(axios.post).not.toHaveBeenCalled()
	})
})
