export type TObject = {
    '@self': {
        id?: string
        uuid: string
        uri: string
        register: string
        schema: string
        relations: string
        files: string
        folder: string
        updated: string
        created: string
        locked: string[] | null // Array of lock tokens or null if not locked
        owner: string // Owner of the object
    }
    [key: string]: unknown // Allow for additional properties
}

export type TObjectPath = {
    register: string
    schema: string
    objectId?: string
}
