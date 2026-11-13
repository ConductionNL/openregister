/**
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { applyTemplate, fetchRecordTemplates, fitsProperty, valueType } from './recordTemplates.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))

const melding = {
	properties: {
		categorie: { type: 'string' },
		prioriteit: { type: 'integer' },
		bedrag: { type: 'number' },
		spoed: { type: 'boolean' },
		labels: { type: 'array' },
		bijlage: { type: 'file' },
		vrij: {},
	},
}

describe('record templates', () => {
	beforeEach(() => axios.get.mockReset())

	it('keeps the values that still fit and drops, and names, the ones that do not', () => {
		const { values, dropped } = applyTemplate(
			{
				values: {
					categorie: 'Afval',
					prioriteit: 2,
					bedrag: 3,
					spoed: 'ja',
					labels: ['straat'],
					wijk: 'Noord',
					vrij: { a: 1 },
					bijlage: 17,
					'@self': { id: 'x' },
					id: 'old',
				},
			},
			melding,
		)

		expect(values).toEqual({ categorie: 'Afval', prioriteit: 2, bedrag: 3, labels: ['straat'], vrij: { a: 1 }, bijlage: 17 })
		expect(dropped).toEqual([
			{ field: 'spoed', reason: 'type' },
			{ field: 'wijk', reason: 'missing' },
		])
	})

	it('reads the type of a value and whether it fits a property', () => {
		expect([null, [], 1, 1.5, 'a', true, {}].map(valueType)).toEqual(['null', 'array', 'integer', 'number', 'string', 'boolean', 'object'])
		expect(fitsProperty(1.5, { type: 'integer' })).toBe(false)
		expect(fitsProperty(1, { type: ['string', 'integer'] })).toBe(true)
		expect(fitsProperty(null, { type: 'string' })).toBe(true)
		expect(fitsProperty(true, { type: 'file' })).toBe(false)
		expect(applyTemplate(null, melding)).toEqual({ values: {}, dropped: [] })
	})

	it('lists the templates of a register and schema, none when it cannot', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 't1', name: 'Afval' }], total: 1 } })
		await expect(fetchRecordTemplates({ register: 3, schema: 9 })).resolves.toEqual([{ id: 't1', name: 'Afval' }])
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/record-templates', { params: { register: 3, schema: 9 } })

		axios.get.mockRejectedValueOnce(new Error('403'))
		await expect(fetchRecordTemplates({ register: 3, schema: 9 })).resolves.toEqual([])
		axios.get.mockResolvedValueOnce({ data: {} })
		await expect(fetchRecordTemplates({ register: 3, schema: 9 })).resolves.toEqual([])
		await expect(fetchRecordTemplates({ register: null, schema: 9 })).resolves.toEqual([])
		expect(axios.get).toHaveBeenCalledTimes(3)
	})
})
