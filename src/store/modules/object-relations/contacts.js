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
 * Store wrapping the per-object contact-link endpoints.
 *
 * Endpoints (registered under appinfo/routes.php — `contacts#…`):
 *  - GET    /api/objects/{register}/{schema}/{id}/contacts
 *  - POST   /api/objects/{register}/{schema}/{id}/contacts
 *  - PUT    /api/objects/{register}/{schema}/{id}/contacts/{contactUid}
 *  - DELETE /api/objects/{register}/{schema}/{id}/contacts/{contactUid}
 *
 * Spec: openspec/changes/nextcloud-entity-relations/specs/contact-relations/spec.md
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */
export const useContactRelationsStore = defineStore('contactRelations', {
	state: () => ({
		byObject: {},
		loading: {},
		errors: {},
		contactsUnavailable: false,
	}),

	actions: {
		/**
		 * @param register
		 * @param schema
		 * @param id
		 * @param suffix
		 * @spec openspec/specs/contacts-actions/spec.md
		 */
		_url(register, schema, id, suffix = '') {
			return relationUrl('contacts', { register, schema, id, suffix })
		},

		/**
		 * @param register
		 * @param schema
		 * @param id
		 * @spec openspec/specs/contacts-actions/spec.md
		 */
		async fetch(register, schema, id) {
			return loadRelations(this, {
				url: this._url(register, schema, id),
				key: `${register}:${schema}:${id}`,
				flag: 'contactsUnavailable',
				arraysOnly: true,
			})
		},

		/**
		 * @param register
		 * @param schema
		 * @param id
		 * @param payload
		 * @spec openspec/specs/contacts-actions/spec.md
		 */
		async createOrLink(register, schema, id, payload) {
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
		 * @param contactUid
		 * @spec openspec/specs/contacts-actions/spec.md
		 */
		async unlink(register, schema, id, contactUid) {
			await axios.delete(
				this._url(
					register,
					schema,
					id,
					'/' + encodeURIComponent(contactUid),
				),
			)
			return pruneRelations(this, {
				key: `${register}:${schema}:${id}`,
				keep: (c) => (c.uid || c.contactUid || c.id) !== contactUid,
			})
		},

		/**
		 * @param register
		 * @param schema
		 * @param id
		 * @spec openspec/specs/contacts-actions/spec.md
		 */
		get(register, schema, id) {
			return this.byObject[`${register}:${schema}:${id}`] || []
		},
	},
})
