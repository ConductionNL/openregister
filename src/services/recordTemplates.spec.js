/**
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import {
	applyTemplate,
	belongsToOneRecord,
	fetchRecordTemplates,
	fitsProperty,
	saveRecordTemplate,
	templateFieldChoices,
	templateSharing,
	templateValues,
	valueType,
} from './recordTemplates.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
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
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

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

	describe('saving a record as a template', () => {
		const schema = {
			properties: {
				categorie: { type: 'string', title: 'Categorie' },
				prioriteit: { type: 'integer', title: 'Prioriteit' },
				omschrijving: { type: 'string' },
				zaaknummer: { type: 'string', format: 'uuid' },
				gemeld: { type: 'string', format: 'date-time' },
				melder: { type: 'object', $ref: '#/components/schemas/persoon' },
				bijlagen: { type: 'array', items: { type: 'file' } },
				adres: { type: 'string' },
			},
		}
		const melding = {
			'@self': { id: 'm1', register: 3, schema: 9 },
			id: 'm1',
			categorie: 'Wateroverlast',
			prioriteit: 2,
			omschrijving: 'Water in de kelder',
			zaaknummer: '0b3c3f0e-1111-4c1a-9a51-6f4b1e0d2a10',
			gemeld: '2026-11-01T10:00:00Z',
			melder: 'p-7',
			bijlagen: [12],
			adres: '',
		}

		it('offers the fields with a value, identity, dates, relations and files unticked', () => {
			expect(templateFieldChoices(melding, schema)).toEqual([
				{ field: 'categorie', label: 'Categorie', keep: true },
				{ field: 'prioriteit', label: 'Prioriteit', keep: true },
				{ field: 'omschrijving', label: 'omschrijving', keep: true },
				{ field: 'zaaknummer', label: 'zaaknummer', keep: false },
				{ field: 'gemeld', label: 'gemeld', keep: false },
				{ field: 'melder', label: 'melder', keep: false },
				{ field: 'bijlagen', label: 'bijlagen', keep: false },
			])
		})

		it('falls back to the record\'s own keys without a schema, never its metadata', () => {
			expect(templateFieldChoices({ '@self': {}, _score: 1, id: 'x', uuid: 'y', soort: 'a', leeg: null }, null)).toEqual([
				{ field: 'soort', label: 'soort', keep: true },
			])
		})

		it('tells which properties belong to one record', () => {
			expect(belongsToOneRecord('uuid', undefined)).toBe(true)
			expect(belongsToOneRecord('identifier', { type: 'string' })).toBe(true)
			expect(belongsToOneRecord('dossier', { type: 'string', inversedBy: 'meldingen' })).toBe(true)
			expect(belongsToOneRecord('link', { type: 'string', format: 'uri' })).toBe(true)
			expect(belongsToOneRecord('foto', { type: 'file' })).toBe(true)
			expect(belongsToOneRecord('categorie', { type: 'string' })).toBe(false)
			expect(belongsToOneRecord('vrij', undefined)).toBe(false)
		})

		it('keeps a copy of the chosen values only', () => {
			const values = templateValues(melding, ['categorie', 'bijlagen', 'id', 'onbekend'])
			expect(values).toEqual({ categorie: 'Wateroverlast', bijlagen: [12] })
			values.bijlagen.push(13)
			expect(melding.bijlagen).toEqual([12])
		})

		it('shares with nobody, one group to use, or everyone', () => {
			expect(templateSharing('private')).toEqual({ isPublic: false, sharedWith: [] })
			expect(templateSharing('group', ' kcc ')).toEqual({ isPublic: false, sharedWith: [{ group: 'kcc', mode: 'read' }] })
			expect(templateSharing('group', '  ')).toEqual({ isPublic: false, sharedWith: [] })
			expect(templateSharing('public', 'kcc')).toEqual({ isPublic: true, sharedWith: [] })
		})

		it('posts the template and passes the server\'s refusal on', async () => {
			const template = { name: 'Melding wateroverlast', register: '3', schema: '9', values: { categorie: 'Wateroverlast' }, isPublic: false, sharedWith: [] }
			axios.post.mockResolvedValueOnce({ data: { id: 't1', ...template } })
			await expect(saveRecordTemplate(template)).resolves.toEqual({ id: 't1', ...template })
			expect(axios.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/record-templates', template)

			axios.post.mockRejectedValueOnce({ response: { data: { error: 'You may not create records in this schema.' } } })
			await expect(saveRecordTemplate(template)).rejects.toThrow('You may not create records in this schema.')
			axios.post.mockRejectedValueOnce({})
			await expect(saveRecordTemplate(template)).rejects.toThrow('')
		})
	})
})
