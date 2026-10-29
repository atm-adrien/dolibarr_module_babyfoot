import { test, expect } from '@playwright/test';
import { apiCall, fixtures } from './babyfoot';

const { p1, p2, p3, p4 } = fixtures.players;

// Real HTTP calls: what the PHPUnit suite cannot see is the Restler routing.
const call = apiCall;

test.describe('babyfoot REST API', () => {
  test('me, players, ranking and stats answer', async ({ request }) => {
    const me = await call(request, 'GET', 'me');
    expect(me.status, me.body).toBe(200);
    expect(me.json.id).toBe(fixtures.runner_id);
    expect(me.json.rights.modify_all).toBe(true);
    expect(me.json.score_max).toBe(10);

    const players = await call(request, 'GET', 'players');
    expect(players.status, players.body).toBe(200);
    const listed = players.json.map((row: { fk_user: number }) => row.fk_user);
    expect(listed).toEqual(expect.arrayContaining([p1.id, p2.id, p3.id, p4.id]));

    expect((await call(request, 'GET', 'ranking?mode=all&period=30')).status).toBe(200);
    expect((await call(request, 'GET', 'stats?period=90')).status).toBe(200);
    expect((await call(request, 'GET', 'stats?period=7')).status).toBe(400);
  });

  test('a game goes through create, read, search, cancel, reopen and delete', async ({ request }) => {
    const created = await call(request, 'POST', 'games', {
      mode: '2v2',
      score_team1: 10,
      score_team2: 6,
      players: [
        { fk_user: p1.id, team: 1 },
        { fk_user: p2.id, team: 1 },
        { fk_user: p3.id, team: 2 },
        { login: 'e2ebabyfoot_p4', team: 2 },
      ],
    });
    expect(created.status, created.body).toBe(200);
    const id = created.json as number;

    const game = await call(request, 'GET', `games/${id}`);
    expect(game.status, game.body).toBe(200);
    expect(game.json.players.map((p: { name: string }) => p.name)).toEqual(
      expect.arrayContaining([p1.label, p4.label]),
    );

    const search = await call(request, 'GET', `games?search=${encodeURIComponent(p4.label)}&limit=0`);
    expect(search.json.map((g: { id: number }) => g.id)).toContain(id);

    const updated = await call(request, 'PUT', `games/${id}`, { score_team1: 4, score_team2: 10 });
    expect(updated.status, updated.body).toBe(200);
    expect(updated.json.winner_team).toBe(2);

    expect((await call(request, 'POST', `games/${id}/cancel`)).json.status).toBe(0);
    expect((await call(request, 'POST', `games/${id}/cancel`)).status).toBe(400);
    expect((await call(request, 'POST', `games/${id}/reopen`)).json.status).toBe(1);

    const card = await call(request, 'GET', `players/${p1.id}`);
    expect(card.status, card.body).toBe(200);
    expect(card.json.player.name).toBe(p1.label);
    const history = await call(request, 'GET', `players/${p1.id}/elo-history?mode=2v2`);
    expect(history.status, history.body).toBe(200);
    expect(history.json.length).toBeGreaterThanOrEqual(1);

    expect((await call(request, 'DELETE', `games/${id}`)).status).toBe(200);
    expect((await call(request, 'GET', `games/${id}`)).status).toBe(404);
  });
});
