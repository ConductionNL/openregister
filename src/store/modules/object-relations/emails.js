/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

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
			return generateUrl(
				'/apps/openregister/api/objects/{register}/{schema}/{id}/emails'
					+ suffix,
				{
					register,
					schema,
					id,
				},
			)
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
			const k = `${register}:${schema}:${id}`
			this.loading = { ...this.loading, [k]: true }
			this.errors = { ...this.errors, [k]: null }
			this.mailUnavailable = false

			try {
				const response = await axios.get(this._url(register, schema, id))
				const list = response.data?.results || response.data || []
				this.byObject = { ...this.byObject, [k]: list }
				return list
			} catch (err) {
				if (err.response?.status === 501) {
					this.mailUnavailable = true
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
			const k = `${register}:${schema}:${id}`
			const next = (this.byObject[k] || []).filter((e) => e.id !== emailId)
			this.byObject = { ...this.byObject, [k]: next }
			return next
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
