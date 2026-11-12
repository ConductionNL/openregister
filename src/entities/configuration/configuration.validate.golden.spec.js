import { mockConfiguration, mockConfigurations } from './configuration.mock.ts'
/**
 * Golden record of ConfigurationEntity.validate(): success, parsed data and
 * every issue (path, code, message) for valid and invalid configurations.
 */
import { ConfigurationEntity } from './configuration.ts'

const CASES = {
	mock: mockConfiguration,
	mockSecond: mockConfigurations[1],
	empty: {},
	wrongTypes: {
		id: 5,
		title: 'T',
		description: 7,
		type: 'app',
		application: 'a',
		owner: 'o',
		organisation: '3',
		registers: ['1'],
		schemas: [2, 'x'],
		created: 1,
		updated: '2026-01-01',
	},
	numbers: {
		id: 'c',
		title: 'T',
		organisation: 4,
		registers: [1, 2],
		schemas: [3],
	},
}

/**
 * Validate one input and keep what the result shows.
 *
 * @param {object} input The configuration input.
 * @return {object} The validation outcome.
 */
function outcome(input) {
	const result = new ConfigurationEntity(input).validate()
	return {
		success: result.success,
		data: result.success ? result.data : undefined,
		issues: result.success
			? undefined
			: result.error.issues.map((i) => ({
					path: i.path,
					code: i.code,
					message: i.message,
				})),
	}
}

describe('ConfigurationEntity.validate golden', () => {
	it.each(Object.keys(CASES))('%s', (name) => {
		expect(outcome(CASES[name])).toMatchSnapshot()
	})
})
