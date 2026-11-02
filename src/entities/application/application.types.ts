import type { TQuota, TUsage } from '../quotaUsage.types'

export type TApplication = {
	id?: number
	uuid?: string
	name: string
	description?: string
	version?: string
	organisation?: number
	configurations?: number[]
	registers?: number[]
	schemas?: number[]
	groups?: string[]
	quota?: TQuota
	usage?: TUsage
	authorization?: {
		create?: string[]
		read?: string[]
		update?: string[]
		delete?: string[]
	}
	owner?: string
	active?: boolean
	created?: string
	updated?: string
}

export type TApplicationPath = {
	applicationId?: string
}
