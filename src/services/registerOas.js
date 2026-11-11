/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A register's generated OpenAPI specification: download it as a JSON file or
 * open it in the ReDoc viewer.
 */
import axios from '@nextcloud/axios'
import { saveJson } from './saveFile.js'

/**
 * The absolute URL of a register's generated OpenAPI specification.
 *
 * @spec exclude URL construction for the register OAS endpoint, UI plumbing
 * @param {number|string} id The register id
 * @return {string} The URL
 */
export function registerOasUrl(id) {
	return `${window.location.origin}/index.php/apps/openregister/api/registers/${id}/oas`
}

/**
 * Fetch a register's OpenAPI specification and download it as
 * `<title>-api-specification.json`. A failed request rejects; the caller
 * reports it.
 *
 * @spec exclude API fetch + browser file-download UI plumbing; OAS contract owned by openapi-generation capability
 * @param {{id: number|string, title: string}} register The register
 * @return {Promise<void>}
 */
export async function downloadRegisterOas(register) {
	const response = await axios.get(registerOasUrl(register.id))
	saveJson(response.data, `${register.title.toLowerCase()}-api-specification.json`)
}

/**
 * Open a register's OpenAPI specification in the ReDoc viewer in a new tab.
 *
 * @spec exclude window.open to external Redoc viewer, UI plumbing
 * @param {number|string} id The register id
 * @return {void}
 */
export function viewRegisterOas(id) {
	window.open(
		`https://redocly.github.io/redoc/?url=${encodeURIComponent(registerOasUrl(id))}`,
		'_blank',
	)
}
