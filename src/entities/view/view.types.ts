/**
 * View entity type definitions
 *
 * @package
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 */

import type { TQuota, TUsage } from '../quotaUsage.types'

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
	quota?: TQuota
	usage?: TUsage
	created?: string
	updated?: string
}

export type TViewPath = {
	viewId?: string
}
