export type TRegister = {
    id?: string
    name: string
    description: string
    schemas: string[] // Array of Schema UUIDs
    databaseId: string // Reference to the Database entity
    tablePrefix?: string
}