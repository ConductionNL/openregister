export type TObject = {
    '@self': {
        id: string
        uuid: string
        uri: string
        version: string | null
        register: string
        schema: string
        schemaVersion: string | null
        relations: string | Array<unknown> | null
        files: string | Array<unknown> | null
        folder: string | null
        textRepresentation: string | null
        locked: Array<unknown> | null
        owner: string | null
        authorization: Array<unknown> | null
        application: string | null
        organisation: string | null
        validation: Array<unknown> | null
        deleted: Array<unknown> | null
        geo: Array<unknown> | null
        retention: Array<unknown> | null
        size: string | null
        updated: string
        created: string
        published: string | null
        depublished: string | null
    }
    [key: string]: unknown // Allow for additional properties
}

export type TObjectPath = {
    register: string
    schema: string
    objectId?: string
}
