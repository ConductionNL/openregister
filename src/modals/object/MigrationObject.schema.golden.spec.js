/**
 * Golden record of how the migration modal loads the source and target
 * schema properties: what it fetches, what it keeps, and when it stops.
 *
 * @spec exclude Golden record of client-side migration modal loading.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import MigrationObject from './MigrationObject.vue'
import { schemaStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: { schemaItem: null },
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowRight.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseExport.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileTreeOutline.vue', () => ({}))
jest.mock('../../components/shared/SelectedObjectsList.vue', () => ({}))

/**
 * A modal context whose mapping steps only record themselves.
 *
 * @param {object} state The state to start from
 * @param {Array} log Where the steps are recorded
 * @return {object} The context
 */
function context(state, log) {
	const ctx = { ...state }
	for (const [name, method] of Object.entries(MigrationObject.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.initializePropertyMappings = () => log.push(['initializePropertyMappings'])
	ctx.convertMappingToUI = () => log.push(['convertMappingToUI'])
	return ctx
}

beforeEach(() => jest.spyOn(console, 'error').mockImplementation(() => {}))
afterEach(() => jest.restoreAllMocks())

describe('MigrationObject.loadSchemaProperties (golden)', () => {
	it.each([
		['without a source schema', null, { id: 2 }, () => Promise.resolve({ json: async () => ({}) })],
		['without a target schema', { id: 1, properties: {} }, null, () => Promise.resolve({ json: async () => ({}) })],
		['loads both schemas', { id: 1, properties: { a: { type: 'string', title: 'A' } } }, { id: 2 }, () => Promise.resolve({ json: async () => ({ id: 2, properties: { b: { type: 'number' } } }) })],
		['stops on a failed fetch', { id: 1, properties: { a: {} } }, { id: 2 }, () => Promise.reject(new Error('offline'))],
	])('%s', async (name, source, target, answer) => {
		const log = []
		schemaStore.schemaItem = source
		global.fetch = jest.fn(answer)
		const ctx = context({ targetSchema: target, sourceProperties: ['old'], targetProperties: ['old'] }, log)
		await ctx.loadSchemaProperties()
		expect(JSON.stringify({
			fetched: global.fetch.mock.calls,
			sourceProperties: ctx.sourceProperties,
			targetProperties: ctx.targetProperties,
			log,
			errors: console.error.mock.calls.length,
		})).toMatchSnapshot()
	})
})
