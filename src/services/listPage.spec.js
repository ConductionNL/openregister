import { getListPage, listPageQuery, listPagination } from './listPage.js'

describe('listPage', () => {
	describe('listPageQuery', () => {
		it('puts paging, search, filters and sort in that order', () => {
			expect(
				listPageQuery(
					{
						limit: 5,
						offset: 10,
						page: 3,
						search: 's',
						filters: { b: 2 },
						sort: { x: 'ASC', y: 'DESC' },
					},
					{ search: 'stored', filters: { a: 1 }, sort: { z: 'ASC' } },
				),
			).toBe(
				'limit=5&offset=10&page=3&search=s&a=1&b=2&sort=x&order=ASC&sort=y&order=DESC',
			)
		})

		it('falls back to the store search and sort and drops empty filters', () => {
			expect(
				listPageQuery(
					{ limit: 0, filters: { a: '', b: null, c: undefined, d: 0 } },
					{ search: 'stored', filters: { e: 'x' }, sort: { z: 'ASC' } },
				),
			).toBe('search=stored&e=x&d=0&sort=z&order=ASC')
		})

		it('is empty when nothing is set', () => {
			expect(listPageQuery({}, {})).toBe('')
		})
	})

	describe('listPagination', () => {
		it('takes the answer values', () => {
			expect(
				listPagination(
					{ total: 9, page: 2, pages: 3, limit: 4, offset: 4 },
					50,
				),
			).toEqual({ total: 9, page: 2, pages: 3, limit: 4, offset: 4 })
		})

		it('defaults every missing value', () => {
			expect(listPagination({}, 20)).toEqual({
				total: 0,
				page: 1,
				pages: 1,
				limit: 20,
				offset: 0,
			})
		})
	})

	describe('getListPage', () => {
		afterEach(() => fetch.mockReset())

		it('GETs the url with the request token and returns the body', async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: async () => ({ results: [] }),
			})
			await expect(getListPage('/x?a=1', 'nope')).resolves.toEqual({
				results: [],
			})
			expect(fetch).toHaveBeenCalledWith('/x?a=1', {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
			})
		})

		it('throws the error the body names, else the fallback', async () => {
			fetch.mockResolvedValueOnce({
				ok: false,
				json: async () => ({ error: 'named' }),
			})
			await expect(getListPage('/x', 'fallback')).rejects.toThrow('named')
			fetch.mockResolvedValueOnce({ ok: false, json: async () => ({}) })
			await expect(getListPage('/x', 'fallback')).rejects.toThrow('fallback')
		})
	})
})
