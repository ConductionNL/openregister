export type TRegister = {
    id?: string
    title: string
    description: string
    schemas: string[] // Array of Schema UUIDs
    source: string // Reference to the Source entity
    databaseId: string // Reference to the Database entity
    published?: boolean
    depublished?: boolean
    tablePrefix?: string
    updated?: string
    created: string
    slug: string // Slug for the register
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
    stats?: {
        objects: {
            total: number
            size: number
            invalid: number
            deleted: number
            locked: number
            published: number
        },
        logs: {
            total: number
            size: number
        },
        files: {
            total: number
            size: number
        }
    }
}
