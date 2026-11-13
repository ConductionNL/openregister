<template>
	<NcNoteCard v-if="schema && schema.draft" type="info" class="schema-draft-bar">
		<p>
			{{
				t(
					'openregister',
					'This schema has an unpublished draft. Records are checked against the published version until you publish it.',
				)
			}}
		</p>
		<ul v-if="breakingChanges.length" class="schema-draft-bar__changes">
			<li v-for="(change, i) in breakingChanges" :key="`draft-bc-${i}`">
				{{ describeChange(change) }}
			</li>
		</ul>
		<p v-if="errorMessage" class="schema-draft-bar__error">
			{{ errorMessage }}
		</p>
		<div class="schema-draft-bar__actions">
			<NcButton
				variant="secondary"
				:disabled="busy"
				@click="$emit('editDraft')">
				{{ t('openregister', 'Edit draft') }}
			</NcButton>
			<NcButton
				v-if="breakingChanges.length"
				variant="warning"
				:disabled="busy"
				@click="publish(true)">
				{{ t('openregister', 'Publish anyway') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				:disabled="busy"
				@click="publish(false)">
				{{ t('openregister', 'Publish draft') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="discard">
				{{ t('openregister', 'Discard draft') }}
			</NcButton>
		</div>
	</NcNoteCard>
</template>

<script>
import { describeSchemaChange } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { schemaStore } from '../../store/store.js'

/**
 * What to show for a refused publish: the breaking changes to acknowledge,
 * when the server names them and they were not acknowledged yet, or the
 * error.
 *
 * @param {Error} error What went wrong
 * @param {boolean} acknowledgeBreaking Whether breaking changes were acknowledged
 * @return {{breakingChanges: Array}|{errorMessage: string}} The state to show
 */
function publishRefusal(error, acknowledgeBreaking) {
	const body = error?.response?.data || {}
	if (
		error?.response?.status === 409
		&& Array.isArray(body.changes)
		&& !acknowledgeBreaking
	) {
		return { breakingChanges: body.changes }
	}
	return { errorMessage: body.error || error.message }
}

export default {
	name: 'SchemaDraftBar',
	components: { NcButton, NcNoteCard },

	props: {
		/** The schema whose draft this bar shows. */
		schema: {
			type: Object,
			default: null,
		},
	},

	emits: ['editDraft'],

	data() {
		return {
			busy: false,
			// Filled when the server refused the publish as a breaking change.
			breakingChanges: [],
			errorMessage: '',
		}
	},

	methods: {
		t,

		/**
		 * Publish the draft. A breaking change comes back as a question: the bar
		 * lists the changes and offers to publish anyway, never on its own.
		 *
		 * @param {boolean} acknowledgeBreaking - Accept the breaking change
		 * @return {Promise<void>}
		 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
		 */
		async publish(acknowledgeBreaking) {
			this.busy = true
			this.errorMessage = ''
			try {
				await schemaStore.publishSchemaDraft(this.schema.id, {
					acknowledgeBreaking,
				})
				this.breakingChanges = []
			} catch (error) {
				Object.assign(this, publishRefusal(error, acknowledgeBreaking))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Discard the draft; the published schema stays as it is.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
		 */
		async discard() {
			this.busy = true
			this.errorMessage = ''
			try {
				await schemaStore.discardSchemaDraft(this.schema.id)
				this.breakingChanges = []
			} catch (error) {
				this.errorMessage = error?.response?.data?.error || error.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} change - One change descriptor from the server
		 * @return {string} The description
		 * @spec exclude pure presentation helper shared with EditSchema
		 */
		describeChange(change) {
			return describeSchemaChange(change, t)
		},
	},
}
</script>

<style scoped>
.schema-draft-bar__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.schema-draft-bar__error {
	color: var(--color-text-error, var(--color-error));
}
</style>
