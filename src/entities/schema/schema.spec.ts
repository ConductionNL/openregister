import { Schema } from './schema'
import { mockSchemaData } from './schema.mock'

describe('Schema Entity', () => {
	it('should create a Schema entity with full data', () => {
		const data = mockSchemaData()[0]
		const schema = new Schema(data)

		expect(schema).toBeInstanceOf(Schema)
		// Field-by-field, not deep equal: deep equal trips on the
		// Schema prototype + the configuration default the constructor
		// adds when the mock omits it.
		expect(schema.id).toBe(data.id)
		expect(schema.title).toBe(data.title)
		expect(schema.slug).toBe(data.slug)
		expect(schema.version).toBe(data.version)
		expect(schema.validate().success).toBe(true)
	})

	it('should default scalar fields when given partial data', () => {
		const schema = new Schema({} as any)

		expect(schema).toBeInstanceOf(Schema)
		expect(schema.id).toBe('')
		expect(schema.title).toBe('')
		expect(schema.slug).toBe('')
		expect(schema.required).toEqual([])
		expect(schema.properties).toEqual({})
		expect(schema.hardValidation).toBe(false)
		expect(schema.maxDepth).toBe(0)
		// id/title/slug are min(1) → partial data must fail validation.
		expect(schema.validate().success).toBe(false)
	})

	it('should fail validation with malformed version', () => {
		const schema = new Schema({
			...mockSchemaData()[0],
			version: 'not-a-semver',
		})

		expect(schema).toBeInstanceOf(Schema)
		expect(schema.validate().success).toBe(false)
	})

	it('should expose stats from the mock', () => {
		const schema = new Schema(mockSchemaData()[0])
		expect(schema.stats).toBeDefined()
		expect(schema.stats?.objects?.total).toBe(10)
		expect(schema.stats?.logs?.total).toBe(2)
		expect(schema.stats?.files?.size).toBe(128)
	})
})
