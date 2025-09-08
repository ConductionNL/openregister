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
        objectSummaryField?: string; // Field to use as object summary
        objectImageField?: string; // Field to use as object image
        allowFiles?: boolean; // Whether files are allowed for this schema
        allowedTags?: string[]; // Array of allowed tags for files
        unique?: boolean; // Whether objects must be unique
        facetCacheTtl?: number; // Cache TTL for facets in seconds
        autoPublish?: boolean; // Whether objects should be auto-published on creation
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
