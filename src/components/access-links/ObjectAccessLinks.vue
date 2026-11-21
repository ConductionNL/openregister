<template>
	<SubjectAccessLinks subjectType="object" :subjectId="objectId" />
</template>

<script>
import SubjectAccessLinks from './SubjectAccessLinks.vue'

/**
 * The "Access links" tab of the object page: the links over one object.
 * A thin wrapper so the object tab keeps its own name and prop.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
export default {
	name: 'ObjectAccessLinks',
	components: {
		SubjectAccessLinks,
	},

	props: {
		/** The uuid of the object the links open. */
		objectId: {
			type: String,
			required: true,
		},
	},
}
</script>
