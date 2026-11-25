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
	{
		// Who may manage the grants on records the group does not own. It
		// decides who reads a record, so the server admits nobody through `*`
		// and the editor does not offer everyone.
		right: 'grant_manage',
		inputLabel: 'Selected Special Rights Grant Manage',
		allowsEveryone: false,
		description: () => t('openregister', 'Manage the grants on records the group does not own'),
	},
]

/**
 * Whether a stored special-right entry is narrowed to a register or schemas
 * (`{group, register?, schemas?}`), rather than a plain group id or `*`.
 *
 * @param {*} entry The entry as stored
 * @return {boolean} True for a narrowed entry
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
export function isNarrowedEntry(entry) {
	return entry !== null && typeof entry === 'object' && !Array.isArray(entry)
}

/**
 * A stored special right split into the plain entries the group picker edits
 * and the narrowed entries it shows and keeps as they are.
 *
 * @param {*} entries The stored list
 * @return {{plain: Array<string>, narrowed: Array<object>}} The split
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
export function splitEntries(entries) {
	const list = Array.isArray(entries) ? entries : []
	return {
		plain: list.filter((entry) => typeof entry === 'string'),
		narrowed: list.filter(isNarrowedEntry),
	}
}

/**
 * The stored list after the picker changed: the narrowed entries first, as
 * they were, then the picked group ids.
 *
 * @param {Array<object>} narrowed The narrowed entries kept
 * @param {Array<string>} groupIds The picked group ids
 * @return {Array<string|object>} The list to store
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
export function mergeEntries(narrowed, groupIds) {
	return [...(Array.isArray(narrowed) ? narrowed : []), ...groupIds]
}

/**
 * A narrowed entry in words: the group, and the register and schemas it is
 * limited to.
 *
 * @param {{group?: string, register?: string, schemas?: Array<string>}} entry The entry
 * @return {string} The line
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
export function narrowedEntryLabel(entry) {
	const group = String(entry?.group ?? '')
	const register = typeof entry?.register === 'string' && entry.register !== '' ? entry.register : null
	const schemas = Array.isArray(entry?.schemas) && entry.schemas.length > 0 ? entry.schemas.join(', ') : null

	if (register !== null && schemas !== null) {
		return t('openregister', '{group}, only for {schemas} in register {register}', { group, schemas, register })
	}
	if (register !== null) {
		return t('openregister', '{group}, only in register {register}', { group, register })
	}
	if (schemas !== null) {
		return t('openregister', '{group}, only for {schemas}', { group, schemas })
	}

	return group
}
