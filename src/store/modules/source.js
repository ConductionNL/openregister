/* eslint-disable no-console */
import { createCrudStore } from '@conduction/nextcloud-vue'
import { Source } from '../../entities/index.js'

export const useSourceStore = createCrudStore('source', {
	endpoint: 'sources',
	entity: Source,
})
