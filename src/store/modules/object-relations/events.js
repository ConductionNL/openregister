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
	state: () => ({
		byObject: {},
		loading: {},
		errors: {},
		calendarUnavailable: false,
	}),

	actions: {
		/**
		 * @param register
		 * @param schema
		 * @param id
		 * @param suffix
		 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-5
		 */
		_url(register, schema, id, suffix = '') {
			return relationUrl('events', { register, schema, id, suffix })
		},

		/**
		 * @param register
		 * @param schema
		 * @param id
		 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-5
		 */
		async fetch(register, schema, id) {
			return loadRelations(this, {
				url: this._url(register, schema, id),
				key: `${register}:${schema}:${id}`,
				flag: 'calendarUnavailable',
			})
		},

		/**
		 * @param register
		 * @param schema
		 * @param id
		 * @param payload
		 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-5
		 */
		async create(register, schema, id, payload) {
			return postAndRefetch(this, {
				url: this._url(register, schema, id),
				payload,
				object: [register, schema, id],
			})
		},

		/**
		 * @param register
		 * @param schema
		 * @param id
		 * @param payload
		 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-5
		 */
		async link(register, schema, id, payload) {
			return postAndRefetch(this, {
				url: this._url(register, schema, id, '/link'),
				payload,
				object: [register, schema, id],
			})
		},

		/**
		 * @param register
		 * @param schema
		 * @param id
		 * @param eventId
		 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-5
		 */
		async unlink(register, schema, id, eventId) {
			await axios.delete(
				this._url(register, schema, id, '/' + encodeURIComponent(eventId)),
			)
			return pruneRelations(this, {
				key: `${register}:${schema}:${id}`,
				keep: (e) => e.id !== eventId,
			})
		},

		/**
		 * @param register
		 * @param schema
		 * @param id
		 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-5
		 */
		get(register, schema, id) {
			return this.byObject[`${register}:${schema}:${id}`] || []
		},
	},
})
