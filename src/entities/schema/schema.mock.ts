import { Schema } from './schema'
import { TSchema } from './schema.types'

export const mockSchemaData = (): TSchema[] => [
	{
		id: '5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		title: 'Character Schema',
		description: 'Defines the structure for character data',
		summary: 'Character Schema',
		required: ['name'],
		properties: {
			name: { type: 'string' },
			description: { type: 'string' },
		},
		version: '1.0.0',
		source: 'https://example.com/schemas/character.json',
		archive: [],
		updated: new Date().toISOString(),
		created: new Date().toISOString(),
	},
	{
		id: '9012a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		title: 'Item Schema',
		description: 'Defines the structure for item data',
		summary: 'Item Schema',
		required: ['name', 'value'],
		properties: {
			name: { type: 'string' },
			value: { type: 'number' },
		},
		version: '1.1.0',
		source: 'https://example.com/schemas/item.json',
		archive: [],
		updated: new Date().toISOString(),
		created: new Date().toISOString(),
	},
]

export const mockSchema = (data: TSchema[] = mockSchemaData()): TSchema[] => data.map(item => new Schema(item))
