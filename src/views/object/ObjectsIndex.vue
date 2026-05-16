<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<template #list>
			<ObjectsList />
		</template>
		<template #default>
			<NcEmptyContent v-if="!objectStore.objectItem || !isObjectsRoute"
				class="detailContainer"
				name="No object"
				description="No object selected yet">
				<template #icon>
					<DatabaseOutline />
				</template>
				<template #action>
					<NcButton type="primary" @click="addObject">
						Add Object
					</NcButton>
				</template>
			</NcEmptyContent>
			<ObjectDetails v-if="objectStore.objectItem && isObjectsRoute" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcButton } from '@nextcloud/vue'
import ObjectsList from './ObjectsList.vue'
import ObjectDetails from './ObjectDetails.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'

export default {
	name: 'ObjectsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		ObjectsList,
		ObjectDetails,
		DatabaseOutline,
	},
	computed: {
		// Both the original /objects route and the deep-link
		// /objects/:register/:schema/:id route should render the
		// ObjectDetails surface. Other routes (e.g. /registers/:id)
		// share this view tree via NcAppContent but should fall back
		// to the empty state.
		isObjectsRoute() {
			return this.$route.path === '/objects' || this.$route.name === 'objectDetail'
		},
	},
	mounted() {
		this.loadFromRoute()
	},
	watch: {
		'$route.params.id': {
			handler() {
				this.loadFromRoute()
			},
		},
	},
	methods: {
		// Fetch the object referenced by /objects/:register/:schema/:id and
		// prime the store so ObjectDetails (with its registry-driven
		// integration tabs) renders. Used by the per-leaf screenshot harness
		// — a deterministic URL beats clicking through the registers ⇒
		// schemas ⇒ objects nav chain.
		async loadFromRoute() {
			const { register, schema, id } = this.$route.params || {}
			if (!register || !schema || !id) {
				return
			}
			try {
				// Prime filter state up-front so the surrounding ObjectsList
				// component (which mounts in the same NcAppContent #list
				// slot) has the (register, schema) pair its refreshObjectList
				// action needs — otherwise it throws 'Register and schema
				// are required.' mid-mount.
				if (typeof objectStore.setFilters === 'function') {
					objectStore.setFilters({ register, schema })
				}
				const url = `/index.php/apps/openregister/api/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/${encodeURIComponent(id)}`
				const response = await fetch(url, {
					headers: {
						Accept: 'application/json',
						'OCS-APIRequest': 'true',
						requesttoken: OC?.requestToken,
					},
				})
				if (!response.ok) {
					console.error(`[ObjectsIndex] deep-link fetch ${url} returned ${response.status}`)
					return
				}
				const item = await response.json()
				objectStore.setObjectItem(item)
			} catch (e) {
				console.error('[ObjectsIndex] deep-link fetch failed', e)
			}
		},
		addObject() {
			// Clear any existing object and open the add object modal
			objectStore.setObjectItem(null)
			// Ensure register and schema are set for new object creation
			if (registerStore.registerItem) {
				registerStore.setRegisterItem(registerStore.registerItem)
			}
			if (schemaStore.schemaItem) {
				schemaStore.setSchemaItem(schemaStore.schemaItem)
			}
			navigationStore.setModal('viewObject')
		},
	},
}
</script>
