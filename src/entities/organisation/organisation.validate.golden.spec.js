import { mockOrganisationData } from './organisation.mock.ts'
/**
 * Golden record of Organisation.validate(): success, parsed data and every
 * issue (path, code, message) for valid, partial and invalid organisations.
 */
import { Organisation } from './organisation.ts'

jest.useFakeTimers().setSystemTime(new Date('2026-01-02T03:04:05.000Z'))

const crud = { create: ['admin'], read: ['users'], update: [], delete: [] }

const CASES = {
	mockFull: mockOrganisationData()[0],
	mockUsers: mockOrganisationData()[1],
	partial: { name: 'Test Organisation' },
	emptyName: { name: '' },
	wrongTypes: {
		name: 'Org',
		id: '7',
		uuid: 3,
		isDefault: 'yes',
		active: false,
		users: ['a', 2],
		quota: {
			storage: '10',
			bandwidth: null,
			requests: 1,
			users: -1,
			groups: 'x',
		},
		usage: { storage: null, bandwidth: '1', requests: 2, users: 3, groups: 4 },
	},
	authorization: {
		name: 'Org',
		authorization: {
			register: crud,
			schema: { create: [1] },
			object: 'all',
			view: crud,
			agent: { read: 'users' },
			configuration: crud,
			application: crud,
			object_publish: ['g'],
			agent_use: 'g',
			dashboard_view: [true],
			llm_use: ['g'],
			unknown: ['ignored'],
		},
	},
	nullQuota: {
		name: 'Org',
		quota: null,
		usage: null,
		authorization: null,
		created: 5,
		updated: '2026-01-01',
	},
}

/**
 * Validate one input and keep what the result shows.
 *
 * @param {object} input The organisation input.
 * @return {object} The validation outcome.
 */
function outcome(input) {
	const result = new Organisation(input).validate()
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

describe('Organisation.validate golden', () => {
	it.each(Object.keys(CASES))('%s', (name) => {
		expect(outcome(CASES[name])).toMatchSnapshot()
	})
})
