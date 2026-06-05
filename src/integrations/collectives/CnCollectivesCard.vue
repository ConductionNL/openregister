<!--
  CnCollectivesCard — widget card for the Collectives integration.

  Renders across four surfaces:
    - detail-page   : inline content of the most-recently linked page, with tabs if >1
    - user-dashboard: recent linked pages (list)
    - app-dashboard : scoped list
    - single-entity : page-title chip

  @spec openspec/changes/integration-collectives/tasks.md#task-7
-->
<template>
	<div class="cn-collectives-card" :class="`cn-collectives-card--${surface}`">
		<!-- single-entity: page-title chip -->
		<template v-if="surface === 'single-entity'">
			<span
				v-if="latestLink"
				class="cn-collectives-card__chip"
				:title="latestLink.collectiveName">
				<BookOpenPageVariant :size="14" />
				{{ latestLink.pageTitle || t('openregister', 'Page') }}
			</span>
			<span v-else class="cn-collectives-card__chip cn-collectives-card__chip--empty">
				{{ t('openregister', 'No page') }}
			</span>
		</template>

		<!-- detail-page: inline markdown with multi-page tabs -->
		<template v-else-if="surface === 'detail-page'">
			<div v-if="loading" class="cn-collectives-card__loading">
				<NcLoadingIcon :size="32" />
			</div>
			<template v-else-if="links.length">
				<!-- Tabs when more than one link -->
				<div v-if="links.length > 1" class="cn-collectives-card__tabs">
					<button
						v-for="(link, idx) in links"
						:key="link.id"
						class="cn-collectives-card__tab"
						:class="{ 'cn-collectives-card__tab--active': activeTab === idx }"
						@click="switchTab(idx)">
						{{ link.pageTitle || t('openregister', 'Page') }}
					</button>
				</div>

				<div class="cn-collectives-card__content-wrapper">
					<div class="cn-collectives-card__content-header">
						<span class="cn-collectives-card__content-title">
							{{ activeLink.pageTitle || t('openregister', 'Untitled page') }}
						</span>
						<a
							v-if="activeLink.pageUrl"
							:href="activeLink.pageUrl"
							target="_blank"
							class="cn-collectives-card__open-link">
							{{ t('openregister', 'Open in Collectives') }}
							<OpenInNew :size="14" />
						</a>
					</div>

					<div v-if="contentLoading" class="cn-collectives-card__loading">
						<NcLoadingIcon :size="24" />
					</div>
					<div
						v-else-if="activeContent === false"
						class="cn-collectives-card__noaccess">
						{{ t('openregister', 'No access to this page') }}
					</div>
					<div
						v-else-if="activeContent"
						class="cn-collectives-card__markdown"
						v-html="renderMarkdown(activeContent)" />
				</div>
			</template>
			<NcEmptyContent
				v-else
				:title="t('openregister', 'No pages linked')"
				:description="t('openregister', 'Link a Collectives page via the sidebar.')" />
		</template>

		<!-- user-dashboard / app-dashboard: recent linked pages list -->
		<template v-else>
			<div v-if="loading" class="cn-collectives-card__loading">
				<NcLoadingIcon :size="32" />
			</div>
			<ul v-else-if="links.length" class="cn-collectives-card__list">
				<li
					v-for="link in links"
					:key="link.id"
					class="cn-collectives-card__list-item">
					<BookOpenPageVariant :size="16" />
					<a
						:href="link.pageUrl"
						target="_blank"
						class="cn-collectives-card__list-title">
						{{ link.pageTitle || t('openregister', 'Untitled page') }}
					</a>
					<span class="cn-collectives-card__list-collective">
						{{ link.collectiveName }}
					</span>
				</li>
			</ul>
			<NcEmptyContent
				v-else
				:title="t('openregister', 'No pages linked')" />
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
	name: 'CnCollectivesCard',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		BookOpenPageVariant,
		OpenInNew,
	},

	props: {
		/** One of: detail-page, user-dashboard, app-dashboard, single-entity */
		surface: {
			type: String,
			required: true,
			validator: (v) => ['detail-page', 'user-dashboard', 'app-dashboard', 'single-entity'].includes(v),
		},
		/** Register slug (required for object-scoped surfaces) */
		register: {
			type: String,
			default: null,
		},
		/** Schema slug */
		schema: {
			type: String,
			default: null,
		},
		/** Object ID */
		objectId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			links: [],
			loading: false,
			activeTab: 0,
			contentCache: {},
			contentLoading: false,
		}
	},

	computed: {
		latestLink() {
			return this.links[0] ?? null
		},

		activeLink() {
			return this.links[this.activeTab] ?? null
		},

		activeContent() {
			if (!this.activeLink) return null
			return this.contentCache[this.activeLink.id] ?? null
		},
	},

	mounted() {
		this.loadLinks()
	},

	methods: {
		t,

		async loadLinks() {
			if (!this.register || !this.schema || !this.objectId) return
			this.loading = true
			try {
				const url = `/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.objectId)}/collectives`
				const { data } = await axios.get(url)
				this.links = data.results ?? []

				if (this.surface === 'detail-page' && this.links.length) {
					this.loadContent(this.links[0])
				}
			} catch {
				// Silently fail for widget contexts.
			} finally {
				this.loading = false
			}
		},

		async switchTab(idx) {
			this.activeTab = idx
			if (this.links[idx] && this.contentCache[this.links[idx].id] === undefined) {
				await this.loadContent(this.links[idx])
			}
		},

		async loadContent(link) {
			if (!link || this.contentCache[link.id] !== undefined) return
			this.contentLoading = true
			try {
				const url = `/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.objectId)}/collectives/${link.id}/content`
				const { data } = await axios.get(url)
				this.$set(this.contentCache, link.id, data.content ?? '')
			} catch (err) {
				this.$set(this.contentCache, link.id, err.response?.status === 403 ? false : '')
			} finally {
				this.contentLoading = false
			}
		},

		/**
		 * Render markdown to safe HTML (plain text fallback when marked not loaded).
		 * @param md
		 */
		renderMarkdown(md) {
			if (!md) return ''
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
.cn-collectives-card {
	padding: 8px;

	&__chip {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 8px;
		border-radius: 12px;
		background: var(--color-background-dark);
		font-size: 12px;

		&--empty {
			color: var(--color-text-maxcontrast);
		}
	}

	&__tabs {
		display: flex;
		gap: 4px;
		margin-bottom: 8px;
		overflow-x: auto;
	}

	&__tab {
		padding: 4px 12px;
		border: 1px solid var(--color-border);
		border-radius: 4px;
		background: none;
		cursor: pointer;
		white-space: nowrap;

		&--active {
			background: var(--color-primary-light);
			border-color: var(--color-primary);
		}
	}

	&__content-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 8px;
	}

	&__content-title {
		font-weight: bold;
	}

	&__open-link {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-size: 12px;
		color: var(--color-primary);
		text-decoration: none;

		&:hover {
			text-decoration: underline;
		}
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: 16px;
	}

	&__noaccess {
		text-align: center;
		padding: 16px;
		color: var(--color-text-maxcontrast);
	}

	&__markdown {
		font-size: 14px;
		line-height: 1.5;
		max-height: 400px;
		overflow-y: auto;

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

	&__list {
		list-style: none;
		padding: 0;
		margin: 0;
	}

	&__list-item {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 6px 0;
		border-bottom: 1px solid var(--color-border);

		&:last-child {
			border-bottom: none;
		}
	}

	&__list-title {
		flex: 1;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		color: var(--color-primary);
		text-decoration: none;

		&:hover {
			text-decoration: underline;
		}
	}

	&__list-collective {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
