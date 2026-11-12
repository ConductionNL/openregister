/**
 * Golden record of the audit trail and schema entities' validate(): success,
 * parsed data and every issue (path, code, message), for valid, partial and
 * invalid inputs. Each input is validated twice, so a validator that keeps
 * state between calls shows up.
 *
 * @spec exclude Golden record of client-side entity validation.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mockAuditTrailData } from './auditTrail/auditTrail.mock.ts'
import { AuditTrail } from './auditTrail/auditTrail.ts'
import { mockSchemaData } from './schema/schema.mock.ts'
import { Schema } from './schema/schema.ts'

jest.useFakeTimers().setSystemTime(new Date('2026-01-02T03:04:05.000Z'))

const AUDIT_TRAILS = {
	mock: () => mockAuditTrailData()[0],
	empty: () => ({}),
	wrongTypes: () => ({
		id: '1',
		uuid: 'not-a-uuid',
		schema: '2',
		changed: 'x',
		size: '3',
		version: 4,
	}),
	arrayChange: () => ({ ...mockAuditTrailData()[0], changed: [1, 2] }),
}

const SCHEMAS = {
	mock: () => mockSchemaData()[0],
	empty: () => ({}),
	badVersion: () => ({ ...mockSchemaData()[0], version: '1.0' }),
	goodVersion: () => ({ ...mockSchemaData()[0], version: '2.10.3' }),
	wrongTypes: () => ({
		id: 3,
		title: '',
		slug: '',
		hardValidation: 'no',
		maxDepth: -1,
		authorization: { read: 'all' },
	}),
	configured: () => ({
		...mockSchemaData()[0],
		configuration: { objectNameField: 1 },
	}),
}

/**
 * Validate one entity and keep what the result shows.
 *
 * @param {object} entity The entity.
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

describe('entity validation golden', () => {
	it('validates audit trails', () => {
		expect(
			Object.fromEntries(
				Object.entries(AUDIT_TRAILS).map(([name, input]) => {
					const entity = new AuditTrail(input())
					return [name, [outcome(entity), outcome(entity)]]
				}),
			),
		).toMatchSnapshot()
	})

	it('validates schemas', () => {
		expect(
			Object.fromEntries(
				Object.entries(SCHEMAS).map(([name, input]) => {
					const entity = new Schema(input())
					return [name, [outcome(entity), outcome(entity)]]
				}),
			),
		).toMatchSnapshot()
	})
})
