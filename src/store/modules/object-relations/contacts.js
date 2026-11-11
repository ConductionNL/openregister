/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { defineStore } from 'pinia'
import { postAction, relationActions, relationState } from './relationCache.js'

/**
 * Store wrapping the per-object contact-link endpoints.
 *
 * Endpoints (registered under appinfo/routes.php — `contacts#…`):
 *  - GET    /api/objects/{register}/{schema}/{id}/contacts
 *  - POST   /api/objects/{register}/{schema}/{id}/contacts
 *  - PUT    /api/objects/{register}/{schema}/{id}/contacts/{contactUid}
 *  - DELETE /api/objects/{register}/{schema}/{id}/contacts/{contactUid}
 *
 * Spec: openspec/changes/nextcloud-entity-relations/specs/contact-relations/spec.md
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */
export const useContactRelationsStore = defineStore('contactRelations', {
	state: () => relationState('contactsUnavailable'),

	actions: {
		...relationActions({
			kind: 'contacts',
			flag: 'contactsUnavailable',
			arraysOnly: true,
			refOf: (c) => c.uid || c.contactUid || c.id,
		}),
		createOrLink: postAction(''),
	},
})
