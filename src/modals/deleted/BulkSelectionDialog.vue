<template>
	<NcDialog :name="name" size="normal" noClose>
		<!-- Object Selection Review -->
		<div v-if="success === null" :class="stepClass">
			<h3 class="step-title">
				{{ heading }}
			</h3>

			<NcNoteCard :type="noteType">
				{{ note }}
			</NcNoteCard>

			<div class="selected-objects-container">
				<h4>
					{{
						t('openregister', 'Selected Objects ({count})', {
							count: objects.length,
						})
					}}
				</h4>

				<div v-if="objects.length" class="selected-objects-list">
					<div
						v-for="obj in objects"
						:key="obj.id"
						class="selected-object-item">
						<div class="object-info">
							<strong>{{ objectTitle(obj) }}</strong>
							<p class="object-id">
								{{ t('openregister', 'ID: {id}', { id: obj.id }) }}
							</p>
						</div>
						<NcButton
							variant="tertiary"
							:aria-label="
								t('openregister', 'Remove {title}', {
									title: objectTitle(obj),
								})
							"
							@click="$emit('remove', obj.id)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
				</div>

				<NcEmptyContent
					v-else
					:name="t('openregister', 'No objects selected')">
					<template #description>
						{{ emptyText }}
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>{{ successMessage }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="$emit('close')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success === null
						? t('openregister', 'Cancel')
						: t('openregister', 'Close')
				}}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading || objects.length === 0"
				:variant="submitVariant"
				@click="$emit('submit')">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<slot v-if="!loading" name="submitIcon" />
				</template>
				{{ submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Close from 'vue-material-design-icons/Close.vue'
import { objectTitle } from './bulkSelection.js'

/**
 * The review step of a bulk action on deleted objects: the selected objects,
 * each removable, the outcome, and the cancel and submit buttons. The
 * dialog that uses it owns the selection and the action.
 *
 * @spec exclude Presentational dialog body shared by the bulk purge and restore dialogs.
 */
export default {
	name: 'BulkSelectionDialog',
	components: {
		NcDialog,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		Cancel,
		Close,
	},

	props: {
		name: { type: String, required: true },
		heading: { type: String, required: true },
		note: { type: String, required: true },
		noteType: { type: String, required: true },
		emptyText: { type: String, required: true },
		submitLabel: { type: String, required: true },
		submitVariant: { type: String, required: true },
		stepClass: { type: String, required: true },
		objects: { type: Array, required: true },
		success: { type: Boolean, default: null },
		successMessage: { type: String, default: '' },
		error: { type: [Boolean, String], default: false },
		loading: { type: Boolean, default: false },
	},

	emits: ['close', 'remove', 'submit'],

	methods: {
		t,
		objectTitle,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.delete-step,
.restore-step {
	padding: 0;
}

@include step-title;

.selected-objects-container {
	margin: 20px 0;
}

@include selected-objects-list-rules;

.selected-object-item:last-child {
	border-bottom: none;
}

@include object-info-strong-rules;
</style>
