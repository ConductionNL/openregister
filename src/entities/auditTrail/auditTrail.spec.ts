/* eslint-disable @typescript-eslint/no-explicit-any */
import { AuditTrail } from './auditTrail'
import { mockAuditTrailData } from './auditTrail.mock'

describe('AuditTrail Entity', () => {
	it('should create an AuditTrail entity with full data', () => {
		const auditTrail = new AuditTrail(mockAuditTrailData()[0])

		expect(auditTrail).toBeInstanceOf(AuditTrail)
		expect(auditTrail).toEqual(mockAuditTrailData()[0])
		expect(auditTrail.validate().success).toBe(true)
	})

	it('should create an AuditTrail entity with partial data', () => {
		const auditTrail = new AuditTrail(mockAuditTrailData()[0])

		expect(auditTrail).toBeInstanceOf(AuditTrail)
		expect(auditTrail.id).toBe(null)
		expect(auditTrail.uuid).toBe(mockAuditTrailData()[0].uuid)
		expect(auditTrail.register).toBe(mockAuditTrailData()[0].register)
		expect(auditTrail.schema).toBe(mockAuditTrailData()[0].schema)
		expect(auditTrail.object).toBe(mockAuditTrailData()[0].object)
		expect(auditTrail.action).toBe(mockAuditTrailData()[0].action)
		expect(auditTrail.changed).toBe(mockAuditTrailData()[0].changed)
		expect(auditTrail.user).toBe(mockAuditTrailData()[0].user)
		expect(auditTrail.userName).toBe(mockAuditTrailData()[0].userName)
		expect(auditTrail.session).toBe(mockAuditTrailData()[0].session)
		expect(auditTrail.request).toBe(mockAuditTrailData()[0].request)
		expect(auditTrail.ipAddress).toBe(mockAuditTrailData()[0].ipAddress)
		expect(auditTrail.version).toBe(mockAuditTrailData()[0].version)
		expect(auditTrail.created).toBe(mockAuditTrailData()[0].created)
		expect(auditTrail.validate().success).toBe(true)
	})

	it('should fail validation with invalid data', () => {
		const auditTrail = new AuditTrail(mockAuditTrailData()[1])

		expect(auditTrail).toBeInstanceOf(AuditTrail)
		expect(auditTrail.validate().success).toBe(false)
		expect(auditTrail.validate().error?.issues).toContainEqual(expect.objectContaining({
			path: ['id'],
			message: 'Expected string, received null',
		}))
	})
})
