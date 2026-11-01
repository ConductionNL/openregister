/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { defineStore } from 'pinia'
import {
	loadRelations,
	postAndRefetch,
	pruneRelations,
	relationUrl,
} from './relationCache.js'

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
	state: () => ({
		byObject: {},
		loading: {},
		errors: {},
		deckUnavailable: false,
	}),

	actions: {
		/**
		 * Build the Deck endpoint URL for an object.
		 *
		 * @param register
		 * @param schema
		 * @param id
		 * @param suffix
		 * @spec exclude private URL-builder helper (no client state)
		 */
		_url(register, schema, id, suffix = '') {
			return relationUrl('deck', { register, schema, id, suffix })
		},

		/**
		 * Fetch and cache Deck links for an object, falling back to an empty
		 * state when the Deck app is unavailable (HTTP 501).
		 *
		 * @param register
		 * @param schema
		 * @param id
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async fetch(register, schema, id) {
			return loadRelations(this, {
				url: this._url(register, schema, id),
				key: `${register}:${schema}:${id}`,
				flag: 'deckUnavailable',
				arraysOnly: true,
			})
		},

		/**
		 * Create or link a Deck card to an object, then refresh the cached
		 * list for that object key.
		 *
		 * @param register
		 * @param schema
		 * @param id
		 * @param payload
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async createOrLink(register, schema, id, payload) {
			return postAndRefetch(this, {
				url: this._url(register, schema, id),
				payload,
				object: [register, schema, id],
			})
		},

		/**
		 * Unlink a Deck card, optimistically pruning it from the cached list
		 * for that object key without refetching.
		 *
		 * @param register
		 * @param schema
		 * @param id
		 * @param deckRef
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async unlink(register, schema, id, deckRef) {
			await axios.delete(
				this._url(register, schema, id, '/' + encodeURIComponent(deckRef)),
			)
			return pruneRelations(this, {
				key: `${register}:${schema}:${id}`,
				keep: (c) => (c.ref || c.deckRef || c.id) !== deckRef,
			})
		},

		/**
		 * Read the cached Deck links for an object key.
		 *
		 * @param register
		 * @param schema
		 * @param id
		 * @spec exclude store getter (reads local per-key cache)
		 */
		get(register, schema, id) {
			return this.byObject[`${register}:${schema}:${id}`] || []
		},
	},
})
