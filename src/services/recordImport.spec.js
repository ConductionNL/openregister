import axios from '@nextcloud/axios'
import {
	dryRunImport,
	failedRowsFile,
	initialDecisions,
	isRecordFile,
	proposeMapping,
	runImport,
	undecidedColumns,
} from './recordImport.js'

// The import surface: which routes it calls with which body, and the pure helpers around them.

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (path, params) => path.replace('{register}', params.register).replace('{schema}', params.schema),
}))

/**
 * A record file.
 *
 * @param {string} name The file name
 * @return {File} The file
 */
function file(name = 'people.csv') {
	return new File(['reference,name\nA1,Alpha\n'], name, { type: 'text/csv' })
}

describe('isRecordFile', () => {
	it.each(['a.csv', 'b.TSV', 'c.tab', 'd.xlsx', 'e.jsonl', 'f.ndjson'])('accepts %s', (name) => {
		expect(isRecordFile({ name })).toBe(true)
	})

	it.each(['a.pdf', 'csv', 'a.csv.zip', '', null])('refuses %s', (name) => {
		expect(isRecordFile(name === null ? null : { name })).toBe(false)
	})
})

describe('the routes', () => {
	beforeEach(() => axios.post.mockReset())

	it('asks for the mapping with the file only', async () => {
		axios.post.mockResolvedValue({ data: { columns: ['reference'] } })

		const proposal = await proposeMapping({ register: 5, schema: 'people', file: file() })

		expect(proposal).toEqual({ columns: ['reference'] })
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/record-import/5/people/mapping')
		expect(body.get('file').name).toBe('people.csv')
		expect(body.has('decisions')).toBe(false)
	})

	it('dry-runs with the decisions and identifier', async () => {
		axios.post.mockResolvedValue({ data: { dryRun: true } })

		await dryRunImport({ register: 5, schema: 7, file: file(), decisions: { reference: 'reference', note: null }, identifier: 'reference' })

		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/record-import/5/7/dry-run')
		expect(JSON.parse(body.get('decisions'))).toEqual({ reference: 'reference', note: null })
		expect(body.get('identifier')).toBe('reference')
	})

	it('imports on the bare route, with an empty identifier for create-only', async () => {
		axios.post.mockResolvedValue({ data: { createdCount: 2 } })

		const result = await runImport({ register: 5, schema: 7, file: file(), decisions: {}, identifier: null })

		expect(result).toEqual({ createdCount: 2 })
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/record-import/5/7')
		expect(body.get('identifier')).toBe('')
	})

	it('reports upload progress as a fraction, and ignores an unknown total', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const seen = []

		await runImport({ register: 5, schema: 7, file: file(), decisions: {}, identifier: null, onProgress: (fraction) => seen.push(fraction) })
		const { onUploadProgress } = axios.post.mock.calls[0][2]
		onUploadProgress({ loaded: 50, total: 200 })
		onUploadProgress({ loaded: 10, total: 0 })
		onUploadProgress({ loaded: 300, total: 200 })

		expect(seen).toEqual([0.25, 1])
	})

	it('sends no progress option without a callback', async () => {
		axios.post.mockResolvedValue({ data: {} })

		await proposeMapping({ register: 5, schema: 7, file: file() })

		expect(axios.post.mock.calls[0][2]).toEqual({})
	})
})

describe('decisions', () => {
	it('starts from the proposal and leaves unmatched columns undecided', () => {
		const decisions = initialDecisions({ columns: ['ref', 'postcode'], mapped: { ref: 'reference' } })

		expect(decisions).toEqual({ ref: 'reference', postcode: undefined })
		expect(undecidedColumns(decisions)).toEqual(['postcode'])
	})

	it('counts an ignored column as decided', () => {
		expect(undecidedColumns({ ref: 'reference', postcode: null, other: '' })).toEqual(['other'])
	})
})

describe('failedRowsFile', () => {
	it('names the CSV after the imported file', async () => {
		const exported = failedRowsFile({ failedRows: 'reference,reason\nA2,invalid\n' }, 'people.xlsx')

		expect(exported.name).toBe('people-failed.csv')
		expect(exported.blob.type).toBe('text/csv;charset=utf-8')
		expect(exported.blob.size).toBe('reference,reason\nA2,invalid\n'.length)
	})

	it('is null when nothing failed', () => {
		expect(failedRowsFile({ failedCount: 0 }, 'people.csv')).toBeNull()
		expect(failedRowsFile(null, 'people.csv')).toBeNull()
	})
})
