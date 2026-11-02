import DeleteSchema from './DeleteSchema.vue'
import { objectStore, registerStore, schemaStore } from '../../store/store.js'

// Opening the delete dialog: how it finds the objects that still use the schema.

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))

/**
 * A bare dialog context with every method bound to it.
 *
 * @return {object} The context
 */
function context() {
	const ctx = { ...DeleteSchema.data.call({}) }
	for (const [name, method] of Object.entries(DeleteSchema.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const REGISTERS = {
	none: [],
	without: [{ id: 1, title: 'One', schemas: [8] }],
	with: [
		{ id: 1, title: 'One', schemas: [8] },
		{ id: 2, title: 'Two', schemas: [7, 9] },
		{ id: 3, title: 'Three', schemas: [7] },
	],
}

const STATS = {
	three: () => Promise.resolve({ objects: { total: 3 } }),
	zero: () => Promise.resolve({ objects: { total: 0 } }),
	noObjects: () => Promise.resolve({}),
	textCount: () => Promise.resolve({ objects: { total: '2' } }),
	rejects: () => Promise.reject(new Error('stats down')),
	nullStats: () => Promise.resolve(null),
}

const COLLECTIONS = {
	empty: () => [],
	perRegister: (filters) =>
		filters.register === 3 ? [] : [{ id: `o${filters.register}` }],
}

describe('DeleteSchema.initDialog', () => {
	let log

	beforeEach(() => {
		log = []
		jest.spyOn(console, 'warn').mockImplementation((...args) =>
			log.push(['console.warn', args[0], String(args[1])]),
		)
	})

	afterEach(() => jest.restoreAllMocks())

	for (const [registersName, registers] of Object.entries(REGISTERS)) {
		for (const [statsName, stats] of Object.entries(STATS)) {
			for (const [collectionName, collection] of Object.entries(COLLECTIONS)) {
				for (const schemaItem of [{ id: 7 }, { id: 9 }, null]) {
					it(`opens with registers ${registersName}, stats ${statsName}, collections ${collectionName}, schema ${schemaItem?.id}`, async () => {
						let filters = {}
						registerStore.refreshRegisterList = async () => {
							log.push(['refreshRegisterList'])
							registerStore.registerList = registers
						}
						registerStore.registerList = null
						schemaStore.schemaItem = schemaItem
						schemaStore.getSchemaStats = (id) => {
							log.push(['getSchemaStats', id])
							return stats()
						}
						objectStore.currentType = 'current'
						objectStore.refreshObjectList = async (options) => {
							log.push(['refreshObjectList', options])
							filters = options
						}
						objectStore.getCollection = (type) => {
							log.push(['getCollection', type])
							return collection(filters)
						}
						const ctx = context()
						ctx.objects = [{ id: 'before' }]
						let outcome = 'resolved'
						try {
							await ctx.initDialog()
						} catch (e) {
							outcome = `rejected: ${e.message}`
						}
						expect({
							log,
							outcome,
							objects: ctx.objects,
							registerName: ctx.registerName,
						}).toMatchSnapshot()
					})
				}
			}
		}
	}
})
