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
    slug: string; // Slug for the schema
    configuration?: {
        objectNameField?: string; // Field to use as object name
        objectDescriptionField?: string; // Field to use as object description
    }
    hardValidation: boolean; // Whether hard validation is enabled
    maxDepth: number; // Maximum depth of the schema
    authorization?: Record<string, string[]>; // RBAC authorization configuration
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
