export const mockConfiguration = {
	'@self': {
		id: '1',
		title: 'Test Configuration',
		description: 'A test configuration',
		type: 'test',
		owner: 'admin',
		created: '2024-03-20T10:00:00Z',
		updated: '2024-03-20T10:00:00Z'
	},
	configuration: {
		key1: 'value1',
		key2: 'value2',
		nested: {
			key3: 'value3'
		}
	}
}

export const mockConfigurations = [
	mockConfiguration,
	{
		'@self': {
			id: '2',
			title: 'Another Configuration',
			description: 'Another test configuration',
			type: 'test',
			owner: 'admin',
			created: '2024-03-20T11:00:00Z',
			updated: '2024-03-20T11:00:00Z'
		},
		configuration: {
			setting1: true,
			setting2: false,
			complex: {
				array: ['item1', 'item2'],
				object: {
					prop1: 'value1'
				}
			}
		}
	}
] 