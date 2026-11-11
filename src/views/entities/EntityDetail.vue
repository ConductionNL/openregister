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
							variant="tertiary"
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
						{{
							t(
								'openregister',
								'View entity details and manage relations',
							)
						}}
					</p>
				</div>

				<!-- Actions Bar -->
				<ListActionsBar
					:summary="
						t('openregister', 'Entity ID: {id}', { id: entity.id })
					"
					:actions="[refreshAction(refreshEntity)]" />

				<!-- Entity Information Card -->
				<div class="card">
					<div class="cardHeader">
						<h2>{{ t('openregister', 'Entity Information') }}</h2>
					</div>

					<EntityStatsTable
						class="statisticsTable entityInfoTable"
						labelWidth="30%"
						:entity="entity"
						:fields="[
							'value',
							'type',
							'category',
							'detectedAt',
							'confidence',
							'source',
						]" />
				</div>

				<!-- Relations Section -->
				<div class="card">
					<div class="cardHeader">
						<h2>
							{{ t('openregister', 'Relations') }}
							<span class="badge badge-count">{{
								relations.length
							}}</span>
						</h2>
					</div>

					<NcEmptyContent
						v-if="!loadingRelations && !relations.length"
						:name="t('openregister', 'No relations found')"
						:description="
							t(
								'openregister',
								'This entity has no relations to objects or files',
							)
						">
						<template #icon>
							<LinkVariantOff :size="64" />
						</template>
					</NcEmptyContent>

					<div v-else-if="loadingRelations" class="relationLoading">
						<NcLoadingIcon :size="32" />
					</div>

					<div v-else class="relationsContainer">
						<div
							v-for="relation in relations"
							:key="relation.id"
							class="relationCard">
							<div class="relationHeader">
								<div class="relationTitle">
									<FileDocumentOutline
										v-if="relation.fileId"
										:size="20" />
									<DatabaseOutline
										v-else-if="relation.objectId"
										:size="20" />
									<TextBoxOutline v-else :size="20" />
									<strong>
										{{ getRelationTitle(relation) }}
									</strong>
								</div>
								<span class="relationType">{{
									getRelationType(relation)
								}}</span>
							</div>
							<div v-if="relation.context" class="relationDescription">
								{{ relation.context }}
							</div>
							<div class="relationMeta">
								<span v-if="relation.confidence">
									{{
										t(
											'openregister',
											'Confidence: {confidence}%',
											{
												confidence: (
													relation.confidence * 100
												).toFixed(1),
											},
										)
									}}
								</span>
								<span v-if="relation.detectionMethod">
									{{
										t('openregister', 'Method: {method}', {
											method: relation.detectionMethod,
										})
									}}
								</span>
								<span v-if="relation.createdAt">
									{{
										t('openregister', 'Detected: {date}', {
											date: formatDate(relation.createdAt),
										})
									}}
								</span>
							</div>
							<div class="relationActions">
								<NcButton
									v-if="relation.objectId"
									variant="secondary"
									@click="viewObject(relation)">
									<template #icon>
										<EyeOutline :size="20" />
									</template>
									{{ t('openregister', 'View Object') }}
								</NcButton>
								<NcButton
									v-if="relation.fileId"
									variant="secondary"
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
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import LinkVariantOff from 'vue-material-design-icons/LinkVariantOff.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import ListActionsBar from '../../components/shared/ListActionsBar.vue'
import EntityStatsTable from './EntityStatsTable.vue'
import { displayDateTime } from '../../services/displayDateTime.js'
import { refreshAction } from '../../services/menuActions.js'

/**
 * Entity detail view showing entity information and relations
 *
 * @visual exclude No hermetic e2e can reach this page, because nothing can
 *   create the record it displays. `openregister_entities` rows are DETECTED
 *   PII, and the routed surface is read-only: `appinfo/routes.php` registers
 *   `gdprEntities#index`, `#show`, `#destroy`, `#getTypes`, `#getCategories`
 *   and `#getStats` — and NO create. The only writer anywhere is
 *   `fileText#addManualEntity`, which `ManualEntityService` refuses unless the
 *   target Nextcloud file already carries EXTRACTED CHUNKS, i.e. it needs an
 *   uploaded file plus a completed text-extraction run — neither hermetic nor
 *   available in CI. The alternative was a spec that navigates to whatever row
 *   happens to exist and guards its assertions, which passes without asserting
 *   on an empty instance; `tests/e2e/ci/playwright.config.ts` names that shape
 *   as admission criterion 3 and refuses it, and so does this file. Remove this
 *   waiver and write a real spec on the change that gives entities a create
 *   endpoint or a seedable extraction fixture; it records that the record
 *   cannot be made, not that the screen needs no proof.
 *
 * @package
 * @category View
 * @author Ruben Linde <ruben@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/ConductionNL/openregister
 */
export default {
	name: 'EntityDetail',
	components: {
		EntityStatsTable,
		ListActionsBar,
		NcAppContent,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		AccountOutline,
		ArrowLeft,
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
		refreshAction,

		t,

		/**
		 * Load entity from the API
		 *
		 * @spec exclude detail-view API fetch plumbing (linked-entity-types contract)
		 * @return {Promise<void>}
		 */
		async loadEntity() {
			this.loading = true
			this.loadingRelations = true
			this.error = null

			try {
				const response = await axios.get(this.entityUrl())
				this.showEntityAnswer(response.data)
			} catch (error) {
				this.showEntityFailure(error)
			} finally {
				this.loading = false
				this.loadingRelations = false
			}
		},

		/**
		 * The URL of the entity the route names.
		 *
		 * @spec exclude detail-view API fetch plumbing (linked-entity-types contract)
		 * @return {string} The URL
		 */
		entityUrl() {
			const entityId = this.$route.params.id
			return generateUrl('/apps/openregister/api/entities/{id}', {
				id: entityId,
			})
		},

		/**
		 * Take the entity and its relations from a successful answer, or the
		 * refusal's message.
		 *
		 * @spec exclude detail-view API fetch plumbing (linked-entity-types contract)
		 * @param {object} data The answer
		 * @return {void}
		 */
		showEntityAnswer(data) {
			if (data.success) {
				this.entity = data.data
				// Relations are returned in the same response.
				this.relations = data.relations || []
			} else {
				this.error =
					data.message
					|| t('openregister', 'Failed to load entity')
			}
		},

		/**
		 * Log and show a failed entity load in the API's words or the default.
		 *
		 * @spec exclude detail-view API fetch plumbing (linked-entity-types contract)
		 * @param {Error} error The failed request
		 * @return {void}
		 */
		showEntityFailure(error) {
			console.error('Failed to load entity:', error)
			this.error =
				error.response?.data?.message
				|| t('openregister', 'Failed to load entity')
			showError(this.error)
		},

		/**
		 * Refresh entity data
		 *
		 * @spec exclude detail-view manual refresh plumbing
		 * @return {void}
		 */
		refreshEntity() {
			this.loadEntity()
		},

		/**
		 * Get relation type string
		 *
		 * @spec exclude detail-view relation-type display helper
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
		 * @spec exclude detail-view relation-title display helper
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
		 * @spec exclude detail-view router-navigation plumbing to a related object
		 * @param {object} relation - Relation object
		 * @return {void}
		 */
		viewObject(relation) {
			if (relation.objectId) {
				this.$router.push({
					path: '/objects',
					query: { id: relation.objectId },
				})
			}
		},

		/**
		 * View file in Nextcloud Files app with details sidebar
		 *
		 * @spec exclude detail-view navigation plumbing to the Nextcloud Files app
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

		formatDate: displayDateTime,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;
@use './cardRules.scss' as *;

@include view-container-2;

.viewHeader {
	margin-bottom: 20px;
}

@include view-header-title;

.viewHeaderTitleIndented {
	margin: 0;
	font-size: 28px;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 12px;
}

@include view-header-p;

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
	@include card-header-row;
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

@include badge-rules-2;

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
