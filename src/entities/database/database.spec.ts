import { Database } from './database'
import { mockDatabaseData } from './database.mock'

describe('Database Entity', () => {
    it('should create a Database entity with full data', () => {
        const database = new Database(mockDatabaseData()[0])

        expect(database).toBeInstanceOf(Database)
        expect(database).toEqual(mockDatabaseData()[0])
        expect(database.validate().success).toBe(true)
    })

    it('should create a Database entity with partial data', () => {
        const partialData = {
            name: 'Partial Database',
            url: 'mysql://user:pass@localhost:3306/partial_db'
        }
        const database = new Database(partialData)

        expect(database).toBeInstanceOf(Database)
        expect(database.id).toBe('')
        expect(database.name).toBe(partialData.name)
        expect(database.tablePrefix).toBe('')
        expect(database.validate().success).toBe(true)
    })

    it('should fail validation with invalid data', () => {
        const invalidData = {
            name: '',
            url: 'invalid-url'
        }
        const database = new Database(invalidData)

        expect(database).toBeInstanceOf(Database)
        expect(database.validate().success).toBe(false)
    })

    it('should handle table prefix', () => {
        const data = mockDatabaseData()[0]
        const database = new Database(data)

        expect(database).toBeInstanceOf(Database)
        expect(database.tablePrefix).toBe('myorganisation_')
        expect(database.validate().success).toBe(true)
    })

    it('should correctly identify database type from URL', () => {
        const mysqlDb = new Database(mockDatabaseData()[0])
        const postgresDb = new Database(mockDatabaseData()[1])
        const mongoDb = new Database(mockDatabaseData()[2])

        expect(mysqlDb.getDatabaseType()).toBe('mysql')
        expect(postgresDb.getDatabaseType()).toBe('postgresql')
        expect(mongoDb.getDatabaseType()).toBe('mongodb')
    })
})