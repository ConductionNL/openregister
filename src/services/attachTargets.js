/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A consuming app's `fileActions.attachTargets`: the schemas its manifest pins
 * the "Add to object" and "Save chat to object" pickers to.
 *
 * The declaration narrows the picker and never widens it: the server offers
 * only schemas the caller may change and that hold files, and intersects that
 * offer with the slugs read here (`/api/files/attach-targets?targets=`).
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-files-action-attaches-a-file-to-a-register-object
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The schema slugs a manifest declares under `fileActions.attachTargets`.
 *
 * An entry is a schema slug (`"case"`) or a `{ register, schema }` pair, of
 * which the schema is what the server narrows on. Anything else is dropped.
 *
 * @param {object|null|undefined} manifest The consuming app's manifest
 * @return {string[]|null} The slugs, or null when the manifest pins nothing
 */
export function declaredAttachTargets(manifest) {
	const declared = manifest?.fileActions?.attachTargets
	if (!Array.isArray(declared)) {
		return null
	}
	const slugs = []
	for (const entry of declared) {
		const slug = typeof entry === 'string' ? entry : entry?.schema
		if (typeof slug !== 'string' || slug.trim() === '') {
			continue
		}
		if (!slugs.includes(slug.trim())) {
			slugs.push(slug.trim())
		}
	}
	return slugs.length > 0 ? slugs : null
}

/**
 * Read an app's manifest and return its declared attach targets.
 *
 * A manifest that cannot be read pins nothing: the picker then offers what the
 * server offers, which is already limited to what the caller may change.
 *
 * @param {string} app The consuming app's id
 * @return {Promise<string[]|null>}
 */
export async function loadDeclaredAttachTargets(app) {
	if (typeof app !== 'string' || !/^[a-z0-9_-]+$/i.test(app)) {
		return null
	}
	try {
		const { data } = await axios.get(generateUrl('/apps/openregister/api/manifest/{app}', { app }))
		return declaredAttachTargets(data)
	} catch {
		return null
	}
}
