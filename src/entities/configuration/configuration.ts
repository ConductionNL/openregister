import { SafeParseReturnType, z } from 'zod'
import { TConfiguration } from './configuration.types'

/**
 * Entity class representing a Configuration with validation
 */
export class ConfigurationEntity implements TConfiguration {
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

    [key: string]: unknown

    constructor(configuration: TConfiguration) {
        this['@self'] = {
            id: configuration['@self']?.id || '',
            uuid: configuration['@self']?.uuid || '',
            title: configuration['@self']?.title || '',
            description: configuration['@self']?.description || null,
            version: configuration['@self']?.version || '1.0.0',
            slug: configuration['@self']?.slug || '',
            owner: configuration['@self']?.owner || null,
            organisation: configuration['@self']?.organisation || null,
            application: configuration['@self']?.application || null,
            updated: configuration['@self']?.updated || '',
            created: configuration['@self']?.created || '',
        }

        this.configuration = {
            registers: configuration.configuration?.registers || [],
            schemas: configuration.configuration?.schemas || [],
            endpoints: configuration.configuration?.endpoints || [],
            rules: configuration.configuration?.rules || [],
            jobs: configuration.configuration?.jobs || [],
            sources: configuration.configuration?.sources || [],
            objects: configuration.configuration?.objects || [],
        }

        // Copy any additional properties
        Object.keys(configuration).forEach(key => {
            if (key !== '@self' && key !== 'configuration') {
                this[key] = configuration[key]
            }
        })
    }

    /**
     * Validates the configuration against a schema
     */
    public validate(): SafeParseReturnType<TConfiguration, unknown> {
        const schema = z.object({
            '@self': z.object({
                id: z.string().min(1),
                uuid: z.string().min(1),
                title: z.string().min(1),
                description: z.string().nullable(),
                version: z.string().min(1),
                slug: z.string().min(1),
                owner: z.string().nullable(),
                organisation: z.string().nullable(),
                application: z.string().nullable(),
                updated: z.string().min(1),
                created: z.string().min(1),
            }),
            configuration: z.object({
                registers: z.array(z.string()).optional(),
                schemas: z.array(z.string()).optional(),
                endpoints: z.array(z.string()).optional(),
                rules: z.array(z.string()).optional(),
                jobs: z.array(z.string()).optional(),
                sources: z.array(z.string()).optional(),
                objects: z.array(z.string()).optional(),
            }),
        }).passthrough()

        return schema.safeParse(this)
    }
} 