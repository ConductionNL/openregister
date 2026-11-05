import MergeObject from './MergeObject.vue'
import { objectStore, registerStore, schemaStore } from '../../store/store.js'

// Loading the merge source: its files, its outgoing relations and the objects that refer to it.

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowRight.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronUp.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/Merge.vue', () => ({}))

/**
 * A bare merge context with every method and computed bound to it.
 *
 * @return {object} The context
 */
function context() {
	const ctx = {
		...MergeObject.data.call({}),
		sourceFiles: ['stale'],
		sourceRelations: ['stale'],
		sourceIncomingReferences: ['stale'],
	}
	for (const [name, method] of Object.entries(MergeObject.methods)) {
		ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(MergeObject.computed)) {
		Object.defineProperty(ctx, name, {
			get: () => getter.call(ctx),
			enumerable: false,
		})
	}
	return ctx
}

const SOURCES = {
	none: null,
	bare: {},
	selfRelations: {
		'@self': { id: 'src', relations: ['r1', 'r2'] },
		attachments: [{ name: 'a.pdf' }],
	},
	legacyRelations: {
		'@self': { id: 'src' },
		_relations: ['r3'],
		attachments: 'not-a-list',
	},
	emptySelfRelations: {
		'@self': { id: 'src', relations: [] },
		_relations: ['r4'],
	},
	relationsNotList: { '@self': { id: 'src', relations: 'r5' } },
	noSelfId: { '@self': { relations: ['r6'] }, attachments: [] },
}

const REFERRERS = [
	{ '@self': { id: 'u1', name: 'Named', title: 'T', schema: 4, register: 2 } },
	{ '@self': { id: 'u2', title: 'Titled' } },
	{ '@self': { id: 'u3' } },
	{ id: 'u4' },
	{ '@self': { schema: 'sch' }, id: 'u5' },
	null,
]

const ANSWERS = {
	referrers: () => Promise.resolve({ json: async () => ({ results: REFERRERS }) }),
	noResults: () => Promise.resolve({ json: async () => ({}) }),
	brokenJson: () =>
		Promise.resolve({
			json: async () => {
				throw new Error('Unexpected token')
			},
		}),
	networkDown: () => Promise.reject(new Error('Network')),
}

const SCOPES = {
	scoped: [{ id: 1 }, { id: 2 }],
	noRegister: [null, { id: 2 }],
	noSchema: [{ id: 1 }, {}],
}

describe('MergeObject.loadSourceData', () => {
	let log

	beforeEach(() => {
		log = []
		jest.spyOn(console, 'error').mockImplementation((...args) =>
			log.push(['console.error', args[0], String(args[1])]),
		)
	})

	afterEach(() => jest.restoreAllMocks())

	for (const [sourceName, source] of Object.entries(SOURCES)) {
		for (const [scopeName, [register, schema]] of Object.entries(SCOPES)) {
			for (const [answerName, answer] of Object.entries(ANSWERS)) {
				it(`loads ${sourceName}, ${scopeName}, answering ${answerName}`, async () => {
					objectStore.objectItem = source
					registerStore.registerItem = register
					schemaStore.schemaItem = schema
					global.fetch = (url) => {
						log.push(['fetch', url])
						return answer()
					}
					const ctx = context()
					await ctx.loadSourceData()
					expect({
						log,
						files: ctx.sourceFiles,
						relations: ctx.sourceRelations,
						incoming: ctx.sourceIncomingReferences,
					}).toMatchSnapshot()
				})
			}
		}
	}
})
