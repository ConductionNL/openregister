export const mockConfiguration = {
	'@self': {
		id: '1',
		uuid: '550e8400-e29b-41d4-a716-446655440000',
		title: 'Test Configuration',
		description: 'A test configuration',
		version: '1.0.0',
		slug: 'test-configuration',
		owner: 'admin',
		organisation: 'Test Org',
		application: 'Test App',
		updated: '2024-03-20T12:00:00Z',
		created: '2024-03-20T12:00:00Z'
	},
	configuration: {
		key1: 'value1',
		key2: 'value2',
		nestedConfig: {
			subKey1: 'subValue1',
			subKey2: 'subValue2'
		}
	}
}

export const mockConfigurations = [
	mockConfiguration,
	{
		'@self': {
			id: '2',
			uuid: '550e8400-e29b-41d4-a716-446655440001',
			title: 'Another Configuration',
			description: 'Another test configuration',
			version: '1.0.0',
			slug: 'another-configuration',
			owner: 'admin',
			organisation: 'Test Org',
			application: 'Test App',
			updated: '2024-03-20T12:00:00Z',
			created: '2024-03-20T12:00:00Z'
		},
		configuration: {
			setting1: true,
			setting2: false,
			complexSetting: {
				option1: 'value1',
				option2: 'value2'
			}
		}
	},
	{
		'@self': {
			id: '3',
			uuid: '550e8400-e29b-41d4-a716-446655440002',
			title: 'Third Configuration',
			description: 'A third test configuration',
			version: '1.0.0',
			slug: 'third-configuration',
			owner: 'admin',
			organisation: 'Test Org',
			application: 'Test App',
			updated: '2024-03-20T12:00:00Z',
			created: '2024-03-20T12:00:00Z'
		},
		configuration: {
			feature1: 'enabled',
			feature2: 'disabled',
			featureSettings: {
				timeout: 30,
				retries: 3
			}
		}
	}
] 