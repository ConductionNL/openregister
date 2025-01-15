export type TObject = {
    id?: string
    uuid: string
    uri: string
    register: string
    schema: string
    object: string // JSON object
    relations: string
    files: string
    updated: string
    created: string
    locked: string[] | null // Array of lock tokens or null if not locked
    owner: string // Owner of the object
}
