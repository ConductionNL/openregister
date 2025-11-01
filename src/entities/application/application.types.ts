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
	storageQuota?: number | null
	bandwidthQuota?: number | null
	requestQuota?: number | null
	owner?: string
	active?: boolean
	created?: string
	updated?: string
}

export type TApplicationPath = {
	applicationId?: string
}

