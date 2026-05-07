/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

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
 */
export const useEventRelationsStore = defineStore('eventRelations', {
	state: () => ({
		byObject: {},
		loading: {},
		errors: {},
		calendarUnavailable: false,
	}),

	actions: {
		_url(register, schema, id, suffix = '') {
			return generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}/events' + suffix, {
				register,
				schema,
				id,
			})
		},

		async fetch(register, schema, id) {
			const k = `${register}:${schema}:${id}`
			this.loading = { ...this.loading, [k]: true }
			this.errors = { ...this.errors, [k]: null }
			this.calendarUnavailable = false

			try {
				const response = await axios.get(this._url(register, schema, id))
				const list = response.data?.results || response.data || []
				this.byObject = { ...this.byObject, [k]: list }
				return list
			} catch (err) {
				if (err.response?.status === 501) {
					this.calendarUnavailable = true
					this.byObject = { ...this.byObject, [k]: [] }
					return []
				}

				this.errors = { ...this.errors, [k]: err.response?.data?.error || err.message || '' }
				throw err
			} finally {
				this.loading = { ...this.loading, [k]: false }
			}
		},

		async create(register, schema, id, payload) {
			const response = await axios.post(this._url(register, schema, id), payload)
			await this.fetch(register, schema, id)
			return response.data
		},

		async link(register, schema, id, payload) {
			const response = await axios.post(this._url(register, schema, id, '/link'), payload)
			await this.fetch(register, schema, id)
			return response.data
		},

		async unlink(register, schema, id, eventId) {
			await axios.delete(this._url(register, schema, id, '/' + encodeURIComponent(eventId)))
			const k = `${register}:${schema}:${id}`
			const next = (this.byObject[k] || []).filter(e => e.id !== eventId)
			this.byObject = { ...this.byObject, [k]: next }
			return next
		},

		get(register, schema, id) {
			return this.byObject[`${register}:${schema}:${id}`] || []
		},
	},
})
