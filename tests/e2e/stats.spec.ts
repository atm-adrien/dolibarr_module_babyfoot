import { test, expect } from '@playwright/test';
import { submitAndWait } from './dolibarr';
import { apiCall, fixtures, loginToFixtureEntity, openBabyfootMenu } from './babyfoot';

test.describe('period filter', () => {
  test.beforeEach(async ({ page }) => {
    await loginToFixtureEntity(page);
  });

  test('choosing a period filters the statistics', async ({ page, request }) => {
    // A game played now, so the 30 day period is never empty
    const { p1, p2 } = fixtures.players;
    const created = await apiCall(request, 'POST', 'games', {
      mode: '1v1', score_team1: 10, score_team2: 2,
      players: [{ fk_user: p1.id, team: 1 }, { fk_user: p2.id, team: 2 }],
    });
    expect(created.status, created.body).toBe(200);

    await openBabyfootMenu(page, 'stats.php');
    await expect(page.locator('.babyfoot-period-selector a.babyfoot-winner')).toHaveAttribute('href', /period=all/);

    await submitAndWait(page, '.babyfoot-period-selector a[href*="period=30"]');
    await expect(page).toHaveURL(/period=30/);
    await expect(page.locator('.babyfoot-period-selector a.babyfoot-winner')).toHaveAttribute('href', /period=30/);
    await expect(page.locator('table.tableforfield')).toBeVisible();
  });

  test('the ranking marks the chosen period', async ({ page }) => {
    await openBabyfootMenu(page, 'ranking.php');
    await submitAndWait(page, '.babyfoot-period-selector a[href*="period=90"]');
    await expect(page.locator('.babyfoot-period-selector a.babyfoot-winner')).toHaveAttribute('href', /period=90/);
  });
});
