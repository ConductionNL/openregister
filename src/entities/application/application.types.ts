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

