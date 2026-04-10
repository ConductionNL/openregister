<template>
	<section class="or-mail-linked-objects" aria-labelledby="or-mail-linked-title">
		<h3 id="or-mail-linked-title" class="or-mail-section-title">
			{{ t('openregister', 'Linked Objects') }}
		</h3>
		<div v-if="loading" class="or-mail-loading">
			<span class="icon-loading-small" />
			{{ t('openregister', 'Loading...') }}
		</div>
		<div v-else-if="objects.length === 0" class="or-mail-empty">
			{{ t('openregister', 'No objects linked to this email') }}
		</div>
		<div v-else class="or-mail-object-list">
			<ObjectCard
				v-for="obj in objects"
				:key="obj.linkId || obj.objectUuid"
				:object="obj"
				:show-unlink="true"
				@unlink="$emit('unlink', $event)" />
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ObjectCard from './ObjectCard.vue'

export default {
	name: 'LinkedObjectsList',
	components: { ObjectCard },
	props: {
		objects: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},
	methods: { t },
}
</script>
