/**
 * @spec exclude Client-side helper of the bulk copy on the object list.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { copyRows } from './objectCopy.js'

const rows = [
	{ id: 'a', title: 'One', nested: { n: 1 }, '@self': { id: 'a', register: 1 } },
	{ id: 'b', title: 'Two' },
]
const request = (save, ids = ['a', 'b']) => ({
	rows,
	ids,
	nameOf: (row) => `Copy of ${row.title}`,
	save,
})

describe('copyRows', () => {
	it('saves each copy without the identity of its row, under the new name', async () => {
		const save = jest.fn(async () => ({ id: 'new' }))

		const outcome = await copyRows(request(save))

		expect(save.mock.calls.map(([copy]) => copy)).toEqual([
			{
				title: 'Copy of One',
				nested: { n: 1 },
				'@self': { register: 1, name: 'Copy of One' },
			},
			{ title: 'Copy of Two' },
		])
		expect(outcome).toEqual({
			success: true,
			successfulIds: ['a', 'b'],
			failedIds: [],
		})
	})

	it('leaves the rows of the page as they were', async () => {
		const save = jest.fn(async (copy) => {
			copy.nested.n = 2
			return copy
		})

		await copyRows(request(save, ['a']))

		expect(rows[0].nested.n).toBe(1)
		expect(rows[0]['@self'].id).toBe('a')
	})

	it('keeps going after a failed save and names what failed', async () => {
		const save = jest
			.fn()
			.mockResolvedValueOnce(null)
			.mockResolvedValueOnce({ id: 'new' })

		const outcome = await copyRows(request(save))

		expect(outcome).toEqual({
			success: false,
			successfulIds: ['b'],
			failedIds: ['a'],
		})
	})

	it('counts an id that is not on the page as failed', async () => {
		const save = jest.fn()

		const outcome = await copyRows(request(save, ['gone']))

		expect(save).not.toHaveBeenCalled()
		expect(outcome.failedIds).toEqual(['gone'])
	})
})
