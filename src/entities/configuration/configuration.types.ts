export type TConfiguration = {
    '@self': {
        id: string
        uuid: string
        title: string
        description: string | null
        version: string
        slug: string
        owner: string | null
        organisation: string | null
        application: string | null
        updated: string
        created: string
    }
    configuration: {
        registers?: string[]
        schemas?: string[]
        endpoints?: string[]
        rules?: string[]
        jobs?: string[]
        sources?: string[]
        objects?: string[]
    }
    [key: string]: unknown // Allow for additional properties
}

export type TConfigurationPath = {
    configurationId?: string
} 