/**
 * The schema operations a register's OpenAPI document lists, and the request
 * values the API page reads from a reader.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
 */
import {
	isComplete,
	operationsFromOas,
	parseBody,
	pathParameters,
	serverUrl,
} from './registerApiOperations.js'

const OAS = {
	servers: [{ url: 'https://cloud.example.org/apps/openregister/api' }],
	paths: {
		'/objects/zaken/person': {
			get: {
				summary: 'Get all Person objects',
				operationId: 'getAllPerson',
				tags: ['Person'],
			},
			post: {
				summary: 'Create a new Person object',
				operationId: 'createPerson',
				tags: ['Person'],
			},
		},
		'/objects/zaken/person/{id}': {
			parameters: [{ name: 'id', in: 'path' }],
			delete: { summary: 'Delete a Person object', tags: ['Person'] },
			get: { summary: 'Get a Person object', tags: ['Person'] },
			patch: { summary: 'Patch a Person object', tags: ['Person'] },
			put: { summary: 'Update a Person object', tags: ['Person'] },
		},
		'/objects/zaken/case': {
			get: { summary: 'Get all Case objects', tags: ['Case'] },
		},
		'/contexts/zaken': {
			get: { summary: 'JSON-LD context for zaken', tags: ['JSON-LD'] },
		},
	},
}

describe('operationsFromOas', () => {
	it('lists every schema operation under its schema, in document and method order', () => {
		const groups = operationsFromOas(OAS)

		expect(groups.map((group) => group.tag)).toEqual(['Person', 'Case'])
		expect(groups[0].operations.map((operation) => operation.key)).toEqual([
			'get /objects/zaken/person',
			'post /objects/zaken/person',
			'get /objects/zaken/person/{id}',
			'put /objects/zaken/person/{id}',
			'patch /objects/zaken/person/{id}',
			'delete /objects/zaken/person/{id}',
		])
	})

	it('describes an operation by its method, path, summary, path parameters and body', () => {
		const [person] = operationsFromOas(OAS)

		expect(person.operations[1]).toEqual({
			key: 'post /objects/zaken/person',
			method: 'POST',
			path: '/objects/zaken/person',
			summary: 'Create a new Person object',
			operationId: 'createPerson',
			pathParams: [],
			hasBody: true,
		})
		expect(person.operations[5]).toMatchObject({
			method: 'DELETE',
			pathParams: ['id'],
			hasBody: false,
			operationId: '',
		})
	})

	it('leaves out the paths that are not object routes, and the path item fields', () => {
		const groups = operationsFromOas(OAS)
		const paths = groups.flatMap((group) =>
			group.operations.map((operation) => operation.path),
		)

		expect(paths).not.toContain('/contexts/zaken')
		expect(
			groups
				.flatMap((group) => group.operations)
				.every((operation) => operation.method !== 'PARAMETERS'),
		).toBe(true)
	})

	it('groups an untagged operation under its path and reads nothing from an empty document', () => {
		expect(
			operationsFromOas({ paths: { '/objects/a/b': { get: {} } } }),
		).toEqual([
			{
				tag: '/objects/a/b',
				operations: [
					expect.objectContaining({ method: 'GET', summary: '' }),
				],
			},
		])
		expect(operationsFromOas(null)).toEqual([])
		expect(operationsFromOas({ paths: { '/objects/a/b': null } })).toEqual([])
	})
})

describe('request values', () => {
	it('names the path parameters in order', () => {
		expect(pathParameters('/objects/{register}/{schema}/{id}')).toEqual([
			'register',
			'schema',
			'id',
		])
		expect(pathParameters('/objects/zaken/person')).toEqual([])
	})

	it('takes the first server of the document, or the fallback', () => {
		expect(serverUrl(OAS, 'x')).toBe(
			'https://cloud.example.org/apps/openregister/api',
		)
		expect(serverUrl({ servers: [] }, 'https://here/api')).toBe(
			'https://here/api',
		)
		expect(serverUrl({ servers: [{ url: '' }] }, 'https://here/api')).toBe(
			'https://here/api',
		)
	})

	it('parses a typed body, treats an empty one as none and refuses one that is not JSON', () => {
		expect(parseBody('{"name": "Ada"}')).toEqual({
			ok: true,
			value: { name: 'Ada' },
		})
		expect(parseBody('  ')).toEqual({ ok: true, value: undefined })
		expect(parseBody(undefined)).toEqual({ ok: true, value: undefined })
		expect(parseBody('{name:')).toEqual({ ok: false, value: undefined })
	})

	it('is complete only when every path parameter has a value', () => {
		const operation = { pathParams: ['id'] }

		expect(isComplete(operation, { id: '7' })).toBe(true)
		expect(isComplete(operation, { id: ' ' })).toBe(false)
		expect(isComplete(operation, undefined)).toBe(false)
		expect(isComplete({ pathParams: [] }, undefined)).toBe(true)
	})
})
