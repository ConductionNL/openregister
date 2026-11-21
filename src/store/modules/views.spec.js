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

	describe('view save carries the presentation and surfaces refusals (saved-view-presentation-picker)', () => {
		it('POSTs the presentation with a created view', async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: async () => ({ view: { id: 'v9' } }),
			})

			await store.createView({
				name: 'Board',
				presentation: { viewType: 'kanban', kanban: { groupByField: 'status' } },
			})

			const [, options] = fetch.mock.calls[0]
			expect(JSON.parse(options.body).presentation).toEqual({
				viewType: 'kanban',
				kanban: { groupByField: 'status' },
			})
		})

		it('PUTs the presentation with an updated view, keeping it through cleanViewForSave', async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: async () => ({ view: { id: 'v9' } }),
			})

			await store.updateView('v9', {
				id: 'v9',
				name: 'Board',
				presentation: { viewType: 'calendar', calendar: { dateField: 'ontvangst' } },
			})

			const [, options] = fetch.mock.calls[0]
			const sent = JSON.parse(options.body)
			expect(sent.presentation).toEqual({ viewType: 'calendar', calendar: { dateField: 'ontvangst' } })
			expect(sent.id).toBeUndefined()
		})

		it('surfaces the server error body on a refused save, so the field message is not lost', async () => {
			fetch.mockResolvedValueOnce({
				ok: false,
				status: 400,
				json: async () => ({ error: 'A calendar presentation requires a non-empty calendar.dateField' }),
			})

			await expect(
				store.updateView('v9', { name: 'Bad', presentation: { viewType: 'calendar', calendar: {} } }),
			).rejects.toMatchObject({
				status: 400,
				body: { error: 'A calendar presentation requires a non-empty calendar.dateField' },
				message: 'A calendar presentation requires a non-empty calendar.dateField',
			})
		})
	})

})
