export type TSchema = {
    id?: string
    title: string
    version: string
    description: string
    summary: string
    required: string[]
    properties: Record<string, any>
    archive: Record<string, any>
    updated: string;
    created: string;
}
