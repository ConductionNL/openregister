import { TConfiguration } from './configuration.types'

export const mockConfiguration: TConfiguration = {
	'@self': {
		id: '1',
		uuid: '550e8400-e29b-41d4-a716-446655440000',
		title: 'Test Configuration',
		description: 'A test configuration for mocking purposes',
		version: '1.0.0',
		slug: 'test-configuration',
		owner: 'test-user',
		organisation: 'test-org',
		application: 'test-app',
		updated: '2024-03-20T12:00:00Z',
		created: '2024-03-20T12:00:00Z',
	},
	configuration: {
		registers: ['register1', 'register2'],
		schemas: ['schema1', 'schema2'],
		endpoints: ['endpoint1', 'endpoint2'],
		rules: ['rule1', 'rule2'],
		jobs: ['job1', 'job2'],
		sources: ['source1', 'source2'],
		objects: ['object1', 'object2'],
	},
}

export const mockConfigurations: TConfiguration[] = [
	mockConfiguration,
	{
		'@self': {
			id: '2',
			uuid: '550e8400-e29b-41d4-a716-446655440001',
			title: 'Another Configuration',
			description: 'Another test configuration',
			version: '1.0.0',
			slug: 'another-configuration',
			owner: 'test-user',
			organisation: 'test-org',
			application: 'test-app',
			updated: '2024-03-20T12:00:00Z',
			created: '2024-03-20T12:00:00Z',
		},
		configuration: {
			registers: ['register3', 'register4'],
			schemas: ['schema3', 'schema4'],
			endpoints: [],
			rules: [],
			jobs: [],
			sources: [],
			objects: [],
		},
	},
]
