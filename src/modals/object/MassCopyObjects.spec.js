import MassCopyObjects from './MassCopyObjects.vue'
import { objectStore } from '../../store/store.js'

// Copying a selection saves one renamed copy per object, without the
// identity and bookkeeping of the original, and reports how many made it.

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {
		selectedObjects: [],
		saveObject: jest.fn(),
		refreshObjectList: jest.fn(),
	},
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({}))

/**
 * A bare component context with a naming pattern.
 *
 * @param {object} pattern The selected naming pattern.
 * @return {object} The context
 */
function context(pattern) {
	const ctx = {
		selectedNamingPattern: pattern,
		customPattern: 'Custom {name} ({id})',
		loading: false,
		error: false,
		success: null,
		successCount: 0,
		totalCount: 0,
	}
	for (const [name, method] of Object.entries(MassCopyObjects.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.closeDialog = jest.fn()
	return ctx
}

/**
 * Run copyObjects on a context.
 *
 * @param {object} ctx The context.
 * @return {Promise<void>}
 */
function copy(ctx) {
	return ctx.copyObjects()
}

/**
 * A stored object with full metadata.
 *
 * @param {number} id The id.
 * @param {object} extra Extra root fields.
 * @return {object}
 */
function stored(id, extra = {}) {
	return {
		id,
		...extra,
		'@self': {
			id,
			uuid: `u-${id}`,
			uri: 'x',
			created: 'c',
			updated: 'u',
			version: '1',
			files: [],
			relations: [],
			folder: 'f',
			size: 3,
			deleted: null,
			name: `Name ${id}`,
			register: 1,
			schema: 2,
			owner: 'kept',
		},
	}
}

describe('MassCopyObjects.copyObjects', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.spyOn(console, 'error').mockImplementation(() => {})
		objectStore.saveObject.mockReset()
		objectStore.refreshObjectList.mockReset()
	})
	afterEach(() => {
		jest.useRealTimers()
		jest.restoreAllMocks()
	})

	it('saves a stripped, renamed copy of each object under its register and schema', async () => {
		objectStore.selectedObjects = [
			stored(1, { name: 'root', title: 't' }),
			stored(2),
		]
		objectStore.saveObject.mockResolvedValue({ response: { ok: true } })
		const ctx = context({ value: 'prefix', pattern: 'Copy of {name} #{id}' })

		await copy(ctx)

		expect(objectStore.saveObject).toHaveBeenCalledTimes(2)
		const [first, firstScope] = objectStore.saveObject.mock.calls[0]
		expect(first).toEqual({
			name: 'Copy of Name 1 #1',
			title: 'Copy of Name 1 #1',
			'@self': {
				name: 'Copy of Name 1 #1',
				register: 1,
				schema: 2,
				owner: 'kept',
			},
		})
		expect(firstScope).toEqual({ register: 1, schema: 2 })
		expect(objectStore.saveObject.mock.calls[1][0]).toEqual({
			'@self': {
				name: 'Copy of Name 2 #2',
				register: 1,
				schema: 2,
				owner: 'kept',
			},
		})
		expect(ctx.successCount).toBe(2)
		expect(ctx.totalCount).toBe(2)
		expect(ctx.success).toBe(true)
		expect(ctx.loading).toBe(false)
		expect(objectStore.refreshObjectList).toHaveBeenCalled()
		jest.advanceTimersByTime(3000)
		expect(ctx.closeDialog).toHaveBeenCalled()
	})

	it('uses the custom pattern and falls back through the name sources', async () => {
		const noName = {
			id: 9,
			'@self': { title: 'Titled', register: 1, schema: 2 },
		}
		const nothing = { '@self': { id: 4, register: 1, schema: 2 } }
		objectStore.selectedObjects = [noName, nothing]
		objectStore.saveObject.mockResolvedValue({ response: { ok: true } })
		const ctx = context({ value: 'custom' })

		await copy(ctx)

		expect(objectStore.saveObject.mock.calls[0][0]['@self'].name).toBe(
			'Custom Titled (9)',
		)
		// The copy shares @self with the original, so the original's @self.id is
		// already gone when the name is built.
		expect(objectStore.saveObject.mock.calls[1][0]['@self'].name).toBe(
			'Custom Object undefined (ID)',
		)
	})

	it('defaults to "Copy of {name}" without a pattern', async () => {
		objectStore.selectedObjects = [stored(5)]
		objectStore.saveObject.mockResolvedValue({ response: { ok: true } })
		const ctx = context(null)
		await copy(ctx)
		expect(objectStore.saveObject.mock.calls[0][0]['@self'].name).toBe(
			'Copy of Name 5',
		)
	})

	it('carries on past a failing copy and counts only the saved ones', async () => {
		objectStore.selectedObjects = [stored(1), stored(2), stored(3)]
		objectStore.saveObject
			.mockRejectedValueOnce(new Error('boom'))
			.mockResolvedValueOnce({ response: { ok: false } })
			.mockResolvedValueOnce({ response: { ok: true } })
		const ctx = context({ value: 'x', pattern: '{name}' })

		await copy(ctx)

		expect(console.error).toHaveBeenCalledWith(
			'Failed to copy object:',
			undefined,
			expect.any(Error),
		)
		expect(ctx.successCount).toBe(1)
		expect(ctx.success).toBe(true)
	})

	it('reports a failure when nothing was copied', async () => {
		objectStore.selectedObjects = [stored(1)]
		objectStore.saveObject.mockResolvedValue({ response: { ok: false } })
		const ctx = context({ value: 'x', pattern: '{name}' })

		await copy(ctx)

		expect(ctx.success).toBe(false)
		expect(ctx.error).toBe('Failed to copy any objects')
		expect(ctx.loading).toBe(false)
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
	})
})

describe('MassCopyObjects.getPreviewName', () => {
	it.each([
		[null, { value: 'x', pattern: '{name}' }, 'Preview Name'],
		[
			{ '@self': { name: 'A', id: 3 } },
			{ value: 'x', pattern: '{name}-{id}' },
			'A-3',
		],
		[{ id: 8, name: 'Root' }, null, 'Copy of Root'],
		[{ '@self': { id: 2 } }, { value: 'custom' }, 'Custom Object 2 (2)'],
	])('previews %j', (object, pattern, expected) => {
		expect(context(pattern).getPreviewName(object)).toBe(expected)
	})
})
