/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { defineStore } from 'pinia'
import { loadRelations, pruneRelations, relationUrl } from './relationCache.js'

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
	state: () => ({
		/** @type {Record<string, Array>} keyed by `${register}:${schema}:${id}` */
		byObject: {},
		/** @type {Record<string, boolean>} */
		loading: {},
		/** @type {Record<string, ?string>} */
		errors: {},
		mailUnavailable: false,
	}),

	getters: {
		key: () => (register, schema, id) => `${register}:${schema}:${id}`,
	},

	actions: {
		/**
		 * Build the email-link endpoint URL for an object.
		 *
		 * @param register
		 * @param schema
		 * @param id
		 * @param suffix
		 * @spec exclude private URL-builder helper (no client state)
		 */
		_url(register, schema, id, suffix = '') {
			return relationUrl('emails', { register, schema, id, suffix })
		},

		/**
		 * Fetch and cache email links for an object, falling back to an empty
		 * state when the Mail app is unavailable (HTTP 501).
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
				flag: 'mailUnavailable',
			})
		},

		/**
		 * Unlink an email, optimistically pruning it from the cached list for
		 * that object key without refetching.
		 *
		 * @param register
		 * @param schema
		 * @param id
		 * @param emailId
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async unlink(register, schema, id, emailId) {
			await axios.delete(
				this._url(register, schema, id, '/' + encodeURIComponent(emailId)),
			)
			return pruneRelations(this, {
				key: `${register}:${schema}:${id}`,
				keep: (e) => e.id !== emailId,
			})
		},

		/**
		 * Read the cached email links for an object key.
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
