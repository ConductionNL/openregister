<template>
	<div class="register-objects-tab">
		<!-- Loading state -->
		<div v-if="loading" class="register-objects-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<!-- Error state -->
		<NcEmptyContent v-else-if="error"
			:name="t('openregister', 'Failed to load register data')"
			:description="errorMessage">
			<template #icon>
				<span class="material-design-icon" v-html="alertCircleIcon" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent v-else-if="objects.length === 0"
			:name="t('openregister', 'No register objects reference this file')">
			<template #icon>
				<span class="material-design-icon" v-html="databaseOffIcon" />
			</template>
		</NcEmptyContent>

		<!-- Objects list -->
		<ul v-else class="register-objects-tab__list">
			<li v-for="obj in objects"
				:key="obj.uuid"
				class="register-objects-tab__item">
				<a :href="getObjectUrl(obj)"
					class="register-objects-tab__link"
					:aria-label="getAriaLabel(obj)">
					<div class="register-objects-tab__title">
						{{ obj.title }}
					</div>
					<div class="register-objects-tab__meta">
						<span class="register-objects-tab__register">
							{{ obj.register.title }}
						</span>
						<span class="register-objects-tab__separator">&middot;</span>
						<span class="register-objects-tab__schema">
							{{ obj.schema.title }}
						</span>
					</div>
				</a>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

// database-off-outline SVG
const databaseOffIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1,4.27L2.28,3L21,21.72L19.73,23L17.73,21C16.07,21.56 13.85,22 12,22C7.58,22 4,20.21 4,18V8C4,7.17 4.8,6.35 6.13,5.71L1,4.27M18,14.8V8.64C16.53,9.47 14.39,10 12,10C11.15,10 10.31,9.93 9.5,9.8L18,14.8M20,8V12.5L18,10.5V8.64C18.72,8.22 19.26,7.74 19.57,7.27C18.84,6.16 16,5 12,5C10.93,5 9.93,5.12 9.04,5.3L7.47,3.73C8.81,3.26 10.35,3 12,3C16.42,3 20,4.79 20,7V8M4,14.77C5.61,15.55 7.72,16 10,16L4,10V14.77M12,20C13.82,20 15.53,19.64 16.86,19.08L12.13,14.34C10.12,14.23 8.21,13.82 6.72,13.15L6,12.8V17.5C6,18.5 8.13,20 12,20Z" /></svg>'

// alert-circle-outline SVG
const alertCircleIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M11,15H13V17H11V15M11,7H13V13H11V7M12,2C6.47,2 2,6.5 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20Z" /></svg>'

export default {
	name: 'RegisterObjectsTab',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		fileId: {
			type: Number,
			required: true,
		},
	},

	data() {
		return {
			loading: false,
			error: false,
			errorMessage: '',
			objects: [],
			databaseOffIcon,
			alertCircleIcon,
		}
	},

	watch: {
		fileId: {
			handler(newVal) {
				if (newVal) {
					this.fetchObjects()
				}
			},
			immediate: true,
		},
	},

	methods: {
		t,

		/**
		 * Fetch objects referencing this file from the API.
		 */
		async fetchObjects() {
			this.loading = true
			this.error = false
			this.errorMessage = ''
			this.objects = []

			try {
				const url = generateUrl('/apps/openregister/api/files/{fileId}/objects', {
					fileId: this.fileId,
				})
				const response = await axios.get(url)

				if (response.data?.success) {
					this.objects = response.data.data || []
				} else {
					this.error = true
					this.errorMessage = response.data?.error || t('openregister', 'Unknown error')
				}
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message
				console.error('[RegisterObjectsTab] Failed to fetch objects:', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Generate the URL to view an object in the OpenRegister app.
		 *
		 * @param {object} obj The object data
		 * @return {string} The absolute URL to the object detail page
		 */
		getObjectUrl(obj) {
			return generateUrl(
				'/apps/openregister/registers/{registerId}/schemas/{schemaId}/objects/{uuid}',
				{
					registerId: obj.register.id,
					schemaId: obj.schema.id,
					uuid: obj.uuid,
				},
			)
		},

		/**
		 * Generate an accessible label for the object link.
		 *
		 * @param {object} obj The object data
		 * @return {string} Accessible label text
		 */
		getAriaLabel(obj) {
			return t('openregister', '{title} in {register} / {schema}', {
				title: obj.title,
				register: obj.register.title,
				schema: obj.schema.title,
			})
		},
	},
}
</script>

<style scoped>
.register-objects-tab {
	padding: 10px;
}

.register-objects-tab__loading {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 100px;
}

.register-objects-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.register-objects-tab__item {
	margin: 0;
	padding: 0;
}

.register-objects-tab__link {
	display: block;
	padding: 8px 12px;
	border-radius: var(--border-radius-large, 6px);
	color: var(--color-main-text);
	text-decoration: none;
	transition: background-color 0.1s ease;
}

.register-objects-tab__link:hover,
.register-objects-tab__link:focus {
	background-color: var(--color-background-hover);
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.register-objects-tab__title {
	font-weight: bold;
	margin-bottom: 2px;
}

.register-objects-tab__meta {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.register-objects-tab__separator {
	margin: 0 4px;
}

.material-design-icon {
	display: inline-flex;
}

.material-design-icon :deep(svg) {
	width: 64px;
	height: 64px;
	fill: currentColor;
}
</style>
