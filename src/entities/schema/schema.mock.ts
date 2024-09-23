import { Schema } from './schema'
import { TSchema } from './schema.types'

export const mockSchemaData = (): TSchema[] => [
    {
        id: "5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f",
        name: "Character Schema",
        description: "Defines the structure for character data",
        jsonSchema: {
            type: "object",
            properties: {
                name: { type: "string" },
                description: { type: "string" },
            },
            required: ["name"]
        },
        version: "1.0.0"
    },
    {
        id: "9012a1e5-b54d-43ad-abd1-4b5bff5fcd3f",
        name: "Item Schema",
        description: "Defines the structure for item data",
        jsonSchema: {
            type: "object",
            properties: {
                name: { type: "string" },
                value: { type: "number" }
            },
            required: ["name", "value"]
        },
        version: "1.1.0"
    }
]

export const mockSchema = (data: TSchema[] = mockSchemaData()): TSchema[] => data.map(item => new Schema(item))