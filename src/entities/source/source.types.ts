export type TSource = {
	id?: string | number
	title: string
	description: string
	databaseUrl: string
	type: 'internal' | 'mongodb' | 'database'
	authConfig?: Record<string, unknown> | null
	updated: string
	created: string
}
