<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="deck-tab">
		<!-- Toolbar -->
		<div v-if="!loading && !deckUnavailable" class="deck-tab__toolbar">
			<NcButton variant="primary" @click="openCreateDialog">
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
		<TabErrorState
			v-else-if="error"
			:name="t('openregister', 'Failed to load Deck cards')"
			:description="errorMessage" />

		<!-- Deck app missing (HTTP 501 graceful degradation) -->
		<NcEmptyContent
			v-else-if="deckUnavailable"
			:name="t('openregister', 'Deck integration is not available')"
			:description="
				t(
					'openregister',
					'The Nextcloud Deck app is not installed or enabled on this server.',
				)
			">
			<template #icon>
				<TableRemove :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="items.length === 0"
			:name="t('openregister', 'No Deck cards linked to this object')"
			:description="
				t(
					'openregister',
					'Create or link a Deck card to track work on this object.',
				)
			">
			<template #icon>
				<TableLarge :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Linked cards list -->
		<ul v-else class="deck-tab__list">
			<li
				v-for="card in items"
				:key="card.ref || card.deckRef || card.id"
				class="deck-tab__item">
				<div class="deck-tab__icon">
					<TableLarge :size="20" />
				</div>
				<div class="deck-tab__content">
					<div class="deck-tab__title">
						{{
							card.title
							|| card.summary
							|| t('openregister', '(untitled card)')
						}}
					</div>
					<div class="deck-tab__meta">
						<span v-if="card.boardTitle" class="deck-tab__board">{{
							card.boardTitle
						}}</span>
						<span v-if="card.stackTitle" class="deck-tab__separator"
							>&middot;</span
						>
						<span v-if="card.stackTitle" class="deck-tab__stack">{{
							card.stackTitle
						}}</span>
						<span v-if="card.dueDate" class="deck-tab__separator"
							>&middot;</span
						>
						<span v-if="card.dueDate" class="deck-tab__date">{{
							formatDate(card.dueDate)
						}}</span>
					</div>
				</div>
				<NcButton
					variant="tertiary"
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
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import TableLarge from 'vue-material-design-icons/TableLarge.vue'
import TableLargePlus from 'vue-material-design-icons/TableLargePlus.vue'
import TableRemove from 'vue-material-design-icons/TableRemove.vue'
import TabErrorState from '../shared/TabErrorState.vue'
import { useDeckRelationsStore } from '../../store/modules/object-relations/deck.js'
import { storeRelationTab } from './relationTab.js'

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
		TabErrorState,
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		TableLarge,
		TableLargePlus,
		TableRemove,
		CloseCircleOutline,
	},

	mixins: [storeRelationTab(useDeckRelationsStore)],

	computed: {
		/**
		 * @spec exclude computed read of integration-availability flag from store, UI plumbing
		 */
		deckUnavailable() {
			return this.store.deckUnavailable
		},
	},

	methods: {
		/**
		 * @param {object} card
		 * @spec exclude store passthrough unlinking card + change emit; deck-relations contract owned by integration-deck capability
		 */
		unlinkCard(card) {
			return this.unlink(card.ref || card.deckRef || card.id, 'deck-changed')
		},

		/**
		 * @spec exclude emit UI handler opening add-card dialog, UI plumbing
		 */
		openCreateDialog() {
			this.$emit('add-deck-card')
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
