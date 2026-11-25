<template>
	<ConfirmDialogFrame
		v-if="show"
		:name="t('openregister', 'Restore with related records')"
		closable
		class="or-restore-related-dialog"
		:loading="loading || restoring"
		:cancelDisabled="restoring"
		:closeLabel="t('openregister', 'Cancel')"
		:confirmLabel="t('openregister', 'Restore')"
		showConfirm
		:confirmDisabled="!canRestore"
		:error="error"
		@cancel="$emit('close')"
		@confirm="restore">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else-if="plan">
			<p v-if="!plan.cascadeKnown">
				{{
					t(
						'openregister',
						'This delete did not record its related records; only this object will be restored.',
					)
				}}
			</p>
			<p v-else-if="groups.length === 0 && forbiddenCount === 0">
				{{
					t(
						'openregister',
						'Nothing else was deleted or unlinked with this object.',
					)
				}}
			</p>
			<NcNoteCard v-if="forbiddenCount > 0" type="warning">
				<p>
					{{
						t(
							'openregister',
							'Related records you may not restore: {count}',
							{ count: forbiddenCount },
						)
					}}
				</p>
			</NcNoteCard>
			<section
				v-for="group in groups"
				:key="group.key"
				class="or-restore-related-dialog__group">
				<h3>{{ group.label }} ({{ group.items.length }})</h3>
				<ul>
					<li v-for="(item, index) in group.items" :key="index">
						{{ itemLabel(item) }}
					</li>
				</ul>
			</section>
		</template>
		<template #confirmIcon>
			<Restore :size="20" />
		</template>
	</ConfirmDialogFrame>
</template>

<script>
/**
 * The preview and confirmation of restoring a deleted object together with
 * what its delete cascaded to. Lives in its own file (ADR-004 modal
 * isolation); the parent opens it with `show` and the object's id, and hears
 * `restored` with the server's counts or `close`.
 *
 * The plan comes from `GET /api/deleted/{id}/restore-preview`; nothing is
 * written until the user confirms. Objects the user may not restore are
 * only counted, and a plan with any of them cannot be confirmed: the server
 * refuses the whole act, so the button says so before the click does.
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import ConfirmDialogFrame from '../../modals/shared/ConfirmDialogFrame.vue'
import { deletedStore } from '../../store/store.js'

/**
 * The plan's classes in the order the dialog shows them, with their headings.
 *
 * @return {Array<{key: string, label: string}>} The classes shown as groups.
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */
export function restoreGroupHeadings() {
	return [
		{ key: 'restore', label: t('openregister', 'Will be restored') },
		{ key: 'relink', label: t('openregister', 'References put back') },
		{ key: 'changed', label: t('openregister', 'Changed since the delete') },
		{
			key: 'gone',
			label: t('openregister', 'Destroyed or past the recovery window'),
		},
		{ key: 'already', label: t('openregister', 'Already restored') },
	]
}

/**
 * The non-empty groups of a restore plan, in display order.
 *
 * @param {object|null} plan The restore preview answer
 * @return {Array<{key: string, label: string, items: Array<object>}>} The groups with items
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */
export function restoreGroups(plan) {
	if (!plan) {
		return []
	}
	return restoreGroupHeadings()
		.map((heading) => ({
			...heading,
			items: Array.isArray(plan[heading.key]) ? plan[heading.key] : [],
		}))
		.filter((group) => group.items.length > 0)
}

/**
 * How one plan item reads: its schema, the reference property, and its id when the user may see it.
 *
 * @param {object} item A plan item
 * @return {string} The label
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */
export function restoreItemLabel(item) {
	const parts = [item.schema || '?']
	if (item.property) {
		parts.push(item.property)
	}
	const label = parts.join(' · ')
	return item.uuid ? `${label} — ${item.uuid}` : label
}

export default {
	name: 'RestoreWithRelatedDialog',
	components: { ConfirmDialogFrame, NcLoadingIcon, NcNoteCard, Restore },

	props: {
		show: {
			type: Boolean,
			required: true,
		},

		objectId: {
			type: [String, Number],
			default: null,
		},
	},

	emits: ['close', 'restored'],

	data() {
		return {
			plan: null,
			loading: false,
			restoring: false,
			error: false,
		}
	},

	computed: {
		groups() {
			return restoreGroups(this.plan)
		},

		forbiddenCount() {
			return this.plan?.forbidden?.count ?? 0
		},

		canRestore() {
			return (
				this.plan !== null
				&& !this.loading
				&& !this.restoring
				&& this.forbiddenCount === 0
			)
		},
	},

	watch: {
		show: {
			immediate: true,
			handler(open) {
				if (open) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,

		itemLabel: restoreItemLabel,

		/**
		 * Read the plan for the object.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
		 */
		async load() {
			this.plan = null
			this.error = false
			if (this.objectId === null || this.objectId === undefined) {
				return
			}
			this.loading = true
			try {
				this.plan = await deletedStore.previewRestore(this.objectId)
			} catch (error) {
				this.error = error.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Restore the object with its related records, then hand the counts to the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
		 */
		async restore() {
			if (!this.canRestore) {
				return
			}
			this.restoring = true
			this.error = false
			try {
				const result = await deletedStore.restoreDeleted(this.objectId)
				this.$emit('restored', result)
			} catch (error) {
				this.error = error.message
			} finally {
				this.restoring = false
			}
		},
	},
}
</script>

<style scoped>
.or-restore-related-dialog__group h3 {
	margin: 12px 0 4px;
	font-size: var(--default-font-size);
	font-weight: bold;
}

.or-restore-related-dialog__group ul {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
	color: var(--color-main-text);
}
</style>
