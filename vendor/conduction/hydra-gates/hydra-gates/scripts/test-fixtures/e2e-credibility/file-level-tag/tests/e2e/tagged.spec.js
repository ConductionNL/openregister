/**
 * A FILE-LEVEL tag claiming both scenarios. Nothing in the body exercises
 * either of them (`.github#343`: the tag is credited without checking the test
 * body), and the file's only test is UNCONDITIONALLY skipped — `test.skip(true)`
 * is a condition that can never be false — so even the assertion it does carry
 * never runs. The one assertion present is `#app-root` visible, which is the
 * decidesk shape: a test named for a tab that only proves the app mounted.
 *
 * @e2e tag::alpha
 * @e2e tag::beta
 */
import { test, expect } from '@playwright/test'

test.skip(true, 'permanently skipped — the condition can never be false')

test('mounts', async ({ page }) => {
	await expect(page.locator('#app-root')).toBeVisible()
})
