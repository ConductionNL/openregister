import { Schema } from './schema'
import { mockSchemaData } from './schema.mock'

describe('Schema Entity', () => {
	it('should create a Schema entity with full data', () => {
		const schema = new Schema(mockSchemaData()[0])

		expect(schema).toBeInstanceOf(Schema)
		expect(schema).toEqual(mockSchemaData()[0])
		expect(schema.validate().success).toBe(true)
		expect(schema.slug).toBe(mockSchemaData()[0].slug) // Added slug property check
	})

	it('should create a Schema entity with partial data', () => {
		const schema = new Schema(mockSchemaData()[0])

		expect(schema).toBeInstanceOf(Schema)
		expect(schema.id).toBe('')
		expect(schema.title).toBe(mockSchemaData()[0].title)
		expect(schema.validate().success).toBe(true)
		expect(schema.slug).toBe(mockSchemaData()[0].slug) // Added slug property check
	})

	it('should fail validation with invalid data', () => {
		const schema = new Schema(mockSchemaData()[1])

		expect(schema).toBeInstanceOf(Schema)
		expect(schema.validate().success).toBe(false)
		expect(schema.slug).toBe(mockSchemaData()[1].slug) // Added slug property check
	})

	it('should create a Schema entity with stats', () => {
		const schema = new Schema(mockSchemaData()[0])
		expect(schema.stats).toBeDefined()
		expect(schema.stats?.objects?.total).toBe(10)
		expect(schema.stats?.logs?.total).toBe(2)
		expect(schema.stats?.files?.size).toBe(128)
	})
})
