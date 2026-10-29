import { test, expect, Page } from '@playwright/test';
import { php, submitAndWait } from './dolibarr';

type Player = { id: number; label: string };
type Fixtures = { login: string; password: string; players: Record<'p1' | 'p2' | 'p3' | 'p4', Player> };
type LastGame = {
  count: number;
  last: null | { id: number; mode: string; score_team1: number; score_team2: number; players: Record<string, number> };
};

const fixtures: Fixtures = JSON.parse(process.env.E2E_FIXTURES || '{}');
const { p1, p2, p3, p4 } = fixtures.players;

// Same entity as the fixtures: with Multicompany the login form picks it, not the account.
async function loginToFixtureEntity(page: Page): Promise<void> {
  await page.goto('index.php');
  const entity = page.locator('select#entity');
  if (await entity.count()) {
    await entity.selectOption(process.env.E2E_ENTITY || '1');
  }
  await page.fill('#username', fixtures.login);
  await page.fill('#password', fixtures.password);
  await submitAndWait(page, 'input[type=submit]');
  await expect(page.locator('#username')).toHaveCount(0);
}

function lastGame(): LastGame {
  return JSON.parse(php('lastgame.php'));
}

async function pickPlayer(page: Page, field: string, player: Player): Promise<void> {
  await page.locator(`#select2-${field}-container`).click();
  const search = page.locator('.select2-container--open .select2-search__field');
  if (await search.isVisible()) {
    await search.fill(player.label);
  }
  await page.locator('.select2-results__option', { hasText: player.label }).first().click();
  await expect(page.locator(`#${field}`)).toHaveValue(String(player.id));
}

async function openQuickEntry2v2(page: Page): Promise<void> {
  await page.goto('index.php');
  await submitAndWait(page, '#mainmenua_tools');
  await submitAndWait(page, '.vmenu a[href*="babyfoot/game_quickadd.php"] >> nth=0');
  await submitAndWait(page, 'a.babyfoot-mode-btn[href*="mode=2v2"]');
}

async function pickLineUp(page: Page, blue: [Player, Player], red: [Player, Player]): Promise<void> {
  await pickPlayer(page, 'player_1_1', blue[0]);
  await pickPlayer(page, 'player_1_2', blue[1]);
  await pickPlayer(page, 'player_2_1', red[0]);
  await pickPlayer(page, 'player_2_2', red[1]);
}

test.describe('quick-entry', () => {
  test.beforeEach(async ({ page }) => {
    await loginToFixtureEntity(page);
  });

  test('the module lives under Tools, not in the top menu', async ({ page }) => {
    await page.goto('index.php');
    await expect(page.locator('#mainmenutd_babyfoot')).toHaveCount(0);

    await submitAndWait(page, '#mainmenua_tools');
    await submitAndWait(page, '.vmenu a[href*="babyfoot/game_quickadd.php"] >> nth=0');
    await expect(page).toHaveURL(/babyfoot\/game_quickadd\.php/);
    await expect(page.locator('form.babyfoot-quickadd')).toBeVisible();
  });

  test('saving a game keeps the players and clears the scores', async ({ page }) => {
    await openQuickEntry2v2(page);
    await pickLineUp(page, [p1, p2], [p3, p4]);
    await page.fill('#score_team1', '10');
    await page.fill('#score_team2', '4');

    const before = lastGame().count;
    await submitAndWait(page, 'input[name="save"]');

    const after = lastGame();
    expect(after.count).toBe(before + 1);
    expect(after.last?.score_team1).toBe(10);
    expect(after.last?.score_team2).toBe(4);
    expect(after.last?.players).toEqual({ [p1.id]: 1, [p2.id]: 1, [p3.id]: 2, [p4.id]: 2 });

    await expect(page.locator('#player_1_1')).toHaveValue(String(p1.id));
    await expect(page.locator('#player_1_2')).toHaveValue(String(p2.id));
    await expect(page.locator('#player_2_1')).toHaveValue(String(p3.id));
    await expect(page.locator('#player_2_2')).toHaveValue(String(p4.id));
    await expect(page.locator('#score_team1')).toHaveValue('');
    await expect(page.locator('#score_team2')).toHaveValue('');
  });

  test('the swap button moves the players to the other team, scores untouched', async ({ page }) => {
    await openQuickEntry2v2(page);
    await pickLineUp(page, [p1, p2], [p3, p4]);
    await page.fill('#score_team1', '6');

    await page.locator('.babyfoot-swap').click();

    await expect(page.locator('#player_1_1')).toHaveValue(String(p3.id));
    await expect(page.locator('#player_1_2')).toHaveValue(String(p4.id));
    await expect(page.locator('#player_2_1')).toHaveValue(String(p1.id));
    await expect(page.locator('#player_2_2')).toHaveValue(String(p2.id));
    await expect(page.locator('#score_team1')).toHaveValue('6');

    await page.fill('#score_team2', '10');
    await submitAndWait(page, 'input[name="save"]');

    const after = lastGame();
    expect(after.last?.score_team1).toBe(6);
    expect(after.last?.score_team2).toBe(10);
    expect(after.last?.players).toEqual({ [p3.id]: 1, [p4.id]: 1, [p1.id]: 2, [p2.id]: 2 });
  });
});
