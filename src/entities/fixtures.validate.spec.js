import { mockApplication, mockApplicationData } from './application/application.mock.ts'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The entity fixtures other tests build on: each one becomes its entity and
 * passes that entity's own validation, so a fixture cannot drift from the
 * schema it stands in for.
 *
 * @spec exclude Unit test of client-side test fixtures.
 */
import { Application } from './application/application.ts'
import { mockAuditTrail, mockAuditTrailData } from './auditTrail/auditTrail.mock.ts'
import { AuditTrail } from './auditTrail/auditTrail.ts'
import { mockDatabase, mockDatabaseData } from './database/database.mock.ts'
import { Database } from './database/database.ts'

describe('entity fixtures', () => {
	it.each([
		['Database', mockDatabase, mockDatabaseData, Database],
		['AuditTrail', mockAuditTrail, mockAuditTrailData, AuditTrail],
	])('every %s fixture becomes a valid entity', (name, build, data, Entity) => {
		const entities = build()
		expect(entities).toHaveLength(data().length)
		for (const entity of entities) {
			expect(entity).toBeInstanceOf(Entity)
			const result = entity.validate()
			expect(result.error?.issues ?? []).toEqual([])
		}
	})

	it('builds the entities from the data it is given', () => {
		const one = mockDatabaseData().slice(1, 2)
		expect(mockDatabase(one).map((d) => d.name)).toEqual(['Analytics PostgreSQL Database'])
		const trail = mockAuditTrailData().slice(0, 1)
		expect(mockAuditTrail(trail).map((a) => a.id)).toEqual([trail[0].id])
	})

	it('builds a valid application from the first fixture', () => {
		const app = mockApplication()
		expect(app).toBeInstanceOf(Application)
		expect(app.name).toBe(mockApplicationData()[0].name)
		expect(app.validate().error?.issues ?? []).toEqual([])
	})
})
