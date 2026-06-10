import { test, expect } from '@playwright/test';

test('Creation and deletion of appliance', async ({ page }) => {
    const applianceName = `Appliance ${Date.now()}`;

    await page.goto('/appliances/create');
    await page.addStyleTag({ content: '*, *::before, *::after { transition-duration: 0s !important; animation-duration: 0s !important; }' });

    //## Add Appliance
    await page.getByRole('textbox', { name: 'Appliance Name' }).fill(applianceName);
    await page.getByRole('textbox', { name: 'Model' }).fill("Test model");
    await page.getByPlaceholder('Search or enter a type…').fill('Dishwasher');
    await Promise.all([
        page.waitForResponse(r => r.url().includes('/livewire/update') && r.status() === 200),
        page.getByRole('listitem').filter({ hasText: 'Dishwasher' }).click(),
    ]);

    await page.getByRole('button', { name: 'Next' }).click();


    //## Maintenance Tasks
    await expect(page.getByRole('heading', { name: "Maintenance Tasks" })).toBeVisible({ timeout: 10000 });
    // indicator of AI suggested schedule loaded:
    await expect(page.getByRole('button', { name: 'Remove' }).first()).toBeVisible({ timeout: 30000 });
    await page.getByRole('button', { name: 'Next' }).click();


    //## When did you last service these?
    await expect(page.getByRole('heading', { name: "When did you last service these?" })).toBeVisible({ timeout: 10000 });
    await page.getByRole('button', { name: 'Next' }).click();


    //## Confirm Your Plan
    await expect(page.getByRole('heading', { name: "Confirm Your Plan" })).toBeVisible({ timeout: 10000 });
    await expect(page.getByRole('heading', { name: "Maintenance Tasks" })).toBeVisible({ timeout: 10000 });
    await page.getByRole('button', { name: 'Confirm Plan' }).click();


    //## Appliance Detail Page
    // indicator of saved appliance:
    await expect(page.getByRole('button', { name: 'Add task' }).first()).toBeVisible({ timeout: 30000 });
    await page.waitForURL(/\/appliances\/\d+$/);
    const editUrl = page.url() + '/edit';


    // Assert appliance appears on the index
    await page.goto('/appliances');
    await expect(page.getByRole('heading', { name: applianceName })).toBeVisible();


    // Cleanup — navigate directly to edit page via captured URL
    await page.goto(editUrl);
    await page.getByRole('button', { name: 'Delete Appliance' }).click();
    await page.locator('form').filter({ has: page.getByRole('button', { name: 'Cancel' }) })
        .getByRole('button', { name: 'Delete Appliance' }).click();
});
