/**
 * Golden record of ObjectsTab::onAttachmentDrop: what a dropped Mail
 * attachment uploads to which object, the toasts and the upload marker.
 *
 * @spec exclude Golden record of client-side drop plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { showError, showSuccess } from '@nextcloud/dialogs'
import ObjectsTab from './ObjectsTab.vue'
import { ATTACHMENT_MIME } from '../composables/useAttachmentDrag.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (all, key) => vars?.[key] ?? all),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('../../dialogs/mail/RemoveConnectionDialog.vue', () => ({}))
jest.mock('../icons.js', () => ({ schemaIconComponent: jest.fn() }))

const OBJECTS = {
	full: {
		register: 1,
		schemaId: 2,
		schema: 'ignored',
		id: 'o1',
		uuid: 'u1',
		title: 'One',
	},
	schemaAndUuid: { register: 1, schema: 3, uuid: 'u2', title: 'Two' },
	noRegister: { schemaId: 2, id: 'o3', uuid: 'u3' },
	noSchema: { register: 1, id: 'o4', uuid: 'u4' },
	noId: { register: 1, schemaId: 2 },
}
const PAYLOADS = {
	attachment: JSON.stringify({ id: 'a1', name: 'invoice.pdf' }),
	broken: '{not json',
	none: '',
}
const UPLOADS = {
	uploaded: () => Promise.resolve(),
	failed: () => Promise.reject(new Error('quota')),
}

describe('ObjectsTab.onAttachmentDrop (golden)', () => {
	for (const [objectName, obj] of Object.entries(OBJECTS)) {
		for (const [payloadName, payload] of Object.entries(PAYLOADS)) {
			for (const [uploadName, upload] of Object.entries(UPLOADS)) {
				it(`drops ${payloadName} on ${objectName}, ${uploadName}`, async () => {
					const log = []
					showError.mockImplementation((...args) =>
						log.push(['showError', ...args]),
					)
					showSuccess.mockImplementation((...args) =>
						log.push(['showSuccess', ...args]),
					)
					jest.spyOn(console, 'error').mockImplementation((...args) =>
						log.push([
							'console.error',
							...args.map((arg) =>
								arg instanceof Error
									? `Error(${arg.message})`
									: String(arg),
							),
						]),
					)
					const ctx = { uploadingObjectUuid: 'idle' }
					for (const [name, method] of Object.entries(
						ObjectsTab.methods,
					)) {
						ctx[name] = method.bind(ctx)
					}
					ctx.displayName = (o) => `name:${o.title}`
					ctx.uploadAttachmentToObject = (...args) => {
						log.push(['upload', ...args, ctx.uploadingObjectUuid])
						return upload()
					}
					const event = {
						dataTransfer: {
							getData: (mime) =>
								mime === ATTACHMENT_MIME ? payload : 'wrong mime',
						},
					}
					await ctx.onAttachmentDrop(event, obj)
					jest.restoreAllMocks()
					expect({
						log,
						uploadingObjectUuid: ctx.uploadingObjectUuid,
					}).toMatchSnapshot()
				})
			}
		}
	}

	it('ignores a drop without a data transfer', async () => {
		const ctx = {
			uploadingObjectUuid: 'idle',
			uploadAttachmentToObject: jest.fn(),
		}
		await ObjectsTab.methods.onAttachmentDrop.call(ctx, {}, OBJECTS.full)
		expect(ctx.uploadAttachmentToObject).not.toHaveBeenCalled()
		expect(ctx.uploadingObjectUuid).toBe('idle')
	})
})
