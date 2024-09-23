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
        const partialData = {
            name: 'Partial Schema',
            description: 'A schema with partial data',
            jsonSchema: {},
            version: '0.1.0'
        }
        const schema = new Schema(partialData)

        expect(schema).toBeInstanceOf(Schema)
        expect(schema.id).toBe('')
        expect(schema.name).toBe(partialData.name)
        expect(schema.validate().success).toBe(true)
    })

    it('should fail validation with invalid data', () => {
        const invalidData = {
            name: '',
            description: 'Invalid schema',
            jsonSchema: {},
            version: '1.0.0'
        }
        const schema = new Schema(invalidData)

        expect(schema).toBeInstanceOf(Schema)
        expect(schema.validate().success).toBe(false)
    })
})