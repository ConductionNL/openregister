# Pinia Store Pattern

```javascript
// store/modules/object.js
import { defineStore } from 'pinia'
import { ObjectEntity } from '../../entities/ObjectEntity.js'

export const useObjectStore = defineStore('object', {
    state: () => ({
        objectItem: false,
        objectList: [],
        pagination: {
            total: 0,
            page: 1,
            pages: 0,
            limit: 20,
            offset: 0,
        },
        filters: {},
        loading: false,
    }),

    actions: {
        // Private helpers prefixed with underscore
        _buildObjectPath({ register, schema, objectId = '' }) {
            return `/index.php/apps/openregister/api/objects/${register}/${schema}${objectId ? '/' + objectId : ''}`
        },

        // Async actions use try/catch/finally
        async refreshObjectList({ register, schema, filters = {} }) {
            this.loading = true
            const endpoint = this._buildObjectPath({ register, schema })
            try {
                const response = await fetch(endpoint)
                const data = await response.json()
                this.objectList = data.results || []
                this.pagination = {
                    total: data.total || 0,
                    page: data.page || 1,
                    pages: data.pages || 0,
                    limit: data.limit || 20,
                    offset: data.offset || 0,
                }
                return { response, data }
            } catch (err) {
                console.error(err)
                throw err
            } finally {
                this.loading = false
            }
        },

        async saveObject(objectItem, { register, schema }) {
            const isNewObject = !objectItem['@self'].id
            const endpoint = this._buildObjectPath({
                register,
                schema,
                objectId: isNewObject ? '' : objectItem['@self'].id,
            })

            try {
                const response = await fetch(endpoint, {
                    method: isNewObject ? 'POST' : 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(objectItem),
                })
                const data = await response.json()
                this.setObjectItem(data)
                return { response, data }
            } catch (err) {
                console.error(err)
                throw err
            }
        },

        setObjectItem(objectItem) {
            this.objectItem = objectItem && new ObjectEntity(objectItem)
        },
    },
})
```

**Central store export** (`store/store.js`):
```javascript
import pinia from '../pinia.js'
import { useObjectStore } from './modules/object.js'
import { useRegisterStore } from './modules/register.js'
import { useSchemaStore } from './modules/schema.js'

const objectStore = useObjectStore(pinia)
const registerStore = useRegisterStore(pinia)
const schemaStore = useSchemaStore(pinia)

export { objectStore, registerStore, schemaStore }
```

Rules:
- Use native `fetch()` — NOT axios
- Private helpers prefixed with `_`
- `loading` state managed manually with `try/finally`
- Wrap response data in entity classes: `new ObjectEntity(data)`
- Store actions return `{ response, data }` tuple
- All stores exported from central `store/store.js`
- Entity state initializes as `false` (not `null`) when empty
