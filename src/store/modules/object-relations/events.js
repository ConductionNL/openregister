/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { defineStore } from 'pinia'
import { postAction, relationActions, relationState } from './relationCache.js'

/**
 * Store wrapping the per-object calendar-event endpoints.
 *
 * Endpoints (registered under appinfo/routes.php — `calendarEvents#…`):
 *  - GET    /api/objects/{register}/{schema}/{id}/events
 *  - POST   /api/objects/{register}/{schema}/{id}/events
 *  - POST   /api/objects/{register}/{schema}/{id}/events/link
 *  - DELETE /api/objects/{register}/{schema}/{id}/events/{eventId}
 *
 * Mirrors the email-relations store: per-object cache keyed on the canonical
 * triple, 501-graceful when the Calendar app is missing.
 *
 * Spec: openspec/changes/nextcloud-entity-relations/specs/event-relations/spec.md
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-5
 */
export const useEventRelationsStore = defineStore('eventRelations', {
	state: () => relationState('calendarUnavailable'),

	actions: {
		...relationActions({
			kind: 'events',
			flag: 'calendarUnavailable',
			refOf: (e) => e.id,
		}),
		create: postAction(''),
		link: postAction('/link'),
	},
})
