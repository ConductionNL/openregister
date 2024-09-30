import { Schema } from './schema'
import { mockSchemaData } from './schema.mock'

describe('Schema Entity', () => {
	it('should create a Schema entity with full data', () => {
		const schema = new Schema(mockSchemaData()[0])

		expect(schema).toBeInstanceOf(Schema)
		expect(schema).toEqual(mockSchemaData()[0])
		expect(schema.validate().success).toBe(true)
	})

	it('should create a Schema entity with partial data', () => {
		const schema = new Schema(mockSchemaData()[0])

		expect(schema).toBeInstanceOf(Schema)
		expect(schema.id).toBe('')
		expect(schema.title).toBe(mockSchemaData()[0].title)
		expect(schema.validate().success).toBe(true)
	})

	it('should fail validation with invalid data', () => {
		const schema = new Schema(mockSchemaData()[1])

		expect(schema).toBeInstanceOf(Schema)
		expect(schema.validate().success).toBe(false)
	})
})
