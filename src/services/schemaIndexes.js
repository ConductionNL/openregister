/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The index list of a schema's tables, and whether this instance can build a
 * text-search index at all.
 *
 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-the-property-editor-offers-both-index-kinds
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read `GET /api/schemas/{id}/indexes`.
 *
 * @param {string|number} schema The schema id, uuid or slug.
 * @return {Promise<{results: Array<{register: number, indexes: Array<object>}>, textSearchIndex: boolean}>} The envelope, with defaults for a field the server left out.
 */
export async function fetchSchemaIndexes(schema) {
	const url = generateUrl('/apps/openregister/api/schemas/{schema}/indexes', { schema })
	const { data } = await axios.get(url)

	return {
		results: Array.isArray(data?.results) ? data.results : [],
		textSearchIndex: data?.textSearchIndex === true,
	}
}

