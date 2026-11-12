/**
 * The quota, usage and statistics shapes that several entities share.
 *
 * @package
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 */

/** The resources a quota limits and a usage counts. */
type TResource = 'storage' | 'bandwidth' | 'requests' | 'users' | 'groups'

/** An entity's limits: a number per resource, or null for no limit. */
export type TQuota = Partial<Record<TResource, number | null>>

/** An entity's consumption: a number per resource. */
export type TUsage = Partial<Record<TResource, number>>

/** The object, log and file counts the API adds to a register or schema. */
export type TEntityStats = {
	objects: {
		total: number
		size: number
		invalid: number
		deleted: number
		locked: number
	}
	logs: { total: number; size: number }
	files: { total: number; size: number }
}
