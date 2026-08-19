import { test, expect } from '@playwright/test'

// @e2e tag::alpha
test('alpha — submitting a stores the record', async ({ page }) => {
	await page.goto('/index.php/apps/scopefixture/')
	await page.getByRole('button', { name: 'Submit A' }).click()
	await expect(page.getByText('Record stored')).toBeVisible()
})
