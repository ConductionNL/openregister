export type TAuditTrail = {
    id: number
    uuid: string
    schema: number // schema ID
    register: number // register ID
    object: number // object ID
    objectUuid: string | null
    registerUuid: string | null
    schemaUuid: string | null
    action: string
    changed: object | array // JSON object or array for changes
    user: string
    userName: string
    session: string
    request: string
    ipAddress: string
    version: string | null
    created: string // ISO datetime string
    organisationId: string | null
    organisationIdType: string | null
    processingActivityId: string | null
    processingActivityUrl: string | null
    processingId: string | null
    confidentiality: string | null
    retentionPeriod: string | null
    size: number
}
