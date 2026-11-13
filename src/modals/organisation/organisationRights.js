import { translate as t } from '@nextcloud/l10n'

/**
 * The rights the organisation modal's Security tab offers.
 *
 * @spec exclude UI plumbing: static tab and table rows for display.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The tabs of the CRUD permission tables, one per entity type.
 *
 * @return {Array<object>} The tabs
 */
export function entityTabs() {
	return [
		{ entityType: 'register', title: t('openregister', 'Registers') },
		{ entityType: 'schema', title: t('openregister', 'Schemas') },
		{ entityType: 'object', title: t('openregister', 'Objects') },
		{ entityType: 'view', title: t('openregister', 'Views') },
		{ entityType: 'agent', title: t('openregister', 'Agents') },
		{
			entityType: 'configuration',
			title: t('openregister', 'Configurations'),
		},
		{ entityType: 'application', title: t('openregister', 'Applications') },
	]
}

/** The entry that gives a special right to every signed-in user. */
export const EVERYONE = '*'

/**
 * The choice under a special right that stands for every signed-in user.
 *
 * @return {{id: string, name: string}} The option
 */
export function everyoneOption() {
	return { id: EVERYONE, name: t('openregister', 'Everyone') }
}

/** Rights beyond CRUD, each granted to a list of groups or to everyone. */
export const SPECIAL_RIGHTS = [
	{
		right: 'agent_use',
		inputLabel: 'Selected Special Rights Agent Use',
		description: () =>
			t('openregister', 'Use AI agents for processing and analysis'),
	},
	{
		right: 'llm_use',
		inputLabel: 'Selected Special Rights Llm Use',
		description: () => t('openregister', 'Use Large Language Model features'),
	},
]
