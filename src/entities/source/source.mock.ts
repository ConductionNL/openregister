import { Source } from './source'
import { TSource } from './source.types'

export const mockSourceData = (): TSource[] => [
    {
        id: "5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f",
        name: "Main PostgreSQL Database",
        description: "Primary database for user data",
        databaseUrl: "postgresql://user:password@localhost:5432/maindb"
    },
    // ... existing code ...
]

export const mockSource = (data: TSource[] = mockSourceData()): TSource[] => data.map(item => new Source(item))