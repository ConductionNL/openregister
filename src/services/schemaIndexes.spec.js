/**
 * The schema index read: its URL and the defaults for a field the server left out.
 *
 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-the-property-editor-offers-both-index-kinds
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { fetchSchemaIndexes } from './schemaIndexes.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (path, vars = {}) => path.replace(/\{(\w+)\}/g, (match, key) => vars[key]),
}))

describe('fetchSchemaIndexes', () => {
	it('reads the schema\'s index list and passes its envelope on', async () => {
		const results = [{ register: 3, indexes: [] }]
		axios.get.mockResolvedValue({ data: { results, textSearchIndex: true } })

		expect(await fetchSchemaIndexes(12)).toEqual({ results, textSearchIndex: true })
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/schemas/12/indexes')
	})

	it('reads a missing list as none and a missing flag as no text-search index', async () => {
		axios.get.mockResolvedValue({ data: {} })

		expect(await fetchSchemaIndexes('zaken')).toEqual({ results: [], textSearchIndex: false })
	})
})
