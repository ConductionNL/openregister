<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2

  The one stable run address: /apps/openregister/flow-runs/{uuid}.

  A run has no screen of its own and should not grow one: everything worth
  seeing about a run — the graph with its replay, the steps, the objects it
  touched, its tasks and its log — is already the flow editor's sidebar in
  run view. What was missing is an ADDRESS. Without one, "open this run in
  a new tab" cannot be a link, and anything that is not a link cannot be
  middle-clicked, bookmarked, or pasted into a ticket.

  So this page is a resolver, not a view. It reads the run to learn which
  flow it belongs to, then replaces itself with that flow's editor holding
  the run inspected. `replace`, never `push`: a redirect the visitor never
  chose must not sit in their history, or Back returns here and forwards
  them again.

  It renders a real failure state rather than bouncing to the dashboard. A
  run uuid that does not resolve is usually a stale link from a ticket or a
  notification, and silently landing somebody on the dashboard is the
  behaviour that makes a dead link impossible to diagnose.
-->
<template>
	<NcAppContent>
		<div class="viewContainer">
			<template v-if="loading">
				<NcLoadingIcon :size="32" />
			</template>

			<NcEmptyContent
				v-else
				:name="t('openregister', 'No such run')"
				:description="
					t(
						'openregister',
						'The run does not exist, or it is not yours to see. Deleting a flow deletes its runs.',
					)
				">
				<template #icon>
					<PlayCircleOutline :size="64" />
				</template>
			</NcEmptyContent>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'

export default {
	name: 'FlowRunDetail',

	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		PlayCircleOutline,
	},

	props: {
		/**
		 * The run uuid, from the route.
		 */
		uuid: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
		}
	},

	watch: {
		// A router-link between two runs reuses this component rather than
		// remounting it, so resolving only in `created` would leave the
		// second link on the first run's flow.
		uuid: {
			handler: 'resolve',
			immediate: true,
		},
	},

	methods: {
		/**
		 * Read the run, then hand over to its flow's editor.
		 *
		 * @return {Promise<void>}
		 */
		async resolve() {
			this.loading = true

			try {
				const response = await axios.get(
					generateUrl(`/apps/openregister/api/flow-runs/${this.uuid}`),
				)

				// `flowId` on a run holds the flow's UUID, not its numeric id —
				// the flows API answers the same value under both `id` and
				// `uuid`, which is what makes this address resolvable at all.
				const flowId = response?.data?.flowId

				if (!flowId) {
					this.loading = false
					return
				}

				this.$router.replace({
					path: `/flows/${flowId}`,
					query: { run: this.uuid },
				})
			} catch {
				// 404 for a run that is gone, 401 without a session, and both
				// mean the same thing to the visitor holding the link.
				this.loading = false
			}
		},
	},
}
</script>
