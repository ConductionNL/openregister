export type TConfiguration = {
	id: string
	title: string
	description: string | null
	type: string
	application: string
	owner: string
	organisation: number | null
	registers?: number[]
	schemas?: number[]
	created: string
	updated: string
}

export type TConfigurationPath = {
	configurationId?: string
}

export type TConfigurationExport = {
	'@self': TConfiguration
	configuration: {
		registers?: string[]
		schemas?: string[]
		endpoints?: string[]
		rules?: string[]
		jobs?: string[]
		sources?: string[]
		objects?: string[]
	}
}
