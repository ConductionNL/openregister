/**
 * Golden record of the object detail view's relation and audit trail requests: what the
 * view asks the store for, with which page, and its loading flags and
 * starting state.
 *
 * @spec exclude Golden record of client-side request wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ObjectDetails from './ObjectDetails.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/ExclamationThick.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FileOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FolderOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOpenOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/TimelineQuestionOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountOff.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CloseCircleOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TableLarge.vue', () => ({}))
jest.mock('vue-material-design-icons/TableLargePlus.vue', () => ({}))
jest.mock('vue-material-design-icons/TableRemove.vue', () => ({}))
jest.mock('vue-material-design-icons/EmailOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/EmailOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CalendarOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CalendarPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/CalendarRemove.vue', () => ({}))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/LinkVariantOff.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
	objectStore: {},
}))


/**
 * A view context with the view's methods bound to it.
 *
 * @param {object} pagination The pagination state
 * @return {object} The context
 */
function view(pagination) {
	const ctx = {
		auditTrailLoading: false,
		auditTrails: [],
		relationsLoading: false,
		pagination,
	}
	for (const [name, method] of Object.entries(ObjectDetails.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * Replace the store's contents.
 *
 * @param {object} contents The store
 */
function store(contents) {
	for (const key of Object.keys(objectStore)) delete objectStore[key]
	Object.assign(objectStore, contents)
}

/**
 * A deferred promise.
 *
 * @return {{promise: Promise, resolve: Function}} The promise and its resolver
 */
function deferred() {
	let resolve
	const promise = new Promise((res) => {
		resolve = res
	})
	return { promise, resolve }
}

const PAGES = {
	files: { limit: 200, currentPage: 1 },
	auditTrails: { limit: 50, currentPage: 3 },
	relations: { limit: 20, currentPage: 4 },
}

describe('ObjectDetails requests golden', () => {
	it('starts from the store pages, or the first page', () => {
		store({ files: { page: 2 }, auditTrails: { page: 5 }, used: { page: 7 } })
		expect(ObjectDetails.data.call({})).toMatchSnapshot()
		store({})
		expect(ObjectDetails.data.call({}).pagination).toMatchSnapshot()
	})

	it.each([
		['no object', { fetchUsed: jest.fn() }],
		['no fetchUsed', { objectItem: { id: 'o1' } }],
	])('asks for no relations with %s', (name, contents) => {
		store(contents)
		const ctx = view(PAGES)
		ctx.getRelations()
		expect(ctx.relationsLoading).toBe(false)
		expect(contents.fetchUsed?.mock.calls ?? []).toEqual([])
	})

	it.each([
		['with fetchUses', true],
		['without fetchUses', false],
	])('asks for both relation directions %s', async (name, withUses) => {
		const log = []
		const incoming = deferred()
		const outgoing = deferred()
		store({
			objectItem: { id: 'o1' },
			currentType: '1-2',
			fetchUsed: (...args) => {
				log.push(['fetchUsed', ...args])
				return incoming.promise
			},
			...(withUses
				? {
						fetchUses: (...args) => {
							log.push(['fetchUses', ...args])
							return outgoing.promise
						},
					}
				: {}),
		})
		const ctx = view(PAGES)
		ctx.getRelations()
		expect(ctx.relationsLoading).toBe(true)
		incoming.resolve([])
		await Promise.resolve()
		await Promise.resolve()
		expect(ctx.relationsLoading).toBe(withUses)
		outgoing.resolve([])
		await new Promise((res) => setTimeout(res, 0))
		expect(ctx.relationsLoading).toBe(false)
		expect(log).toMatchSnapshot()
	})

	it.each([
		['no object', { getAuditTrails: jest.fn() }],
		['no getAuditTrails', { objectItem: { id: 'o1' } }],
	])('asks for no audit trails with %s', (name, contents) => {
		store(contents)
		const ctx = view(PAGES)
		ctx.getAuditTrails()
		expect(ctx.auditTrailLoading).toBe(false)
		expect(contents.getAuditTrails?.mock.calls ?? []).toEqual([])
	})

	it.each([
		['answers', () => Promise.resolve({ data: [{ id: 't1' }] })],
	])('asks for the audit trail page and %s', async (name, answer) => {
		const calls = []
		store({
			objectItem: { id: 'o1' },
			getAuditTrails: (...args) => {
				calls.push(args)
				return answer()
			},
		})
		const ctx = view(PAGES)
		const pending = ctx.getAuditTrails()
		expect(pending).toBeUndefined()
		expect(ctx.auditTrailLoading).toBe(true)
		await new Promise((res) => setTimeout(res, 0))
		expect({
			calls,
			auditTrails: ctx.auditTrails,
			loading: ctx.auditTrailLoading,
		}).toMatchSnapshot()
	})
})
