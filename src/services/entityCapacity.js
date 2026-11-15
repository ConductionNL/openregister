/**
 * The capacity suggested for a detected person, and its confirmation.
 *
 * Detection suggests in which capacity a person appears in a file (a public
 * office, a professional role, a private person, or unknown). The suggestion
 * decides nothing until a reviewer confirms it; confirming sends the capacity
 * to `PATCH /api/entity-relations/{id}`, where the capacity policy fills in the
 * decision and ground unless the reviewer set them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.3
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The capacities the server accepts, in `CapacityPolicy::CAPACITIES` order.
 *
 * @type {string[]}
 */
export const CAPACITIES = ['public-office', 'professional', 'private', 'unknown']

/**
 * The label of one capacity, or the raw value for one this client does not know.
 *
 * @param {string} capacity The capacity
 * @return {string} The label
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.3
 */
export function capacityLabel(capacity) {
	switch (capacity) {
	case 'public-office':
		return t('openregister', 'Public office')
	case 'professional':
		return t('openregister', 'Professional capacity')
	case 'private':
		return t('openregister', 'Private person')
	case 'unknown':
		return t('openregister', 'Capacity unknown')
	default:
		return String(capacity)
	}
}

/**
 * The capacity line of a relation: the confirmed capacity when there is one,
 * else the suggestion, else nothing.
 *
 * @param {object} relation The serialised entity relation
 * @return {string|null} The line, or null when the relation carries no capacity
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.3
 */
export function capacityLine(relation) {
	if (relation?.capacityApplied) {
		return t('openregister', 'Confirmed capacity: {capacity}', { capacity: capacityLabel(relation.capacityApplied) })
	}
	if (relation?.capacity) {
		return t('openregister', 'Suggested capacity: {capacity}', { capacity: capacityLabel(relation.capacity) })
	}
	return null
}

/**
 * Whether the relation offers the confirm control: it carries a known
 * suggestion that no reviewer has confirmed yet.
 *
 * @param {object} relation The serialised entity relation
 * @return {boolean} True when the suggestion can be confirmed
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.3
 */
export function canConfirmCapacity(relation) {
	return Boolean(relation)
		&& !relation.capacityApplied
		&& CAPACITIES.includes(relation.capacity)
}

/**
 * Confirm the suggested capacity of one relation.
 *
 * Only the capacity is sent, so the server's capacity policy decides whether
 * the occurrence is released or withheld and under which ground.
 *
 * @param {object} relation The serialised entity relation
 * @return {Promise<object>} The server's answer
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.3
 */
export async function confirmCapacity(relation) {
	const url = generateUrl('/apps/openregister/api/entity-relations/{id}', { id: relation.id })
	const response = await axios.patch(url, { capacity: relation.capacity })
	return response.data
}
