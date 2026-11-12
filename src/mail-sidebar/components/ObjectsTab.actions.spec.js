/**
 * ObjectsTab's requests: loading the objects linked to a message, removing a
 * connection after confirmation, and uploading a dropped attachment.
 *
 * @spec exclude Unit coverage of client-side mail sidebar object actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ObjectsTab from './ObjectsTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), delete: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars = {}) =>
		path.replace(/\{(\w+)\}/g, (all, key) => vars[key] ?? all),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('../../dialogs/mail/RemoveConnectionDialog.vue', () => ({}))
jest.mock('../icons.js', () => ({ schemaIconComponent: jest.fn() }))

/**
 * A tab context for one message, with the tab's methods bound to it.
 *
 * @param {object} state Extra state
 * @return {object} The context
 */
function tab(state = {}) {
	const emitted = []
	const ctx = {
		...ObjectsTab.data.call({}),
		accountId: 7,
		messageId: 42,
		emitted,
		$emit: (...args) => emitted.push(args),
		...state,
	}
	for (const [name, method] of Object.entries(ObjectsTab.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

let consoleError
const originalFetch = globalThis.fetch
beforeEach(() => {
	jest.clearAllMocks()
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})
afterEach(() => {
	consoleError.mockRestore()
	globalThis.fetch = originalFetch
})

describe('ObjectsTab.loadObjects', () => {
	it.each([
		['an account', { accountId: null }],
		['a message', { messageId: null }],
	])('clears the list and counts zero without %s', async (name, state) => {
		const ctx = tab({ ...state, objects: [{ uuid: 'old' }] })
		await ctx.loadObjects()
		expect(axios.get).not.toHaveBeenCalled()
		expect(ctx.objects).toEqual([])
		expect(ctx.emitted).toEqual([['count', 0]])
	})

	it('loads the objects linked to the message and counts them', async () => {
		let during
		const ctx = tab()
		axios.get.mockImplementation(() => {
			during = ctx.loading
			return Promise.resolve({ data: { results: [{ uuid: 'a' }, { uuid: 'b' }] } })
		})
		await ctx.loadObjects()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/linked/mail/7/42', { timeout: 10000 })
		expect(during).toBe(true)
		expect(ctx.objects).toEqual([{ uuid: 'a' }, { uuid: 'b' }])
		expect(ctx.loading).toBe(false)
		expect(ctx.emitted).toEqual([['count', 2]])
	})

	it('reads an answer without results as no objects', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const ctx = tab()
		await ctx.loadObjects()
		expect(ctx.objects).toEqual([])
		expect(ctx.emitted).toEqual([['count', 0]])
	})

	it('empties the list when loading fails', async () => {
		axios.get.mockRejectedValue(new Error('timeout'))
		const ctx = tab({ objects: [{ uuid: 'old' }] })
		await ctx.loadObjects()
		expect(ctx.objects).toEqual([])
		expect(ctx.loading).toBe(false)
		expect(ctx.emitted).toEqual([['count', 0]])
	})
})

describe('ObjectsTab connection removal', () => {
	it('opens the confirmation for an object', () => {
		const ctx = tab()
		ctx.promptUnlink({ uuid: 'u1' })
		expect(ctx.removeTarget).toEqual({ uuid: 'u1' })
	})

	it('dismisses the confirmation, unless a removal is running', () => {
		const ctx = tab({ removeTarget: { uuid: 'u1' }, removing: true })
		ctx.cancelUnlink()
		expect(ctx.removeTarget).toEqual({ uuid: 'u1' })
		ctx.removing = false
		ctx.cancelUnlink()
		expect(ctx.removeTarget).toBeNull()
	})

	it('does nothing without a pending connection', async () => {
		const ctx = tab()
		await ctx.confirmUnlink()
		expect(axios.delete).not.toHaveBeenCalled()
		expect(ctx.removing).toBe(false)
	})

	it('removes the connection, says so and reloads', async () => {
		axios.delete.mockResolvedValue({})
		const ctx = tab({ removeTarget: { uuid: 'u1' } })
		ctx.loadObjects = jest.fn()
		await ctx.confirmUnlink()
		expect(axios.delete).toHaveBeenCalledWith('/apps/openregister/api/objects/u1/_linked/mail/7/42')
		expect(showSuccess).toHaveBeenCalledWith('Connection removed')
		expect(ctx.removeTarget).toBeNull()
		expect(ctx.loadObjects).toHaveBeenCalledTimes(1)
		expect(ctx.removing).toBe(false)
	})

	it('keeps the confirmation open when removal fails', async () => {
		axios.delete.mockRejectedValue(new Error('403'))
		const ctx = tab({ removeTarget: { uuid: 'u1' } })
		ctx.loadObjects = jest.fn()
		await ctx.confirmUnlink()
		expect(showError).toHaveBeenCalledWith('Failed to remove connection')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(ctx.removeTarget).toEqual({ uuid: 'u1' })
		expect(ctx.loadObjects).not.toHaveBeenCalled()
		expect(ctx.removing).toBe(false)
	})
})

describe('ObjectsTab attachment upload', () => {
	const TARGET = { register: 1, schema: 2, objectId: 'o1' }

	it('marks a drag over the tab as a copy', () => {
		const event = { dataTransfer: { dropEffect: 'none' } }
		tab().onAttachmentDragOver(event)
		expect(event.dataTransfer.dropEffect).toBe('copy')
		expect(() => tab().onAttachmentDragOver({})).not.toThrow()
	})

	it('downloads the attachment and posts it to the object as a file', async () => {
		const blob = new Blob(['%PDF'], { type: 'application/pdf' })
		globalThis.fetch = jest.fn(() => Promise.resolve({ ok: true, blob: () => Promise.resolve(blob) }))
		axios.post.mockResolvedValue({})
		await tab().uploadAttachmentToObject(
			{ downloadUrl: '/mail/att/9', fileName: 'invoice.pdf', mime: 'application/pdf' },
			TARGET,
		)
		expect(fetch).toHaveBeenCalledWith('/mail/att/9', { credentials: 'same-origin' })
		const [url, form, options] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/objects/1/2/o1/files-multipart')
		expect(options).toEqual({ headers: { 'Content-Type': 'multipart/form-data' }, timeout: 20000 })
		const file = form.get('files[]')
		expect(file.name).toBe('invoice.pdf')
		expect(file.type).toBe('application/pdf')
	})

	it('names and types an attachment that lacks both', async () => {
		const blob = new Blob(['x'])
		globalThis.fetch = jest.fn(() => Promise.resolve({ ok: true, blob: () => Promise.resolve(blob) }))
		axios.post.mockResolvedValue({})
		await tab().uploadAttachmentToObject({ downloadUrl: '/a', attachmentId: 5 }, TARGET)
		const file = axios.post.mock.calls[0][1].get('files[]')
		expect(file.name).toBe('attachment-5')
		expect(file.type).toBe('application/octet-stream')
	})

	it('refuses an attachment it cannot download, without posting', async () => {
		globalThis.fetch = jest.fn(() => Promise.resolve({ ok: false, status: 404 }))
		await expect(tab().uploadAttachmentToObject({ downloadUrl: '/a' }, TARGET))
			.rejects.toThrow('Attachment download failed with status 404')
		expect(axios.post).not.toHaveBeenCalled()
	})
})
