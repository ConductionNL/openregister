/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

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
 */
export const useContactRelationsStore = defineStore('contactRelations', {
	state: () => ({
		byObject: {},
		loading: {},
		errors: {},
		contactsUnavailable: false,
	}),

	actions: {
		_url(register, schema, id, suffix = '') {
			return generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}/contacts' + suffix, {
				register,
				schema,
				id,
			})
		},

		async fetch(register, schema, id) {
			const k = `${register}:${schema}:${id}`
			this.loading = { ...this.loading, [k]: true }
			this.errors = { ...this.errors, [k]: null }
			this.contactsUnavailable = false

			try {
				const response = await axios.get(this._url(register, schema, id))
				// ContactsController#index returns either {results,...} or a flat array;
				// we normalise to an array.
				let list = response.data?.results || response.data || []
				if (!Array.isArray(list)) {
					list = []
				}

				this.byObject = { ...this.byObject, [k]: list }
				return list
			} catch (err) {
				if (err.response?.status === 501) {
					this.contactsUnavailable = true
					this.byObject = { ...this.byObject, [k]: [] }
					return []
				}

				this.errors = { ...this.errors, [k]: err.response?.data?.error || err.message || '' }
				throw err
			} finally {
				this.loading = { ...this.loading, [k]: false }
			}
		},

		async createOrLink(register, schema, id, payload) {
			const response = await axios.post(this._url(register, schema, id), payload)
			await this.fetch(register, schema, id)
			return response.data
		},

		async unlink(register, schema, id, contactUid) {
			await axios.delete(this._url(register, schema, id, '/' + encodeURIComponent(contactUid)))
			const k = `${register}:${schema}:${id}`
			const next = (this.byObject[k] || []).filter(c => (c.uid || c.contactUid || c.id) !== contactUid)
			this.byObject = { ...this.byObject, [k]: next }
			return next
		},

		get(register, schema, id) {
			return this.byObject[`${register}:${schema}:${id}`] || []
		},
	},
})
