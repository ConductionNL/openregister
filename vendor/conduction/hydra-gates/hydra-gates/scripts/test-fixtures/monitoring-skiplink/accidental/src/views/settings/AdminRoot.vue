<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<!--
	  #227: an ADMIN SETTINGS SURFACE. This component is rendered into core's
	  Settings page, inside the section core already built for it. It is a
	  stack of settings sections, not an app shell: it has no Nextcloud
	  content wrapper, no shared app root, and no jump-to-content affordance,
	  and it must NOT be reported for any of those, because it owns no
	  document.

	  NOTHING in this comment may name the markup the gate greps for. The
	  first draft of this file spelled out the wrapper element it does not
	  have, and the gate matched the COMMENT and accepted the file — so the
	  "#227 is fixed" assertion passed identically with the fix reverted. A
	  fixture that quotes the pattern it is meant to defeat defeats itself
	  instead (the gate-56 lesson, relearned here the hard way).

	  Deliberately placed in `accidental/`, next to an App.vue that DOES still
	  fail: the two files agree on every signal gate-38 used to look at and
	  differ only in being a settings surface. The suite asserts App.vue and
	  standalone.php are still named in the SAME run, so "not reported" here
	  can never be an empty scope reading as a pass.
	-->
	<CnAdminSettingsShell app-id="fixture" app-name="Fixture">
		<CnSettingsSection name="Things">
			<p>Configure things.</p>
		</CnSettingsSection>
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell, CnSettingsSection } from '@conduction/nextcloud-vue'

export default {
	name: 'AdminRoot',
	components: { CnAdminSettingsShell, CnSettingsSection },
}
</script>
