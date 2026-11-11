/**
 * Golden record of the upload modal's upload action: which files it sends,
 * the form data of each, the store calls and the state that follows.
 *
 * @spec exclude Golden record of client-side upload orchestration.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import UploadFiles from './UploadFiles.vue'
import { objectStore } from '../../store/store.js'

const mockLog = []

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {
		objectTypes: [],
		registerObjectType: (...args) =>
			mockLog.push(['registerObjectType', ...args]),
		uploadFiles: jest.fn(),
		fetchFiles: (...args) => mockLog.push(['fetchFiles', ...args]),
	},
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('./../../composables/UseFileSelection.js', () => ({
	useFileSelection: () => ({}),
}))
jest.mock('vue-material-design-icons/AlphaXCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Exclamation.vue', () => ({}))
jest.mock('vue-material-design-icons/FolderOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Minus.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/TagEdit.vue', () => ({}))
jest.mock('vue-material-design-icons/TrayArrowDown.vue', () => ({}))

/**
 * The entries of a form data, as plain pairs.
 *
 * @param {FormData} form The form data
 * @return {Array} The pairs
 */
function entries(form) {
	return [...form.entries()].map(([key, value]) => [
		key,
		typeof value === 'string' ? value : value.name,
	])
}

/**
 * A file the way the modal holds it.
 *
 * @param {string} name The name
 * @param {object} extra Status, size and tags
 * @return {File} The file
 */
function file(name, extra = {}) {
	const { size = 1, ...rest } = extra
	return Object.assign(new File(['x'.repeat(size)], name), {
		status: 'pending',
		...rest,
	})
}

/**
 * A modal context with the modal's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function modal(state) {
	const ctx = {
		registerId: 1,
		schemaId: 2,
		objectId: 'o-3',
		share: false,
		error: 'old',
		success: false,
		loading: false,
		filesComputed: [],
		...state,
	}
	for (const [name, method] of Object.entries(UploadFiles.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.getTooBigFiles = (size) => size > 100
	ctx.getAllTags = () => mockLog.push(['getAllTags'])
	return ctx
}

const SCENARIOS = {
	'no register': [{ registerId: null }, null, []],
	'no schema': [{ schemaId: 0 }, null, []],
	'no object': [{ objectId: '' }, null, []],
	'nothing to send': [
		{
			filesComputed: [
				file('a', { status: 'uploaded' }),
				file('b', { status: 'uploading' }),
				file('c', { size: 500 }),
			],
		},
		null,
		[],
	],
	'queued files': [
		{
			filesComputed: [
				file('a', { tags: ['t1', 't2'] }),
				file('b', { status: 'failed', size: 10 }),
				file('c', { status: 'uploaded' }),
				file('big', { size: 101 }),
			],
		},
		null,
		['ok', 'ok'],
	],
	'shared upload': [
		{ share: true, filesComputed: [file('a', { tags: 'notalist' })] },
		null,
		['ok'],
	],
	'one specific file': [
		{ filesComputed: [file('ignored')] },
		file('chosen', { size: 999, status: 'uploaded' }),
		['ok'],
	],
	'known type': [{ known: true, filesComputed: [file('a')] }, null, ['ok']],
	'one fails': [
		{ filesComputed: [file('a'), file('b')] },
		null,
		['ok', new Error('too slow')],
	],
	'all fail': [
		{ filesComputed: [file('a'), file('b')] },
		null,
		[new Error('first'), new Error('second')],
	],
}

describe('upload files golden', () => {
	for (const [name, [state, specific, replies]] of Object.entries(SCENARIOS)) {
		it(name, async () => {
			mockLog.length = 0
			objectStore.objectTypes = state.known ? ['1-2'] : []
			const queue = [...replies]
			objectStore.uploadFiles.mockImplementation((type, id, form) => {
				mockLog.push(['uploadFiles', type, id, entries(form)])
				const next = queue.shift()
				return next instanceof Error
					? Promise.reject(next)
					: Promise.resolve({ reply: next })
			})
			const ctx = modal(state)
			await (specific ? ctx.addAttachments(specific) : ctx.addAttachments())
			const files = [...ctx.filesComputed, ...(specific ? [specific] : [])]
			expect({
				log: mockLog,
				error: ctx.error,
				success: ctx.success,
				loading: ctx.loading,
				statuses: files.map((f) => [f.name, f.status]),
			}).toMatchSnapshot()
		})
	}

	it('reports an unexpected failure', async () => {
		mockLog.length = 0
		objectStore.objectTypes = null
		const ctx = modal({ filesComputed: [file('a')] })
		ctx.getAllTags = () => {
			throw Object.assign(new Error('boom'), {
				response: { data: { error: 'server says no' } },
			})
		}
		objectStore.uploadFiles.mockImplementation(() => Promise.resolve({}))
		await ctx.addAttachments()
		expect({
			log: mockLog,
			error: ctx.error,
			loading: ctx.loading,
		}).toMatchSnapshot()
		ctx.getAllTags = () => {
			throw new Error('plain')
		}
		ctx.filesComputed[0].status = 'pending'
		await ctx.addAttachments()
		expect(String(ctx.error)).toBe('Error: plain')
	})
})
