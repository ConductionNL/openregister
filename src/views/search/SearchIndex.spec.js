/**
 * SearchIndex — object-list view dispatch unit tests (object-views-kanban-calendar #4.1).
 *
 * The repo's installed @vue/test-utils is the Vue 3 build while the app runs
 * on Vue 2.7, so (mirroring AvgIndex.spec.js / TranslationStatusChip.spec.js)
 * we exercise the component's options object — computed getters + methods —
 * directly against a synthetic `this` context rather than mounting.
 */

// Mock the store singletons the component imports so we can assert dispatches.
import SearchIndex from './SearchIndex.vue'
import {
	objectStore as mockObjectStore,
	viewsStore as mockViewsStore,
} from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn(), modal: null },
	objectStore: {},
	registerStore: { registerList: [] },
	schemaStore: { schemaList: [] },
	viewsStore: {},
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (_app, text) => text,
}))
jest.mock(
	'@nextcloud/vue',
	() =>
		new Proxy(
			{ __esModule: true },
			{
				get: (target, prop) =>
					prop in target
						? target[prop]
						: { name: String(prop), render: () => null },
			},
		),
)
jest.mock(
	'vue-material-design-icons/Pencil.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ContentCopy.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/TrashCanOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)

const computedCall = (key, ctx) => SearchIndex.computed[key].call(ctx)
const methodCall = (key, ctx, ...args) => SearchIndex.methods[key].call(ctx, ...args)

describe('SearchIndex — presentation dispatch (REQ-VIEW-PRES-05)', () => {
	beforeEach(() => {
		Object.assign(mockObjectStore, {
			searchCollection: [],
			searchParams: {
				register: null,
				schema: null,
				sortKey: null,
				sortOrder: null,
			},
			searchRegister: 'reg-1',
			searchSchema: 'schema-1',
			searchLoading: false,
			searchPagination: { total: 0, page: 1, pages: 1, limit: 20 },
			searchVisibleColumns: [],
			selectedObjects: [],
			errors: {},
			createObjectTypeSlug: (...parts) => parts.join('-'),
			saveObject: jest.fn(),
			setObjectItem: jest.fn(),
			setSelectedObjects: jest.fn(),
			refetchSearchCollection: jest.fn(),
			updateSearchParams: jest.fn(),
		})
		Object.assign(mockViewsStore, {
			activeView: null,
			fetchKanbanBoard: jest.fn(),
			fetchCalendarObjects: jest.fn(),
		})
	})

	it('presentationType is "table" when there is no active view', () => {
		expect(computedCall('presentationType', { activeView: null })).toBe('table')
	})

	it('presentationType is "table" for a legacy view with no presentation block (REQ-VIEW-PRES-01)', () => {
		const ctx = { activeView: { id: 'v1', name: 'Legacy view' } }
		expect(computedCall('presentationType', ctx)).toBe('table')
	})

	it('presentationType reflects a kanban view', () => {
		const ctx = {
			activeView: {
				id: 'v1',
				presentation: {
					viewType: 'kanban',
					kanban: { groupByField: 'status' },
				},
			},
		}
		expect(computedCall('presentationType', ctx)).toBe('kanban')
	})

	it('presentationType reflects a calendar view', () => {
		const ctx = {
			activeView: {
				id: 'v1',
				presentation: {
					viewType: 'calendar',
					calendar: { dateField: 'dueDate' },
				},
			},
		}
		expect(computedCall('presentationType', ctx)).toBe('calendar')
	})

	it('activeViewId falls back to uuid when id is absent', () => {
		expect(computedCall('activeViewId', { activeView: { uuid: 'u-1' } })).toBe(
			'u-1',
		)
		expect(computedCall('activeViewId', { activeView: null })).toBe(null)
	})

	it("normalizedKanbanColumns normalizes each column's cards (top-level id from @self.id)", () => {
		const ctx = {
			kanbanBoard: {
				columns: [
					{
						value: 'todo',
						cards: [{ '@self': { id: 'obj-1' }, title: 'Card 1' }],
						total: 1,
						limit: 20,
						offset: 0,
					},
					{
						value: 'done',
						cards: [{ id: 'obj-2', title: 'Card 2' }],
						total: 1,
						limit: 20,
						offset: 0,
					},
				],
			},
		}
		const columns = computedCall('normalizedKanbanColumns', ctx)
		expect(columns[0].cards[0].id).toBe('obj-1')
		expect(columns[1].cards[0].id).toBe('obj-2')
		// Non-card column fields (value/total/limit/offset) pass through untouched.
		expect(columns[0].value).toBe('todo')
		expect(columns[0].total).toBe(1)
	})

	it('normalizedKanbanColumns is empty when there is no board yet', () => {
		expect(
			computedCall('normalizedKanbanColumns', { kanbanBoard: null }),
		).toEqual([])
	})

	it('normalizedCalendarObjects normalizes objects the same way as the table', () => {
		const ctx = {
			calendarObjects: [{ '@self': { id: 'obj-3' }, dueDate: '2026-07-24' }],
		}
		expect(computedCall('normalizedCalendarObjects', ctx)[0].id).toBe('obj-3')
	})

	describe('handleKanbanMove (REQ-VIEW-KANBAN-03)', () => {
		it('on a legal move, saves the full object (PUT-semantic) and resolves the optimistic move', async () => {
			const card = {
				id: 'obj-1',
				'@self': { id: 'obj-1' },
				status: 'todo',
				title: 'Card 1',
			}
			mockObjectStore.saveObject.mockResolvedValue({
				...card,
				status: 'doing',
			})
			const resolveMove = jest.fn()
			const ctx = {
				computedObjectType: 'reg-1-schema-1',
				$refs: { kanban: { resolveMove, rejectMove: jest.fn() } },
			}

			await methodCall('handleKanbanMove', ctx, {
				object: card,
				groupByField: 'status',
				fromValue: 'todo',
				toValue: 'doing',
			})

			// Every existing field is carried forward — only groupByField changes.
			expect(mockObjectStore.saveObject).toHaveBeenCalledWith(
				'reg-1-schema-1',
				{ ...card, status: 'doing' },
			)
			expect(resolveMove).toHaveBeenCalledWith('obj-1')
			expect(ctx.$refs.kanban.rejectMove).not.toHaveBeenCalled()
		})

		it("on an illegal lifecycle transition, rejects with the server's reason so the card snaps back", async () => {
			const card = { id: 'obj-2', status: 'done', title: 'Card 2' }
			mockObjectStore.saveObject.mockResolvedValue(null)
			mockObjectStore.errors = {
				'reg-1-schema-1': { message: 'Illegal transition: done -> todo' },
			}
			const rejectMove = jest.fn()
			const ctx = {
				computedObjectType: 'reg-1-schema-1',
				$refs: { kanban: { resolveMove: jest.fn(), rejectMove } },
			}

			await methodCall('handleKanbanMove', ctx, {
				object: card,
				groupByField: 'status',
				fromValue: 'done',
				toValue: 'todo',
			})

			expect(rejectMove).toHaveBeenCalledWith(
				'obj-2',
				'Illegal transition: done -> todo',
			)
			expect(ctx.$refs.kanban.resolveMove).not.toHaveBeenCalled()
		})

		it('falls back to a generic reason when the server error carries no message', async () => {
			mockObjectStore.saveObject.mockResolvedValue(null)
			mockObjectStore.errors = {}
			const rejectMove = jest.fn()
			const ctx = {
				computedObjectType: 'reg-1-schema-1',
				$refs: { kanban: { resolveMove: jest.fn(), rejectMove } },
			}

			await methodCall('handleKanbanMove', ctx, {
				object: { id: 'obj-3' },
				groupByField: 'status',
				toValue: 'todo',
			})

			expect(rejectMove).toHaveBeenCalledWith('obj-3', expect.any(String))
		})
	})

	describe('handleKanbanLoadMore (REQ-VIEW-KANBAN-02)', () => {
		it('refetches the board at a larger limit and swaps in only the requesting column', async () => {
			mockViewsStore.fetchKanbanBoard.mockResolvedValue({
				columns: [
					{
						value: 'todo',
						cards: [{ id: '1' }, { id: '2' }],
						total: 5,
						limit: 40,
						offset: 0,
					},
					{
						value: 'done',
						cards: [{ id: '9' }],
						total: 1,
						limit: 40,
						offset: 0,
					},
				],
			})
			const ctx = {
				activeViewId: 'v1',
				loadingColumns: [],
				kanbanBoard: {
					columns: [
						{
							value: 'todo',
							cards: [{ id: '1' }],
							total: 5,
							limit: 20,
							offset: 0,
						},
						{
							value: 'done',
							cards: [{ id: '9' }],
							total: 1,
							limit: 20,
							offset: 0,
						},
					],
				},
			}

			await methodCall('handleKanbanLoadMore', ctx, {
				value: 'todo',
				offset: 1,
			})

			expect(mockViewsStore.fetchKanbanBoard).toHaveBeenCalledWith('v1', {
				_limit: 40,
			})
			// Only the 'todo' column was replaced with the refetched result.
			expect(
				ctx.kanbanBoard.columns.find((c) => c.value === 'todo').cards,
			).toHaveLength(2)
			expect(
				ctx.kanbanBoard.columns.find((c) => c.value === 'done').cards,
			).toHaveLength(1)
			// The loading marker is cleared again once the fetch settles.
			expect(ctx.loadingColumns).not.toContain('todo')
		})

		it('does nothing when there is no active view', async () => {
			const ctx = { activeViewId: null, loadingColumns: [], kanbanBoard: null }
			await methodCall('handleKanbanLoadMore', ctx, {
				value: 'todo',
				offset: 0,
			})
			expect(mockViewsStore.fetchKanbanBoard).not.toHaveBeenCalled()
		})
	})

	describe('handleCalendarRangeChange (REQ-VIEW-CAL-04)', () => {
		it('fetches objects for the visible range and stores them', async () => {
			mockViewsStore.fetchCalendarObjects.mockResolvedValue({
				objects: [{ id: 'o1', dueDate: '2026-07-10' }],
			})
			const ctx = {
				activeViewId: 'v2',
				calendarLoading: false,
				calendarObjects: [],
			}

			await methodCall('handleCalendarRangeChange', ctx, {
				rangeStart: '2026-07-01',
				rangeEnd: '2026-07-31',
			})

			expect(mockViewsStore.fetchCalendarObjects).toHaveBeenCalledWith(
				'v2',
				'2026-07-01',
				'2026-07-31',
			)
			expect(ctx.calendarObjects).toEqual([
				{ id: 'o1', dueDate: '2026-07-10' },
			])
			expect(ctx.calendarLoading).toBe(false)
		})
	})

	describe('activeViewId watcher', () => {
		it('resets kanban/calendar state and fetches the board on switching to a kanban view', () => {
			const ctx = {
				kanbanBoard: { columns: [] },
				loadingColumns: ['todo'],
				calendarObjects: [{ id: 'stale' }],
				presentationType: 'kanban',
				fetchKanbanBoard: jest.fn(),
			}

			SearchIndex.watch.activeViewId.call(ctx, 'v3')

			expect(ctx.kanbanBoard).toBe(null)
			expect(ctx.loadingColumns).toEqual([])
			expect(ctx.calendarObjects).toEqual([])
			expect(ctx.fetchKanbanBoard).toHaveBeenCalled()
		})

		it('does not fetch a board when switching to a calendar (or table) view', () => {
			const ctx = {
				kanbanBoard: { columns: [] },
				loadingColumns: [],
				calendarObjects: [],
				presentationType: 'calendar',
				fetchKanbanBoard: jest.fn(),
			}

			SearchIndex.watch.activeViewId.call(ctx, 'v4')

			expect(ctx.fetchKanbanBoard).not.toHaveBeenCalled()
		})
	})
})
