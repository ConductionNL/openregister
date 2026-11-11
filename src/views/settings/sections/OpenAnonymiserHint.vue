<!--
  Why the OpenAnonymiser backend cannot be used, with a link to install it.
  Shown under the backend status list and under the internal-source choice.

  @spec exclude Presentational hint of the file settings.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<p class="setting-description backend-hint" role="status">
		{{ hint }}
		<a
			:href="installLink"
			target="_blank"
			rel="noopener noreferrer"
			class="backend-install-link">
			{{ t('openregister', 'Install OpenAnonymiser') }}
		</a>
	</p>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * Why the OpenAnonymiser backend cannot be used, with a link to install it.
 *
 * @spec exclude Presentational hint of the file settings.
 */
export default {
	name: 'OpenAnonymiserHint',

	props: {
		/** The probe's reason, worded for the admin. */
		hint: { type: String, required: true },
		/** Where the admin installs the ExApp. */
		installLink: { type: String, required: true },
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
/* The paragraph classes (setting-description, backend-hint) are styled by the
   settings section, whose scope reaches this root element. */
.backend-install-link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
