import type { TQuota, TUsage } from '../quotaUsage.types'

export type TOrganisation = {
	id?: number
	uuid?: string
	name: string
	slug?: string
	description?: string
	users?: string[]
	groups?: string[]
	isDefault?: boolean
	active?: boolean
	owner?: string
	parent?: string | null
	children?: string[]
	quota?: TQuota
	usage?: TUsage
	authorization?: {
		register?: {
			create?: string[]
			read?: string[]
			update?: string[]
			delete?: string[]
		}
		schema?: {
			create?: string[]
			read?: string[]
			update?: string[]
			delete?: string[]
		}
		object?: {
			create?: string[]
			read?: string[]
			update?: string[]
			delete?: string[]
		}
		view?: {
			create?: string[]
			read?: string[]
			update?: string[]
			delete?: string[]
		}
		agent?: {
			create?: string[]
			read?: string[]
			update?: string[]
			delete?: string[]
		}
		configuration?: {
			create?: string[]
			read?: string[]
			update?: string[]
			delete?: string[]
		}
		application?: {
			create?: string[]
			read?: string[]
			update?: string[]
			delete?: string[]
		}
		agent_use?: string[]
		llm_use?: string[]
	}
	created?: string
	updated?: string
}
