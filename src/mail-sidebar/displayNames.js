/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Display names of Nextcloud users, read once per user id through the
 * autocomplete any signed-in user may ask, so a handler shows as
 * "Pieter Jansen" and not as "pieter".
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const cache = new Map()

/**
 * The display name of a user id; the id itself when it cannot be read.
 *
 * @param {string} uid The user id.
 * @return {Promise<string>}
 */
export function displayNameOf(uid) {
	if (!uid) {
		return Promise.resolve('')
	}
	// The autocomplete leaves the signed-in user out, so they are named from the session.
	const me = getCurrentUser()
	if (me?.uid === uid) {
		return Promise.resolve(me.displayName || uid)
	}
	if (!cache.has(uid)) {
		cache.set(uid, axios.get(generateOcsUrl('core/autocomplete/get'), {
			params: { search: uid, itemType: ' ', itemId: ' ', 'shareTypes[]': 0, limit: 10 },
			headers: { 'OCS-APIRequest': 'true' },
		}).then((response) => {
			const match = (response.data?.ocs?.data || []).find((entry) => entry.id === uid)
			return match?.label || uid
		}).catch(() => uid))
	}
	return cache.get(uid)
}

/**
 * Forget every name read; for tests.
 */
export function clearDisplayNames() {
	cache.clear()
}
