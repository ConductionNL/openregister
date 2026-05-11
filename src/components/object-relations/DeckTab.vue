<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="deck-tab">
		<!-- Toolbar -->
		<div v-if="!loading && !deckUnavailable" class="deck-tab__toolbar">
			<NcButton type="primary" @click="openCreateDialog">
				<template #icon>
					<TableLargePlus :size="20" />
				</template>
				{{ t('openregister', 'Add card') }}
			</NcButton>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="deck-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<!-- Error state -->
		<NcEmptyContent v-else-if="error"
			:name="t('openregister', 'Failed to load Deck cards')"
			:description="errorMessage">
			<template #icon>
				<AlertCircleOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Deck app missing (HTTP 501 graceful degradation) -->
		<NcEmptyContent v-else-if="deckUnavailable"
			:name="t('openregister', 'Deck integration is not available')"
			:description="t('openregister', 'The Nextcloud Deck app is not installed or enabled on this server.')">
			<template #icon>
				<TableRemove :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent v-else-if="cards.length === 0"
			:name="t('openregister', 'No Deck cards linked to this object')"
			:description="t('openregister', 'Create or link a Deck card to track work on this object.')">
			<template #icon>
				<TableLarge :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Linked cards list -->
		<ul v-else class="deck-tab__list">
			<li v-for="card in cards"
				:key="card.ref || card.deckRef || card.id"
				class="deck-tab__item">
				<div class="deck-tab__icon">
					<TableLarge :size="20" />
				</div>
				<div class="deck-tab__content">
					<div class="deck-tab__title">
						{{ card.title || card.summary || t('openregister', '(untitled card)') }}
					</div>
					<div class="deck-tab__meta">
						<span v-if="card.boardTitle" class="deck-tab__board">{{ card.boardTitle }}</span>
						<span v-if="card.stackTitle" class="deck-tab__separator">&middot;</span>
						<span v-if="card.stackTitle" class="deck-tab__stack">{{ card.stackTitle }}</span>
						<span v-if="card.dueDate" class="deck-tab__separator">&middot;</span>
						<span v-if="card.dueDate" class="deck-tab__date">{{ formatDate(card.dueDate) }}</span>
					</div>
				</div>
				<NcButton type="tertiary"
					:aria-label="t('openregister', 'Remove Deck card')"
					@click="unlinkCard(card)">
					<template #icon>
						<CloseCircleOutline :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import TableLarge from 'vue-material-design-icons/TableLarge.vue'
import TableLargePlus from 'vue-material-design-icons/TableLargePlus.vue'
import TableRemove from 'vue-material-design-icons/TableRemove.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import { useDeckRelationsStore } from '../../store/modules/object-relations/deck.js'

/**
 * DeckTab — display + manage Nextcloud Deck cards linked to an OpenRegister object.
 *
 * Backed by the per-object endpoint `GET /api/objects/{register}/{schema}/{id}/deck`
 * (DeckController). The Deck app is optional, so this tab gracefully renders an
 * empty state on HTTP 501 (`code: APP_NOT_AVAILABLE`). Card creation/linking is
 * delegated to the parent (which mounts a richer dialog with board/stack pickers),
 * because the per-object endpoint returns the linked card payload only.
 *
 * Spec: openspec/changes/nextcloud-entity-relations/specs/deck-relations/spec.md
 */
export default {
	name: 'DeckTab',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		AlertCircleOutline,
		TableLarge,
		TableLargePlus,
		TableRemove,
		CloseCircleOutline,
	},

	props: {
		register: {
			type: [String, Number],
			required: true,
		},
		schema: {
			type: [String, Number],
			required: true,
		},
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			error: false,
			errorMessage: '',
			store: useDeckRelationsStore(),
		}
	},

	computed: {
		key() {
			return `${this.register}:${this.schema}:${this.objectId}`
		},
		cards() {
			return this.store.byObject[this.key] || []
		},
		loading() {
			return !!this.store.loading[this.key]
		},
		deckUnavailable() {
			return this.store.deckUnavailable
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.fetchCards()
				}
			},
		},
	},

	methods: {
		t,

		async fetchCards() {
			this.error = false
			this.errorMessage = ''
			try {
				await this.store.fetch(this.register, this.schema, this.objectId)
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message || ''
			}
		},

		async unlinkCard(card) {
			const ref = card.ref || card.deckRef || card.id
			try {
				await this.store.unlink(this.register, this.schema, this.objectId, ref)
				this.$emit('deck-changed', this.cards.length)
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message || ''
			}
		},

		openCreateDialog() {
			this.$emit('add-deck-card')
		},

		formatDate(value) {
			if (!value) {
				return ''
			}

			try {
				const d = new Date(value)
				if (Number.isNaN(d.getTime())) {
					return value
				}

				return d.toLocaleString()
			} catch (e) {
				return value
			}
		},
	},
}
</script>

<style scoped>
.deck-tab__toolbar {
	display: flex;
	gap: 8px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.deck-tab__loading {
	display: flex;
	justify-content: center;
	padding: 2em 0;
}

.deck-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.deck-tab__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.deck-tab__item:last-child {
	border-bottom: none;
}

.deck-tab__icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.deck-tab__content {
	flex-grow: 1;
	min-width: 0;
}

.deck-tab__title {
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.deck-tab__meta {
	display: flex;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.deck-tab__board {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
</style>
