import { test, expect } from '@playwright/test'

// @e2e tag::beta
test('beta — submitting c shows the result', async ({ page }) => {
	await page.goto('/index.php/apps/scopefixture/')
	await page.getByRole('button', { name: 'Submit C' }).click()
	await expect(page.getByText('Result shown')).toBeVisible()
})
