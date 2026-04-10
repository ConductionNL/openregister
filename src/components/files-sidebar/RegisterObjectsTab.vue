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
				<AlertCircleOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent v-else-if="objects.length === 0"
			:name="t('openregister', 'No register objects reference this file')">
			<template #icon>
				<DatabaseOffOutline :size="44" />
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
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import DatabaseOffOutline from 'vue-material-design-icons/DatabaseOffOutline.vue'

export default {
	name: 'RegisterObjectsTab',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutline,
		DatabaseOffOutline,
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
