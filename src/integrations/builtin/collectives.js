// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Collectives (NC Knowledge) built-in integration descriptor.
 *
 * Registered with `referenceType: 'collectives'` so schema properties
 * marked with that type auto-render the single-entity chip surface via
 * CnCollectivesCard (provided by @conduction/nextcloud-vue).
 *
 * ADR-019: integration descriptor shape.
 *
 * @see openspec/changes/integration-collectives/tasks.md
 */
export const collectivesIntegration = {
	id: 'collectives',
	label: 'Knowledge',
	icon: 'BookOpenPageVariant',
	group: 'docs',
	requiredApp: 'collectives',
	storage: 'link-table',
	referenceType: 'collectives',
}
