import { expect, test } from '@playwright/test';

test('shows a visible error after payment status requests fail', async ({ page }) => {
    let requestCount = 0;

    await page.route('**/mock/payment-status', async (route) => {
        requestCount++;
        await route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Payment status temporarily unavailable.' }),
        });
    });

    await page.goto('/tests/Browser/fixtures/payment-polling.html', {
        waitUntil: 'domcontentloaded',
        timeout: 20000,
    });
    await page.getByRole('button', { name: 'Verify payment' }).click();
    await expect(page.getByRole('status')).toHaveText('Payment status temporarily unavailable.');
    expect(requestCount).toBe(3);
});
