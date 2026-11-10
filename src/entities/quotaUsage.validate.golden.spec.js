/**
 * Golden record of Application.validate() and View.validate(): success,
 * parsed data and every issue (path, code, message) for valid, partial and
 * invalid input, with the quota and usage blocks in every shape.
 */
import { mockApplicationData } from './application/application.mock.ts'
import { Application } from './application/application.ts'
import { View } from './view/view.ts'

jest.useFakeTimers().setSystemTime(new Date('2026-01-02T03:04:05.000Z'))

const QUOTA_CASES = {
	partial: { name: 'X' },
	emptyName: { name: '' },
	quotaWrongTypes: {
		name: 'X',
		quota: {
			storage: '10',
			bandwidth: null,
			requests: 1,
			users: -1,
			groups: 'x',
			extra: 'dropped',
		},
		usage: {
			storage: null,
			bandwidth: '1',
			requests: 2,
			users: 3,
			groups: 4,
			extra: 5,
		},
	},
	quotaNotObject: { name: 'X', quota: 'all', usage: [1, 2] },
	quotaEmpty: { name: 'X', quota: {}, usage: {} },
	quotaNulls: { name: 'X', quota: null, usage: null, created: 5 },
	usageNullFields: {
		name: 'X',
		quota: { storage: 1, bandwidth: 2, requests: 3, users: 4, groups: 5 },
		usage: {
			storage: null,
			bandwidth: null,
			requests: null,
			users: null,
			groups: null,
		},
	},
}

const APPLICATION_CASES = {
	...QUOTA_CASES,
	mockFull: mockApplicationData()[0],
	mockSecond: mockApplicationData()[1],
	wrongTypes: {
		name: 'App',
		id: '7',
		groups: [1],
		active: 'yes',
		authorization: { create: 'all', read: [1], update: [], unknown: 1 },
	},
}

const VIEW_CASES = {
	...QUOTA_CASES,
	full: {
		id: 3,
		uuid: 'u',
		name: 'My view',
		description: 'd',
		owner: 'admin',
		isPublic: true,
		isDefault: true,
		query: { a: 1, b: [2] },
		favoredBy: ['admin'],
		created: '2026-01-01',
		updated: '2026-01-02',
	},
	wrongTypes: {
		name: 'V',
		isPublic: 'yes',
		query: 'q',
		favoredBy: [1],
	},
}

/**
 * Validate one entity and keep what the result shows.
 *
 * @param {object} entity The constructed entity.
 * @return {object} The validation outcome.
 */
function outcome(entity) {
	const result = entity.validate()
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

describe('Application.validate golden', () => {
	it.each(Object.keys(APPLICATION_CASES))('%s', (name) => {
		expect(outcome(new Application(APPLICATION_CASES[name]))).toMatchSnapshot()
	})
})

describe('View.validate golden', () => {
	it.each(Object.keys(VIEW_CASES))('%s', (name) => {
		expect(outcome(new View(VIEW_CASES[name]))).toMatchSnapshot()
	})
})
