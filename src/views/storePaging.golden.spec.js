/**
 * Golden record of the paging handlers of the deleted-items, audit-trail
 * and search-trail lists: what each asks its store for, the selection it
 * clears, and what it logs when the store fails.
 *
 * @spec exclude Golden record of client-side list paging.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeletedIndex from './deleted/DeletedIndex.vue'
import AuditTrailIndex from './logs/AuditTrailIndex.vue'
import SearchTrailIndex from './logs/SearchTrailIndex.vue'
import {
	auditTrailStore,
	deletedStore,
	searchTrailStore,
} from '../store/store.js'

jest.mock('../store/store.js', () => ({
	auditTrailStore: {},
	deletedStore: {},
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
	searchTrailStore: {},
}))
jest.mock('../eventBus.js', () => ({
	__esModule: true,
	default: { emit: () => {}, on: () => {}, off: () => {} },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s) => s,
	translatePlural: (app, s) => s,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('../components/PaginationComponent.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/Broom.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/CompareHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/DeleteEmpty.vue', () => ({}))
jest.mock('vue-material-design-icons/Download.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FormatListChecks.vue', () => ({}))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/Restore.vue', () => ({}))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({}))

const LISTS = {
	deleted: {
		component: DeletedIndex,
		store: deletedStore,
		fetch: 'fetchDeleted',
		pagination: 'deletedPagination',
		selection: 'selectedItems',
	},
	auditTrails: {
		component: AuditTrailIndex,
		store: auditTrailStore,
		fetch: 'fetchAuditTrails',
		pagination: 'auditTrailPagination',
		selection: 'selectedAuditTrails',
	},
	searchTrails: {
		component: SearchTrailIndex,
		store: searchTrailStore,
		fetch: 'fetchSearchTrails',
		pagination: 'searchTrailPagination',
		selection: 'selectedSearchTrails',
	},
}

/**
 * A bare list context with the methods of the component and its mixins.
 *
 * @param {object} component The list view
 * @return {object} The context
 */
function context(component) {
	const ctx = {}
	for (const source of [...(component.mixins || []), component]) {
		for (const [name, method] of Object.entries(source.methods || {})) {
			ctx[name] = method.bind(ctx)
		}
	}
	return ctx
}

describe('list paging golden', () => {
	let log

	beforeEach(() => {
		log = []
		jest.spyOn(console, 'error').mockImplementation((...args) =>
			log.push(['console.error', args[0], String(args[1])]),
		)
	})

	afterEach(() => jest.restoreAllMocks())

	for (const [name, list] of Object.entries(LISTS)) {
		for (const fails of [false, true]) {
			for (const [handler, value] of [
				['onPageChanged', 3],
				['onPageSizeChanged', 50],
			]) {
				it(`${name} ${handler}${fails ? ' when the store fails' : ''}`, async () => {
					const ctx = context(list.component)
					ctx[list.selection] = ['a', 'b']
					list.store[list.pagination] = { limit: 25 }
					list.store[list.fetch] = async (options) => {
						log.push([list.fetch, options, [...ctx[list.selection]]])
						if (fails) throw new Error('down')
					}
					await ctx[handler](value)
					expect({ log, selection: ctx[list.selection] }).toMatchSnapshot()
				})
			}
		}
	}

	for (const handler of ['handleObjectsDeleted', 'handleObjectsRestored']) {
		it(`deleted ${handler}`, async () => {
			const ctx = context(DeletedIndex)
			ctx.selectedItems = ['a', 'b', 'c']
			ctx.loadItems = async () => log.push(['loadItems', [...ctx.selectedItems]])
			await ctx[handler](['c', 'x', 'a'])
			expect({ log, selection: ctx.selectedItems }).toMatchSnapshot()
		})
	}
})
