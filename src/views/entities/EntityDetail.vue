<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Loading State -->
			<NcLoadingIcon v-if="loading" :size="64" />

			<!-- Error State -->
			<NcEmptyContent
				v-else-if="error"
				:name="t('openregister', 'Error loading entity')"
				:description="error">
				<template #icon>
					<AlertCircleOutline :size="64" />
				</template>
				<template #action>
					<NcButton @click="$router.push('/entities')">
						{{ t('openregister', 'Back to Entities') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<!-- Entity Details -->
			<div v-else-if="entity" class="entityDetailContainer">
				<!-- Header -->
				<div class="viewHeader">
					<div class="viewHeaderTitle">
						<NcButton
							type="tertiary"
							:aria-label="t('openregister', 'Back to entities')"
							@click="$router.push('/entities')">
							<template #icon>
								<ArrowLeft :size="20" />
							</template>
						</NcButton>
						<h1 class="viewHeaderTitleIndented">
							<AccountOutline :size="28" />
							{{ entity.value }}
						</h1>
						<span class="badge badge-type">{{ entity.type }}</span>
					</div>
					<p>
						{{ t('openregister', 'View entity details and manage relations') }}
					</p>
				</div>

				<!-- Actions Bar -->
				<div class="viewActionsBar">
					<div class="viewInfo">
						<span class="viewTotalCount">
							{{ t('openregister', 'Entity ID: {id}', { id: entity.id }) }}
						</span>
					</div>
					<div class="viewActions">
						<NcActions
							:force-name="true"
							:inline="1"
							menu-name="Actions">
							<NcActionButton
								close-after-click
								@click="refreshEntity">
								<template #icon>
									<Refresh :size="20" />
								</template>
								{{ t('openregister', 'Refresh') }}
							</NcActionButton>
						</NcActions>
					</div>
				</div>

				<!-- Entity Information Card -->
				<div class="card">
					<div class="cardHeader">
						<h2>{{ t('openregister', 'Entity Information') }}</h2>
					</div>

					<table class="statisticsTable entityInfoTable">
						<tbody>
							<tr>
								<td><strong>{{ t('openregister', 'Value') }}</strong></td>
								<td>{{ entity.value }}</td>
							</tr>
							<tr>
								<td><strong>{{ t('openregister', 'Type') }}</strong></td>
								<td><span class="badge badge-type">{{ entity.type }}</span></td>
							</tr>
							<tr>
								<td><strong>{{ t('openregister', 'Category') }}</strong></td>
								<td><span class="badge badge-category">{{ entity.category }}</span></td>
							</tr>
							<tr>
								<td><strong>{{ t('openregister', 'Detected At') }}</strong></td>
								<td>{{ formatDate(entity.detectedAt) }}</td>
							</tr>
							<tr>
								<td><strong>{{ t('openregister', 'Confidence Score') }}</strong></td>
								<td>{{ entity.confidence ? (entity.confidence * 100).toFixed(2) + '%' : '-' }}</td>
							</tr>
							<tr v-if="entity.source">
								<td><strong>{{ t('openregister', 'Source') }}</strong></td>
								<td>{{ entity.source }}</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Relations Section -->
				<div class="card">
					<div class="cardHeader">
						<h2>
							{{ t('openregister', 'Relations') }}
							<span class="badge badge-count">{{ relations.length }}</span>
						</h2>
					</div>

					<NcEmptyContent
						v-if="!loadingRelations && !relations.length"
						:name="t('openregister', 'No relations found')"
						:description="t('openregister', 'This entity has no relations to objects or files')">
						<template #icon>
							<LinkVariantOff :size="64" />
						</template>
					</NcEmptyContent>

					<div v-else-if="loadingRelations" class="relationLoading">
						<NcLoadingIcon :size="32" />
					</div>

					<div v-else class="relationsContainer">
						<div v-for="relation in relations" :key="relation.id" class="relationCard">
							<div class="relationHeader">
								<div class="relationTitle">
									<FileDocumentOutline v-if="relation.fileId" :size="20" />
									<DatabaseOutline v-else-if="relation.objectId" :size="20" />
									<TextBoxOutline v-else :size="20" />
									<strong>
										{{ getRelationTitle(relation) }}
									</strong>
								</div>
								<span class="relationType">{{ getRelationType(relation) }}</span>
							</div>
							<div v-if="relation.context" class="relationDescription">
								{{ relation.context }}
							</div>
							<div class="relationMeta">
								<span v-if="relation.confidence">
									{{ t('openregister', 'Confidence: {confidence}%', { confidence: (relation.confidence * 100).toFixed(1) }) }}
								</span>
								<span v-if="relation.detectionMethod">
									{{ t('openregister', 'Method: {method}', { method: relation.detectionMethod }) }}
								</span>
								<span v-if="relation.createdAt">
									{{ t('openregister', 'Detected: {date}', { date: formatDate(relation.createdAt) }) }}
								</span>
							</div>
							<div class="relationActions">
								<NcButton
									v-if="relation.objectId"
									type="secondary"
									@click="viewObject(relation)">
									<template #icon>
										<EyeOutline :size="20" />
									</template>
									{{ t('openregister', 'View Object') }}
								</NcButton>
								<NcButton
									v-if="relation.fileId"
									type="secondary"
									@click="viewFile(relation)">
									<template #icon>
										<EyeOutline :size="20" />
									</template>
									{{ t('openregister', 'View File') }}
								</NcButton>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

import {
	NcAppContent,
	NcButton,
	NcLoadingIcon,
	NcEmptyContent,
	NcActions,
	NcActionButton,
} from '@nextcloud/vue'

import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import LinkVariantOff from 'vue-material-design-icons/LinkVariantOff.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'

/**
 * Entity detail view showing entity information and relations
 *
 * @package
 * @category View
 * @author Ruben Linde <ruben@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license EUPL-1.2
 * @version 1.0.0
 * @link https://github.com/ConductionNL/openregister
 */
export default {
	name: 'EntityDetail',
	components: {
		NcAppContent,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcActions,
		NcActionButton,
		AccountOutline,
		ArrowLeft,
		Refresh,
		AlertCircleOutline,
		LinkVariantOff,
		FileDocumentOutline,
		DatabaseOutline,
		TextBoxOutline,
		EyeOutline,
	},
	data() {
		return {
			entity: null,
			relations: [],
			loading: true,
			loadingRelations: false,
			error: null,
		}
	},
	mounted() {
		this.loadEntity()
	},
	methods: {
		t,

		/**
		 * Load entity from the API
		 *
		 * @return {Promise<void>}
		 */
		async loadEntity() {
			this.loading = true
			this.loadingRelations = true
			this.error = null

			try {
				const entityId = this.$route.params.id
				const response = await axios.get(
					generateUrl('/apps/openregister/api/entities/{id}', { id: entityId }),
				)

				if (response.data.success) {
					this.entity = response.data.data
					// Relations are returned in the same response.
					this.relations = response.data.relations || []
				} else {
					this.error = response.data.message || t('openregister', 'Failed to load entity')
				}
			} catch (error) {
				console.error('Failed to load entity:', error)
				this.error = error.response?.data?.message || t('openregister', 'Failed to load entity')
				showError(this.error)
			} finally {
				this.loading = false
				this.loadingRelations = false
			}
		},

		/**
		 * Refresh entity data
		 *
		 * @return {void}
		 */
		refreshEntity() {
			this.loadEntity()
		},

		/**
		 * Get relation type string
		 *
		 * @param {object} relation - Relation object
		 * @return {string} Type description
		 */
		getRelationType(relation) {
			if (relation.fileId) {
				return t('openregister', 'File')
			}
			if (relation.objectId) {
				return t('openregister', 'Object')
			}
			return t('openregister', 'Chunk')
		},

		/**
		 * Get relation title
		 *
		 * @param {object} relation - Relation object
		 * @return {string} Title string
		 */
		getRelationTitle(relation) {
			if (relation.fileId) {
				return t('openregister', 'File #{id}', { id: relation.fileId })
			}
			if (relation.objectId) {
				return t('openregister', 'Object #{id}', { id: relation.objectId })
			}
			return t('openregister', 'Text Chunk #{id}', { id: relation.chunkId })
		},

		/**
		 * View object details
		 *
		 * @param {object} relation - Relation object
		 * @return {void}
		 */
		viewObject(relation) {
			if (relation.objectId) {
				this.$router.push({ path: '/objects', query: { id: relation.objectId } })
			}
		},

		/**
		 * View file in Nextcloud Files app with details sidebar
		 *
		 * @param {object} relation - Relation object
		 * @return {void}
		 */
		viewFile(relation) {
			if (relation.fileId) {
				// Navigate to Nextcloud Files app with the file ID and open details sidebar.
				// This will open the file in the native Nextcloud file viewer with the details panel showing all tabs (Activity, Sharing, Versions).
				window.location.href = `${window.location.origin}/index.php/apps/files/?fileid=${relation.fileId}&opendetails=true`
			}
		},

		/**
		 * Format date for display
		 *
		 * @param {string} date - Date string
		 * @return {string} Formatted date
		 */
		formatDate(date) {
			if (!date) return '-'
			return new Date(date).toLocaleString()
		},
	},
}
</script>

<style scoped lang="scss">
.viewContainer {
	padding: 20px;
	max-width: 100%;
}

.viewHeader {
	margin-bottom: 20px;
}

.viewHeaderTitle {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 8px;
}

.viewHeaderTitleIndented {
	margin: 0;
	font-size: 28px;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 12px;
}

.viewHeader p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.viewActionsBar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.viewInfo {
	display: flex;
	gap: 12px;
	align-items: center;
}

.viewTotalCount {
	font-weight: 600;
}

.viewActions {
	display: flex;
	gap: 8px;
}

.entityDetailContainer {
	max-width: 1200px;
}

.card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	margin-bottom: 20px;
}

.cardHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.cardHeader h2 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 8px;
}

.entityInfoTable {
	width: 100%;
	border-collapse: collapse;
}

.entityInfoTable tbody tr {
	border-bottom: 1px solid var(--color-border);
}

.entityInfoTable tbody tr:last-child {
	border-bottom: none;
}

.entityInfoTable td {
	padding: 12px 16px;
}

.entityInfoTable td:first-child {
	width: 30%;
	color: var(--color-text-maxcontrast);
}

.badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
}

.badge-type {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}

.badge-category {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.badge-count {
	background: var(--color-primary-element);
	color: white;
	margin-left: 8px;
}

.relationLoading {
	padding: 40px;
	display: flex;
	justify-content: center;
	align-items: center;
}

.relationsContainer {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.relationCard {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.relationHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.relationTitle {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
}

.relationType {
	padding: 2px 8px;
	background: var(--color-background-dark);
	border-radius: 8px;
	font-size: 11px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.relationDescription {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	margin-bottom: 8px;
	line-height: 1.4;
}

.relationMeta {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.relationActions {
	display: flex;
	gap: 8px;
}
</style>
