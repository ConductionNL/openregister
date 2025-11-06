/**
 * View entity type definitions
 *
 * @category Entities
 * @package  openregister
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 * @link     https://www.openregister.nl
 */

export type TView = {
	id?: number
	uuid?: string
	name: string
	description?: string
	owner?: string
	isPublic?: boolean
	isDefault?: boolean
	query?: Record<string, any>
	favoredBy?: string[]
	quota?: {
		storage?: number | null
		bandwidth?: number | null
		requests?: number | null
		users?: number | null
		groups?: number | null
	}
	usage?: {
		storage?: number
		bandwidth?: number
		requests?: number
		users?: number
		groups?: number
	}
	created?: string
	updated?: string
}

export type TViewPath = {
	viewId?: string
}




