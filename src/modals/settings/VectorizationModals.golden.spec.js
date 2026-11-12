import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import FileVectorizationModal from './FileVectorizationModal.vue'
import ObjectVectorizationModal from './ObjectVectorizationModal.vue'

// The start actions of the file and object vectorization modals.

jest.mock('@nextcloud/axios', () => ({ post: jest.fn(), get: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/PlayCircle.vue', () => ({}))

/**
 * A bare modal context with the component's methods that records what it emits.
 *
 * @param {object} component The modal component
 * @param {object} state The modal state
 * @return {object} The context
 */
function context(component, state) {
	const emitted = []
	const ctx = {
		...state,
		emitted,
		t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
		$emit: (name) => emitted.push(name),
	}
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * Everything the action said, sent and left behind.
 *
 * @param {object} ctx The context
 * @return {object} The record
 */
function recorded(ctx) {
	const state = {}
	for (const [key, value] of Object.entries(ctx)) {
		if (typeof value !== 'function') {
			state[key] = value
		}
	}
	return {
		post: axios.post.mock.calls,
		success: showSuccess.mock.calls,
		error: showError.mock.calls,
		state,
	}
}

const FILE_STATE = {
	stats: { chunksToProcess: 8 },
	executionMode: 'serial',
	maxFiles: 10,
	batchSize: 4,
	vectorizeAllTypes: false,
	selectedTypes: ['pdf'],
	processing: false,
	processed: 9,
	vectorized: 9,
	failed: 9,
}

const FILE_ANSWERS = {
	full: () =>
		Promise.resolve({
			data: {
				success: true,
				data: { vectorized: 5, failed: 2, total_files: 3 },
			},
		}),
	empty: () => Promise.resolve({ data: { success: true, data: {} } }),
	refusedWithError: () =>
		Promise.resolve({ data: { success: false, error: 'No provider' } }),
	refusedBare: () => Promise.resolve({ data: { success: false } }),
	notImplementedStatus: () =>
		Promise.reject({
			response: { status: 501, data: { error: 'other' } },
			message: 'x',
		}),
	notImplementedText: () =>
		Promise.reject({
			response: {
				status: 500,
				data: { error: 'File vectorization not yet implemented' },
			},
		}),
	serverError: () =>
		Promise.reject({
			response: { status: 500, data: { error: 'boom', message: 'm' } },
			message: 'x',
		}),
	serverMessage: () =>
		Promise.reject({
			response: { status: 500, data: { message: 'only message' } },
			message: 'x',
		}),
	plainError: () => Promise.reject(new Error('network')),
}

describe('FileVectorizationModal.startVectorization', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => jest.restoreAllMocks())

	it('refuses when there are no chunks', async () => {
		const ctx = context(FileVectorizationModal, {
			...FILE_STATE,
			stats: { chunksToProcess: 0 },
		})
		await ctx.startVectorization()
		expect(recorded(ctx)).toMatchSnapshot()
	})

	for (const allTypes of [false, true]) {
		for (const [name, answer] of Object.entries(FILE_ANSWERS)) {
			it(`answers ${name}, all types ${allTypes}`, async () => {
				let during
				const ctx = context(FileVectorizationModal, {
					...FILE_STATE,
					vectorizeAllTypes: allTypes,
				})
				axios.post.mockImplementation(() => {
					during = JSON.stringify(recorded(ctx).state)
					return answer()
				})
				await ctx.startVectorization()
				expect({ during, ...recorded(ctx) }).toMatchSnapshot()
			})
		}
	}
})

const OBJECT_STATE = {
	stats: { objectsToProcess: 7 },
	maxObjects: 0,
	batchSize: 3,
	vectorizeAllViews: true,
	selectedViews: ['v1'],
	processing: false,
	processed: 9,
	vectorized: 9,
	failed: 9,
}

/**
 * A batch answer.
 *
 * @param {object} data The batch result
 * @return {Promise<object>} The response
 */
function batch(data) {
	return Promise.resolve({ data: { data } })
}

const OBJECT_RUNS = {
	allAtOnce: [
		() => batch({ processed: 3, vectorized: 3 }),
		() => batch({ processed: 3, vectorized: 2, failed: 1 }),
		() => batch({ processed: 1, vectorized: 1 }),
	],
	stopsOnEmpty: [
		() => batch({ processed: 2, vectorized: 2 }),
		() => batch({}),
		() => batch({ processed: 3 }),
	],
	noData: [() => Promise.resolve({})],
	overshoot: [() => batch({ processed: 10, vectorized: 10 })],
	failsWithError: [
		() => batch({ processed: 3 }),
		() =>
			Promise.reject({ response: { data: { error: 'quota' } }, message: 'x' }),
	],
	failsPlain: [() => Promise.reject(new Error('network'))],
}

describe('ObjectVectorizationModal.startVectorization', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => jest.restoreAllMocks())

	const SETUPS = {
		allViews: {},
		someViews: { vectorizeAllViews: false },
		maxObjects: { maxObjects: 4 },
		nothingToDo: { stats: { objectsToProcess: 0 } },
	}

	for (const [setupName, setup] of Object.entries(SETUPS)) {
		for (const [runName, run] of Object.entries(OBJECT_RUNS)) {
			it(`runs ${runName} with ${setupName}`, async () => {
				const ctx = context(ObjectVectorizationModal, {
					...OBJECT_STATE,
					...setup,
				})
				let call = 0
				const during = []
				axios.post.mockImplementation(() => {
					during.push(JSON.stringify(recorded(ctx).state))
					const answer = run[call] || (() => batch({ processed: 1 }))
					call++
					return answer()
				})
				await ctx.startVectorization()
				expect({ during, ...recorded(ctx) }).toMatchSnapshot()
			})
		}
	}

	it('stops when processing is switched off between batches', async () => {
		const ctx = context(ObjectVectorizationModal, { ...OBJECT_STATE })
		axios.post.mockImplementation(() => {
			ctx.processing = false
			return batch({ processed: 1, vectorized: 1 })
		})
		await ctx.startVectorization()
		expect(recorded(ctx)).toMatchSnapshot()
	})
})
