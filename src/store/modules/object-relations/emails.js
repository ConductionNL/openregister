/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { defineStore } from 'pinia'
import { relationActions, relationState } from './relationCache.js'

/**
 * Store wrapping the per-object email-link endpoints.
 *
 * Endpoints (registered under appinfo/routes.php — `emails#…`):
 *  - GET    /api/objects/{register}/{schema}/{id}/emails
 *  - POST   /api/objects/{register}/{schema}/{id}/emails
 *  - DELETE /api/objects/{register}/{schema}/{id}/emails/{emailId}
 *
 * The store keeps a per-`(register/schema/id)` cache keyed on the canonical
 * triple so a sidebar refresh on one object does not invalidate another.
 *
 * Spec: openspec/changes/nextcloud-entity-relations/specs/email-relations/spec.md
 */
export const useEmailRelationsStore = defineStore('emailRelations', {
	state: () => relationState('mailUnavailable'),

	getters: {
		key: () => (register, schema, id) => `${register}:${schema}:${id}`,
	},

	actions: {
		...relationActions({
			kind: 'emails',
			flag: 'mailUnavailable',
			refOf: (e) => e.id,
		}),
	},
})
