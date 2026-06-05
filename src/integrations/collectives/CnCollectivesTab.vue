<!--
  CnCollectivesTab — sidebar tab for the Collectives integration.

  Lists linked Collectives pages with markdown preview, provides a
  collective → page picker to link existing pages, and allows unlinking.

  @spec openspec/changes/integration-collectives/tasks.md#task-6
-->
<template>
	<div class="cn-collectives-tab">
		<!-- Availability guard -->
		<NcEmptyContent
			v-if="!available"
			:title="t('openregister', 'Collectives not installed')"
			:description="t('openregister', 'Install the Collectives app to link knowledge pages.')">
			<template #icon>
				<BookOpenPageVariant :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Link picker -->
			<div class="cn-collectives-tab__picker">
				<NcSelect
					v-model="selectedCollective"
					:options="collectives"
					:loading="loadingCollectives"
					:input-label="t('openregister', 'Collective')"
					:placeholder="t('openregister', 'Select collective…')"
					label="title"
					:reduce="c => c"
					@open="loadCollectives" />
				<NcSelect
					v-if="selectedCollective"
					v-model="selectedPage"
					:options="pages"
					:loading="loadingPages"
					:input-label="t('openregister', 'Page')"
					:placeholder="t('openregister', 'Select page…')"
					label="title"
					:reduce="p => p"
					@open="loadPages" />
				<NcButton
					v-if="selectedPage"
					:disabled="linking"
					@click="linkPage">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Link page') }}
				</NcButton>
			</div>

			<!-- Linked pages list -->
			<div v-if="loadingLinks" class="cn-collectives-tab__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<NcEmptyContent
				v-else-if="!links.length"
				:title="t('openregister', 'No pages linked')"
				:description="t('openregister', 'Use the picker above to link a Collectives page.')">
				<template #icon>
					<BookOpenPageVariant :size="48" />
				</template>
			</NcEmptyContent>

			<ul v-else class="cn-collectives-tab__list">
				<li
					v-for="link in links"
					:key="link.id"
					class="cn-collectives-tab__item">
					<div class="cn-collectives-tab__item-header">
						<BookOpenPageVariant :size="20" class="cn-collectives-tab__item-icon" />
						<span class="cn-collectives-tab__item-title">
							{{ link.pageTitle || t('openregister', 'Untitled page') }}
						</span>
						<span class="cn-collectives-tab__item-collective">
							{{ link.collectiveName }}
						</span>
						<NcActions>
							<NcActionLink
								:href="link.pageUrl"
								target="_blank">
								<template #icon>
									<OpenInNew :size="20" />
								</template>
								{{ t('openregister', 'Open in Collectives') }}
							</NcActionLink>
							<NcActionButton @click="togglePreview(link)">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openregister', 'Preview') }}
							</NcActionButton>
							<NcActionButton
								:disabled="unlinking === link.id"
								@click="unlinkPage(link)">
								<template #icon>
									<LinkOff :size="20" />
								</template>
								{{ t('openregister', 'Unlink') }}
							</NcActionButton>
						</NcActions>
					</div>

					<!-- Markdown preview (collapsible) -->
					<div
						v-if="previewOpen[link.id]"
						class="cn-collectives-tab__preview">
						<div v-if="previewLoading[link.id]" class="cn-collectives-tab__preview-loading">
							<NcLoadingIcon :size="24" />
						</div>
						<div
							v-else-if="previewContent[link.id] === false"
							class="cn-collectives-tab__preview-noaccess">
							{{ t('openregister', 'No access to this page') }}
						</div>
						<div
							v-else-if="previewContent[link.id]"
							class="cn-collectives-tab__markdown"
							v-html="renderMarkdown(previewContent[link.id])" />
					</div>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import NcActionLink from '@nextcloud/vue/dist/Components/NcActionLink.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'CnCollectivesTab',

	components: {
		NcActions,
		NcActionButton,
		NcActionLink,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		BookOpenPageVariant,
		Eye,
		LinkOff,
		OpenInNew,
		Plus,
	},

	props: {
		/** Register slug */
		register: {
			type: String,
			required: true,
		},
		/** Schema slug */
		schema: {
			type: String,
			required: true,
		},
		/** Object ID */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			available: true,
			links: [],
			loadingLinks: false,
			collectives: [],
			loadingCollectives: false,
			selectedCollective: null,
			pages: [],
			loadingPages: false,
			selectedPage: null,
			linking: false,
			unlinking: null,
			previewOpen: {},
			previewLoading: {},
			previewContent: {},
		}
	},

	mounted() {
		this.loadLinks()
	},

	methods: {
		t,

		async loadLinks() {
			this.loadingLinks = true
			try {
				const { data } = await axios.get(
					`/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.objectId)}/collectives`,
				)
				this.links = data.results ?? []
			} catch (err) {
				if (err.response?.status === 501) {
					this.available = false
				} else {
					showError(t('openregister', 'Failed to load linked pages'))
				}
			} finally {
				this.loadingLinks = false
			}
		},

		async loadCollectives() {
			if (this.collectives.length) return
			this.loadingCollectives = true
			try {
				const { data } = await axios.get('/apps/openregister/api/collectives')
				this.collectives = data.results ?? []
			} catch {
				showError(t('openregister', 'Failed to load collectives'))
			} finally {
				this.loadingCollectives = false
			}
		},

		async loadPages() {
			if (!this.selectedCollective) return
			this.loadingPages = true
			this.pages = []
			try {
				const name = encodeURIComponent(this.selectedCollective.name)
				const { data } = await axios.get(`/apps/openregister/api/collectives/${name}/pages`)
				this.pages = data.results ?? []
			} catch {
				showError(t('openregister', 'Failed to load pages'))
			} finally {
				this.loadingPages = false
			}
		},

		async linkPage() {
			if (!this.selectedCollective || !this.selectedPage) return
			this.linking = true
			try {
				await axios.post(
					`/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.objectId)}/collectives`,
					{
						collectiveName: this.selectedCollective.name,
						pageId: this.selectedPage.id,
						pageTitle: this.selectedPage.title,
					},
				)
				this.selectedCollective = null
				this.selectedPage = null
				await this.loadLinks()
			} catch (err) {
				showError(
					err.response?.status === 409
						? t('openregister', 'This page is already linked')
						: t('openregister', 'Failed to link page'),
				)
			} finally {
				this.linking = false
			}
		},

		async unlinkPage(link) {
			this.unlinking = link.id
			try {
				await axios.delete(
					`/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.objectId)}/collectives/${link.id}`,
				)
				this.links = this.links.filter((l) => l.id !== link.id)
			} catch {
				showError(t('openregister', 'Failed to unlink page'))
			} finally {
				this.unlinking = null
			}
		},

		async togglePreview(link) {
			const id = link.id
			if (this.previewOpen[id]) {
				this.$set(this.previewOpen, id, false)
				return
			}

			this.$set(this.previewOpen, id, true)

			if (this.previewContent[id] !== undefined) return

			this.$set(this.previewLoading, id, true)
			try {
				const { data } = await axios.get(
					`/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.objectId)}/collectives/${id}/content`,
				)
				this.$set(this.previewContent, id, data.content ?? '')
			} catch (err) {
				this.$set(this.previewContent, id, err.response?.status === 403 ? false : '')
			} finally {
				this.$set(this.previewLoading, id, false)
			}
		},

		/**
		 * Render markdown to safe HTML subset (plain text fallback).
		 * @param md
		 */
		renderMarkdown(md) {
			if (!md) return ''
			// Use marked if available via @conduction/nextcloud-vue, else plain text.
			if (window.marked) {
				return window.marked.parse(md)
			}

			return md
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/\n/g, '<br>')
		},
	},
}
</script>

<style lang="scss" scoped>
.cn-collectives-tab {
	padding: 12px;

	&__picker {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin-bottom: 16px;
	}

	&__list {
		list-style: none;
		padding: 0;
		margin: 0;
	}

	&__item {
		border: 1px solid var(--color-border);
		border-radius: 8px;
		margin-bottom: 8px;
		padding: 8px 12px;
	}

	&__item-header {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__item-icon {
		color: var(--color-primary);
		flex-shrink: 0;
	}

	&__item-title {
		flex: 1;
		font-weight: bold;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__item-collective {
		color: var(--color-text-maxcontrast);
		font-size: 12px;
	}

	&__preview {
		margin-top: 8px;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
		max-height: 300px;
		overflow-y: auto;
	}

	&__preview-loading,
	&__preview-noaccess {
		text-align: center;
		padding: 16px;
		color: var(--color-text-maxcontrast);
	}

	&__markdown {
		font-size: 14px;
		line-height: 1.5;

		:deep(h1), :deep(h2), :deep(h3) {
			font-weight: bold;
			margin: 8px 0 4px;
		}

		:deep(pre) {
			background: var(--color-background-dark);
			padding: 8px;
			border-radius: 4px;
			overflow-x: auto;
		}
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: 32px;
	}
}
</style>
