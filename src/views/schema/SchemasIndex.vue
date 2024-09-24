<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<template #list>
			<SchemasList />
		</template>
		<template #default>
			<NcEmptyContent v-if="!schemaStore.schemaItem || navigationStore.selected != 'schemas'"
				class="detailContainer"
				name="Geen schema"
				description="Nog geen schema geselecteerd">
				<template #icon>
					<FileTreeOutline />
				</template>
				<template #action>
					<NcButton type="primary" @click="schemaStore.setSchemaItem({}); navigationStore.setModal('editSchema')">
						Schema toevoegen
					</NcButton>
				</template>
			</NcEmptyContent>
			<SchemaDetails v-if="schemaStore.schemaItem && navigationStore.selected === 'schemas'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcButton } from '@nextcloud/vue'
import SchemasList from './SchemasList.vue'
import SchemaDetails from './SchemaDetails.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'

export default {
	name: 'SchemasIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		SchemasList,
		SchemaDetails,
		FileTreeOutline,
	},
}
</script>
