export type TOrganisation = {
    id?: number
    uuid?: string
    name: string
    slug?: string
    description?: string
    users?: string[]
    groups?: string[]
    isDefault?: boolean
    active?: boolean
    owner?: string
    parent?: string | null
    children?: string[]
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
    authorization?: {
        register?: {
            create?: string[]
            read?: string[]
            update?: string[]
            delete?: string[]
        }
        schema?: {
            create?: string[]
            read?: string[]
            update?: string[]
            delete?: string[]
        }
        object?: {
            create?: string[]
            read?: string[]
            update?: string[]
            delete?: string[]
        }
        view?: {
            create?: string[]
            read?: string[]
            update?: string[]
            delete?: string[]
        }
        agent?: {
            create?: string[]
            read?: string[]
            update?: string[]
            delete?: string[]
        }
        configuration?: {
            create?: string[]
            read?: string[]
            update?: string[]
            delete?: string[]
        }
        application?: {
            create?: string[]
            read?: string[]
            update?: string[]
            delete?: string[]
        }
        object_publish?: string[]
        agent_use?: string[]
        dashboard_view?: string[]
        llm_use?: string[]
    }
    created?: string
    updated?: string
}
