/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { defineStore } from 'pinia'
import { postAction, relationActions, relationState } from './relationCache.js'

/**
 * Store wrapping the per-object Deck-card endpoints.
 *
 * Endpoints (registered under appinfo/routes.php — `deck#…`):
 *  - GET    /api/objects/{register}/{schema}/{id}/deck
 *  - POST   /api/objects/{register}/{schema}/{id}/deck
 *  - DELETE /api/objects/{register}/{schema}/{id}/deck/{deckRef}
 *
 * The Deck app may not be installed; the controller returns HTTP 501 with
 * `code: APP_NOT_AVAILABLE` in that case. The store flips a flag so the tab
 * can render an empty state instead of an error.
 *
 * Spec: openspec/changes/nextcloud-entity-relations/specs/deck-relations/spec.md
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
 */
export const useDeckRelationsStore = defineStore('deckRelations', {
	state: () => relationState('deckUnavailable'),

	actions: {
		...relationActions({
			kind: 'deck',
			flag: 'deckUnavailable',
			arraysOnly: true,
			refOf: (c) => c.ref || c.deckRef || c.id,
		}),
		createOrLink: postAction(''),
	},
})
