<template>
	<NcAppContent>
		<h2 class="pageHeader">
			Dashboard
		</h2>

		Welkom!
	</NcAppContent>
</template>

<script>

import { NcAppContent } from '@nextcloud/vue'

export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
	}
}
</script>

<style>
.dashboard-content {
    margin-inline: auto;
    max-width: 1000px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}
.dashboard-content > * {
    margin-block-end: 4rem;
}

/* most searched terms */
.dashboard-content > .most-searched-terms {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}
@media screen and (min-width: 880px) {
    .dashboard-content > .most-searched-terms {
        grid-template-columns: 1fr 1fr;
    }
}
@media screen and (min-width: 1024px) {
    .dashboard-content > .most-searched-terms {
        grid-template-columns: 1fr;
    }
}
@media screen and (min-width: 1220px) {
    .dashboard-content > .most-searched-terms {
        grid-template-columns: 1fr 1fr;
    }
}
@media screen and (min-width: 1590px) {
    .dashboard-content > .most-searched-terms {
        grid-template-columns: 1fr 1fr 1fr;
    }
}
.dashboard-content > .most-searched-terms > div {
    padding: 1rem;
    height: 150px;
    width: 250px;
    border-radius: 8px;
}
/* default theme */
@media (prefers-color-scheme: light) {
    .dashboard-content > .most-searched-terms > div {
        background-color: rgba(0, 0, 0, 0.07);
    }
}
@media (prefers-color-scheme: dark) {
    .dashboard-content > .most-searched-terms > div {
        background-color: rgba(255, 255, 255, 0.1);
    }
}
/* do theme checks, light mode | dark mode */
body[data-theme-light] .dashboard-content > .most-searched-terms > div {
    background-color: rgba(0, 0, 0, 0.07);
}
body[data-theme-dark] .dashboard-content > .most-searched-terms > div {
    background-color: rgba(255, 255, 255, 0.1);
}
.dashboard-content > .most-searched-terms > div > h5 {
    margin: 0;
    font-weight: normal;
}
.dashboard-content > .most-searched-terms > div > .content {
    display: flex;
    justify-content: center;
    align-items: center;
    height: calc(100% - 40px);

    font-size: 3.5rem;
}

/* graphs */
.dashboard-content > .graphs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
}
@media screen and (max-width: 1800px) {
    .dashboard-content > .graphs {
        display: grid;
        grid-template-columns: 1fr;
        gap: 2rem;
    }
}
.dashboard-content > .graphs .full-width {
    grid-column-start: 1;
    grid-column-end: 3;
}
.dashboard-content > .graphs .content {
    display: flex;
    gap: 4px;
}
.dashboard-content > .graphs .dashboard-small-list {
    display: flex;
    flex-direction: column;
}
.dashboard-content > .graphs .dashboard-small-list > li {
    padding: 1rem;
}
.dashboard-content > .graphs .dashboard-small-list > li:not(:last-child) {
    border-bottom: 1px solid grey;
}
</style>
