import { TConfiguration } from './configuration.types'

// Configuration entity was refactored from the legacy `@self`-wrapped
// shape to a flat shape (id, title, description, version, type, …) with
// optional `registers` / `schemas` arrays. Mock follows the current
// shape so configuration.spec.ts can construct valid instances.
export const mockConfiguration: TConfiguration = {
	id: '1',
	title: 'Test Configuration',
	description: 'A test configuration for mocking purposes',
	version: '1.0.0',
	type: 'app',
	application: 'test-app',
	owner: 'test-user',
	organisation: null,
	registers: [],
	schemas: [],
	updated: '2024-03-20T12:00:00Z',
	created: '2024-03-20T12:00:00Z',
}

export const mockConfigurations: TConfiguration[] = [
	mockConfiguration,
	{
		id: '2',
		title: 'Another Configuration',
		description: 'Another test configuration',
		version: '1.0.0',
		type: 'app',
		application: 'test-app',
		owner: 'test-user',
		organisation: null,
		registers: [],
		schemas: [],
		updated: '2024-03-20T12:00:00Z',
		created: '2024-03-20T12:00:00Z',
	},
]
