<script setup>
import { translate as t } from '@nextcloud/l10n'
import {
	objectStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<template #list>
			<ObjectsList />
		</template>
		<template #default>
			<NcEmptyContent
				v-if="!objectStore.objectItem || !isObjectsRoute"
				class="detailContainer"
				name="No object"
				description="No object selected yet">
				<template #icon>
					<DatabaseOutline />
				</template>
				<template #action>
					<NcButton variant="primary" @click="addObject">
						Add Object
					</NcButton>
				</template>
			</NcEmptyContent>
			<ObjectDetails v-if="objectStore.objectItem && isObjectsRoute" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcEmptyContent } from '@nextcloud/vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import ObjectDetails from './ObjectDetails.vue'
import ObjectsList from './ObjectsList.vue'
import { openAddObjectModal } from '../../composables/openAddObject.js'

/**
 * Prime filter state up-front so the surrounding ObjectsList component (which
 * mounts in the same NcAppContent #list slot) has the (register, schema) pair
 * its refreshObjectList action needs — otherwise it throws 'Register and
 * schema are required.' mid-mount.
 *
 * @param {string} register The register from the route
 * @param {string} schema The schema from the route
 * @return {void}
 */
function primeListFilters(register, schema) {
	if (typeof objectStore.setFilters === 'function') {
		objectStore.setFilters({ register, schema })
	}
}

/**
 * Load the surrounding context the detail panel and the object list both
 * need. Without this a deep-link renders an empty object list ("No objects
 * defined yet") and leaves the detail panel without register/schema context.
 * Order matters: the list's `currentType` is derived from the register/schema
 * stores, so those must be primed before refreshObjectList runs. Non-fatal: a
 * context load failure must not block the object itself from rendering.
 *
 * @param {string} register The register from the route
 * @param {string} schema The schema from the route
 * @return {Promise<void>}
 */
async function loadListContext(register, schema) {
	await Promise.allSettled([
		registerStore.getRegister(register),
		schemaStore.getSchema(schema, { setItem: true }),
	])
	await objectStore.refreshObjectList().catch(() => {})
}

/**
 * Fetch the object a deep link names.
 *
 * @param {{register: string, schema: string, id: string}} params The route params
 * @return {Promise<object|undefined>} The object, or undefined when the server refused
 */
async function fetchLinkedObject(params) {
	const { register, schema, id } = params
	const url = `/index.php/apps/openregister/api/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/${encodeURIComponent(id)}`
	const response = await fetch(url, {
		headers: {
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
			requesttoken: OC?.requestToken,
		},
	})
	if (!response.ok) {
		console.error(
			`[ObjectsIndex] deep-link fetch ${url} returned ${response.status}`,
		)
		return undefined
	}
	return response.json()
}

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
			return (
				this.$route.path === '/objects'
				|| this.$route.name === 'objectDetail'
			)
		},
	},

	watch: {
		'$route.params.id': {
			/**
			 * Re-prime the object when the route id changes.
			 *
			 * @spec exclude UI plumbing — route watcher delegating to loadFromRoute; object contract owned by object-lifecycle.
			 * @return {void}
			 */
			handler() {
				this.loadFromRoute()
			},
		},
	},

	mounted() {
		this.loadFromRoute()
	},

	methods: {
		/**
		 * Fetch the deep-linked object and prime the store for ObjectDetails.
		 *
		 * @spec exclude UI plumbing — deep-link prime for the screenshot harness; object fetch contract owned by object-lifecycle.
		 * @return {Promise<void>}
		 */
		// Fetch the object referenced by /objects/:register/:schema/:id and
		// prime the store so ObjectDetails (with its registry-driven
		// integration tabs) renders. Used by the per-leaf screenshot harness
		// — a deterministic URL beats clicking through the registers ⇒
		// schemas ⇒ objects nav chain.
		async loadFromRoute() {
			const params = this.$route.params || {}
			const { register, schema, id } = params
			if (!register || !schema || !id) {
				return
			}
			try {
				primeListFilters(register, schema)
				await loadListContext(register, schema)
				const item = await fetchLinkedObject(params)
				if (item !== undefined) {
					objectStore.setObjectItem(item)
				}
			} catch (e) {
				console.error('[ObjectsIndex] deep-link fetch failed', e)
			}
		},

		/**
		 * Open the add-object modal with register/schema context.
		 *
		 * @spec exclude UI plumbing — store-set + modal dispatch; object creation contract owned by object-lifecycle.
		 * @return {void}
		 */
		addObject() {
			openAddObjectModal()
		},
	},
}
</script>
