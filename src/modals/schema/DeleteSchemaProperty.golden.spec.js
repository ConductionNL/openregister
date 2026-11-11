import DeleteSchemaProperty from './DeleteSchemaProperty.vue'
import { objectStore, registerStore, schemaStore } from '../../store/store.js'

// Opening the delete-property dialog: how it finds the objects that still
// carry a value for the property.

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('../shared/ConfirmDialogFrame.vue', () => ({}))

/**
 * A bare dialog context with every method bound to it.
 *
 * @return {object} The context
 */
function context() {
	const ctx = { ...DeleteSchemaProperty.data.call({}) }
	for (const [name, method] of Object.entries(DeleteSchemaProperty.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const REGISTERS = {
	none: [],
	without: [{ id: 1, title: 'One', schemas: [{ id: 8 }] }],
	with: [
		{ id: 1, title: 'One', schemas: [{ id: 8 }] },
		{ id: 2, title: 'Two', schemas: [{ id: 7 }, { id: 9 }] },
		{ id: 3, title: 'Three', schemas: [{ id: 7 }] },
	],
}

const COLLECTIONS = {
	empty: () => [],
	perRegister: (filters) => (filters.register === 3
		? []
		: [{ id: `a${filters.register}`, email: 'x' }, { id: `b${filters.register}` }, { id: `c${filters.register}`, email: '' }]),
}

describe('DeleteSchemaProperty.initDialog', () => {
	for (const [registersName, registers] of Object.entries(REGISTERS)) {
		for (const [collectionName, collection] of Object.entries(COLLECTIONS)) {
			for (const schemaId of [7, 9]) {
				it(`opens with registers ${registersName}, collections ${collectionName}, schema ${schemaId}`, async () => {
					const log = []
					let filters = {}
					registerStore.refreshRegisterList = async () => {
						log.push(['refreshRegisterList'])
						registerStore.registerList = registers
					}
					registerStore.registerList = null
					schemaStore.schemaItem = { id: schemaId }
					schemaStore.schemaPropertyKey = 'email'
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
					await ctx.initDialog()
					expect({ log, objects: ctx.objects }).toMatchSnapshot()
				})
			}
		}
	}
})
