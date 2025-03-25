export type TObject = {
    '@self': {
        id: string
        uri: string
        register: string
        schema: string
        relations: string
        files: string
        folder: string
        updated: string
        created: string
        locked: string[] | null
        owner: string | null
        organisation: string | null
        application: string | null
        version: string | null
        deleted: string[] | null
        geo: string[] | null
        retention: string[] | null
    }
    [key: string]: unknown // Allow for additional properties
}

export type TObjectPath = {
    register: string
    schema: string
    objectId?: string
}
