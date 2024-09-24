import { SafeParseReturnType, z } from 'zod'
import { TRegister } from './register.types'

export class Register implements TRegister {
    public id: string
    public name: string
    public description: string
    public schemas: string[]
    public databaseId: string
    public tablePrefix: string

    constructor(register: TRegister) {
        this.id = register.id || ''
        this.name = register.name
        this.description = register.description
        this.schemas = register.schemas || []
        this.databaseId = register.databaseId
        this.tablePrefix = register.tablePrefix || ''
    }

    public validate(): SafeParseReturnType<TRegister, unknown> {
        const schema = z.object({
            id: z.string().min(1),
            name: z.string().min(1),
            description: z.string(),
            schemas: z.array(z.string()),
            databaseId: z.string().min(1),
            tablePrefix: z.string()
        })

        return schema.safeParse(this)
    }

    public getFullTablePrefix(databasePrefix: string): string {
        return `${databasePrefix}${this.tablePrefix}`.replace(/_{2,}/g, '_')
    }
}