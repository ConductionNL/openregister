import { Application } from './application'
import { TApplication } from './application.types'

export const mockApplicationData = (): TApplication[] => [
	{
		id: 1,
		uuid: '123e4567-e89b-12d3-a456-426614174000',
		name: 'Customer Management',
		description: 'Customer relationship management application',
		version: '1.0.0',
		organisation: 1,
		configurations: [1, 2],
		registers: [1, 2, 3],
		schemas: [1, 2, 3, 4],
		owner: 'admin',
		active: true,
		created: new Date().toISOString(),
		updated: new Date().toISOString(),
	},
	{
		id: 2,
		uuid: '223e4567-e89b-12d3-a456-426614174001',
		name: 'Product Catalog',
		description: 'Product catalog and inventory management',
		version: '2.1.0',
		organisation: 1,
		configurations: [3],
		registers: [4, 5],
		schemas: [5, 6, 7],
		owner: 'admin',
		active: true,
		created: new Date().toISOString(),
		updated: new Date().toISOString(),
	},
]

export const mockApplication = (): Application => new Application(mockApplicationData()[0])
