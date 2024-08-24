<script setup>
import { navigationStore, searchStore, publicationStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		name="Snelle start"
		subname="Schakel snel naar waar u nodig bent">
		<NcAppSidebarTab id="search-tab" name="Zoeken" :order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>
			Zoek snel in het voor uw beschikbare federatieve netwerk
			<NcTextField class="searchField"
				:value.sync="searchStore.search"
				label="Zoeken" />
			<NcNoteCard v-if="searchStore.searchError" type="error">
				<p>{{ searchStore.searchError }}</p>
			</NcNoteCard>
		</NcAppSidebarTab>
		<NcAppSidebarTab id="settings-tab" name="Publicaties" :order="2">
			<template #icon>
				<ListBoxOutline :size="20" />
			</template>
			Welke publicaties vereisen uw aandacht?
			<NcListItem v-for="(publication, i) in publicationStore.conceptPublications.results"
				:key="`${publication}${i}`"
				:name="publication.name ?? publication.title"
				:bold="false"
				:force-display-actions="true"
				:active="publicationStore.publicationItem.id === publication.id"
				:details="publication?.status">
				<template #icon>
					<ListBoxOutline :class="publicationStore.publicationItem.id === publication.id && 'selectedZaakIcon'"
						disable-menu
						:size="44" />
				</template>
				<template #subname>
					{{ publication?.description }}
				</template>
				<template #actions>
					<NcActionButton @click="publicationStore.setPublicationItem(publication); navigationStore.setSelected('publication');">
						<template #icon>
							<ListBoxOutline :size="20" />
						</template>
						Bekijken
					</NcActionButton>
					<NcActionButton @click="publicationStore.setPublicationItem(publication); navigationStore.setModal('editPublication')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Bewerken
					</NcActionButton>
					<NcActionButton @click="publicationStore.setPublicationItem(publication); navigationStore.setDialog('publishPublication')">
						<template #icon>
							<Publish :size="20" />
						</template>
						Publiceren
					</NcActionButton>
					<NcActionButton @click="publicationStore.setPublicationItem(publication); navigationStore.setDialog('deletePublication')">
						<template #icon>
							<Delete :size="20" />
						</template>
						Verwijderen
					</NcActionButton>
				</template>
			</NcListItem>
			<NcNoteCard v-if="!publicationStore.conceptPublications?.results?.length > 0" type="success">
				<p>Er zijn op dit moment geen publicaties die uw aandacht vereisen</p>
			</NcNoteCard>
		</NcAppSidebarTab>
		<NcAppSidebarTab id="share-tab" name="Bijlagen" :order="3">
			<template #icon>
				<FileOutline :size="20" />
			</template>
			Welke bijlagen vereisen uw aandacht?
			<NcListItem v-for="(attachment, i) in publicationStore.conceptAttachments.results"
				:key="`${attachment}${i}`"
				:name="attachment.name ?? attachment.title"
				:bold="false"
				:force-display-actions="true"
				:active="publicationStore.attachmentItem.id === attachment.id"
				:details="attachment?.status">
				<template #icon>
					<ListBoxOutline :class="publicationStore.publicationItem.id === attachment.id && 'selectedZaakIcon'"
						disable-menu
						:size="44" />
				</template>
				<template #subname>
					{{ attachment?.description }}
				</template>
				<template #actions>
					<NcActionButton @click="publicationStore.setAttachmentItem(attachment); navigationStore.setModal('editAttachment')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Bewerken
					</NcActionButton>
					<NcActionButton @click="publicationStore.setAttachmentItem(attachment); navigationStore.setDialog('publishAttachment')">
						<template #icon>
							<Publish :size="20" />
						</template>
						Publiceren
					</NcActionButton>
					<NcActionButton @click="publicationStore.setAttachmentItem(attachment); navigationStore.setDialog('deleteAttachment')">
						<template #icon>
							<Delete :size="20" />
						</template>
						Verwijderen
					</NcActionButton>
				</template>
			</NcListItem>
			<NcNoteCard v-if="!publicationStore.conceptAttachments?.results?.length > 0" type="success">
				<p>Er zijn op dit moment geen bijlagen die uw aandacht vereisen</p>
			</NcNoteCard>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>
<script>

import { NcAppSidebar, NcAppSidebarTab, NcTextField, NcNoteCard, NcListItem, NcActionButton } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import ListBoxOutline from 'vue-material-design-icons/ListBoxOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'DashboardSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcTextField,
		NcNoteCard,
		NcListItem,
		NcActionButton,
		// Icons
		Magnify,
		ListBoxOutline,
		FileOutline,
		Pencil,
		Publish,
		Delete,
	},
	data() {
		return {

			publications: false,
			attachments: false,
		}
	},
	mounted() {
		publicationStore.getConceptPublications()
		publicationStore.getConceptAttachments()
	},
}
</script>
