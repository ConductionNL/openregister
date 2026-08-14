/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

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
			return generateUrl(
				'/apps/openregister/api/objects/{register}/{schema}/{id}/deck'
					+ suffix,
				{
					register,
					schema,
					id,
				},
			)
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
			const k = `${register}:${schema}:${id}`
			this.loading = { ...this.loading, [k]: true }
			this.errors = { ...this.errors, [k]: null }
			this.deckUnavailable = false

			try {
				const response = await axios.get(this._url(register, schema, id))
				let list = response.data?.results || response.data || []
				if (!Array.isArray(list)) {
					list = []
				}

				this.byObject = { ...this.byObject, [k]: list }
				return list
			} catch (err) {
				if (err.response?.status === 501) {
					this.deckUnavailable = true
					this.byObject = { ...this.byObject, [k]: [] }
					return []
				}

				this.errors = {
					...this.errors,
					[k]: err.response?.data?.error || err.message || '',
				}
				throw err
			} finally {
				this.loading = { ...this.loading, [k]: false }
			}
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
			const response = await axios.post(
				this._url(register, schema, id),
				payload,
			)
			await this.fetch(register, schema, id)
			return response.data
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
			const k = `${register}:${schema}:${id}`
			const next = (this.byObject[k] || []).filter(
				(c) => (c.ref || c.deckRef || c.id) !== deckRef,
			)
			this.byObject = { ...this.byObject, [k]: next }
			return next
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
