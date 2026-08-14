import { createPinia, setActivePinia } from 'pinia'
import { useViewsStore } from './views.js'

// Mock fetch globally
global.fetch = jest.fn()

describe('Views Store — kanban/calendar presentation fetches (object-views-kanban-calendar)', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useViewsStore()
		jest.clearAllMocks()
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	describe('fetchKanbanBoard (REQ-VIEW-KANBAN-02)', () => {
		it('GETs the kanban endpoint and returns the board as-is', async () => {
			const board = {
				viewType: 'kanban',
				groupByField: 'status',
				columns: [
					{ value: 'todo', cards: [], total: 0, limit: 20, offset: 0 },
				],
			}
			fetch.mockResolvedValueOnce({ ok: true, json: async () => board })

			const result = await store.fetchKanbanBoard('view-1')

			expect(fetch).toHaveBeenCalledWith(
				'/index.php/apps/openregister/api/views/view-1/kanban',
				expect.objectContaining({ method: 'GET' }),
			)
			expect(result).toEqual(board)
			expect(store.loading).toBe(false)
		})

		it('forwards _limit/_offset as query params', async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: async () => ({ columns: [] }),
			})

			await store.fetchKanbanBoard('view-1', { _limit: 40, _offset: 20 })

			const [url] = fetch.mock.calls[0]
			expect(url).toContain('_limit=40')
			expect(url).toContain('_offset=20')
		})

		it('throws with the server-provided error message on a non-OK response', async () => {
			fetch.mockResolvedValueOnce({
				ok: false,
				status: 400,
				json: async () => ({
					error: 'Kanban view is missing kanban.groupByField',
				}),
			})

			await expect(store.fetchKanbanBoard('view-1')).rejects.toThrow(
				'Kanban view is missing kanban.groupByField',
			)
			expect(store.error).toBe('Kanban view is missing kanban.groupByField')
		})
	})

	describe('fetchCalendarObjects (REQ-VIEW-CAL-04)', () => {
		it('GETs the calendar endpoint with the visible range and returns the result as-is', async () => {
			const result = {
				viewType: 'calendar',
				dateField: 'dueDate',
				endDateField: null,
				rangeStart: '2026-07-01',
				rangeEnd: '2026-07-31',
				objects: [],
				total: 0,
			}
			fetch.mockResolvedValueOnce({ ok: true, json: async () => result })

			const data = await store.fetchCalendarObjects(
				'view-2',
				'2026-07-01',
				'2026-07-31',
			)

			const [url, options] = fetch.mock.calls[0]
			expect(url).toContain('/views/view-2/calendar')
			expect(url).toContain('start=2026-07-01')
			expect(url).toContain('end=2026-07-31')
			expect(options.method).toBe('GET')
			expect(data).toEqual(result)
		})

		it('throws with the server-provided error message on a non-OK response', async () => {
			fetch.mockResolvedValueOnce({
				ok: false,
				status: 400,
				json: async () => ({
					error: 'Both start and end (the visible date range) are required',
				}),
			})

			await expect(
				store.fetchCalendarObjects('view-2', '', ''),
			).rejects.toThrow(
				'Both start and end (the visible date range) are required',
			)
		})
	})
})
