/* eslint-disable @typescript-eslint/no-explicit-any */
import { AuditTrail } from './auditTrail'
import { mockAuditTrailData } from './auditTrail.mock'

describe('AuditTrail Entity', () => {
	it('should create an AuditTrail entity with full data', () => {
		const data = mockAuditTrailData()[0]
		const auditTrail = new AuditTrail(data)

		expect(auditTrail).toBeInstanceOf(AuditTrail)
		// Field-by-field, not deep equal: deep equal trips on the
		// AuditTrail prototype vs plain object.
		expect(auditTrail.id).toBe(data.id)
		expect(auditTrail.uuid).toBe(data.uuid)
		expect(auditTrail.schema).toBe(data.schema)
		expect(auditTrail.register).toBe(data.register)
		expect(auditTrail.object).toBe(data.object)
		expect(auditTrail.action).toBe(data.action)
		expect(auditTrail.user).toBe(data.user)
		expect(auditTrail.userName).toBe(data.userName)
		expect(auditTrail.created).toBe(data.created)
		expect(auditTrail.size).toBe(data.size)
		expect(auditTrail.validate().success).toBe(true)
	})

	it('should default scalar fields when given partial data', () => {
		const auditTrail = new AuditTrail({} as any)

		expect(auditTrail).toBeInstanceOf(AuditTrail)
		expect(auditTrail.id).toBe(0)
		expect(auditTrail.uuid).toBe('')
		expect(auditTrail.schema).toBe(0)
		expect(auditTrail.register).toBe(0)
		expect(auditTrail.object).toBe(0)
		expect(auditTrail.action).toBe('')
		expect(auditTrail.changed).toEqual([])
		expect(auditTrail.version).toBeNull()
		expect(auditTrail.size).toBe(0)
	})

	it('should fail validation when uuid is not a valid uuid', () => {
		const auditTrail = new AuditTrail({
			...mockAuditTrailData()[0],
			uuid: 'not-a-uuid',
		})

		expect(auditTrail).toBeInstanceOf(AuditTrail)
		expect(auditTrail.validate().success).toBe(false)
		expect(auditTrail.validate().error?.issues).toContainEqual(expect.objectContaining({
			path: ['uuid'],
		}))
	})
})
