export type TRegister = {
    id?: string
    title: string
    description: string
    schemas: string[] // Array of Schema UUIDs
    source: string // Reference to the Source entity
    databaseId: string // Reference to the Database entity
    tablePrefix?: string
    updated?: {
        date: string;
        timezone_type: number;
        timezone: string;
    }
	created: {
        date: string;
        timezone_type: number;
        timezone: string;
    }
}
