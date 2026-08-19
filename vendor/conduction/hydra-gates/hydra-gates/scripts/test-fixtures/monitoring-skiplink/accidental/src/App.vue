<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<!--
	  THE CONTROL for the shared-shell exemption. A bespoke shell: the root is
	  a plain <div>, not the shared app root and not a Nextcloud content
	  wrapper, and there is no jump-to-content affordance anywhere in it.

	  The words this gate looks for appear nowhere in this file, including
	  here — a fixture that quotes the pattern it is meant to defeat defeats
	  itself instead (the gate-56 lesson).
	-->
	<div class="fixture-app">
		<nav class="fixture-app__nav">
			<a href="/apps/fixture/things">Things</a>
		</nav>
		<main class="fixture-app__main">
			<router-view />
		</main>
	</div>
</template>

<script>
export default {
	name: 'App',
}
</script>
