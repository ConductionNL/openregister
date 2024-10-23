export type TAuditTrail = {
    id: string
    uuid: string
    schema: number // schema ID
    register: number // register ID
    object: number // object ID
    action: string
    changed: string // JSON object
    user: string
    userName: string
    session: string
    request: string
    ipAddress: string
    version: string
    created: string
}
