/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
		_url(register, schema, id, suffix = '') {
			return generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}/emails' + suffix, {
				register,
				schema,
				id,
			})
		},

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

				this.errors = { ...this.errors, [k]: err.response?.data?.error || err.message || '' }
				throw err
			} finally {
				this.loading = { ...this.loading, [k]: false }
			}
		},

		async unlink(register, schema, id, emailId) {
			await axios.delete(this._url(register, schema, id, '/' + encodeURIComponent(emailId)))
			const k = `${register}:${schema}:${id}`
			const next = (this.byObject[k] || []).filter(e => e.id !== emailId)
			this.byObject = { ...this.byObject, [k]: next }
			return next
		},

		get(register, schema, id) {
			return this.byObject[`${register}:${schema}:${id}`] || []
		},
	},
})
