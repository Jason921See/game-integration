<?php
/**
 * Deterministic fixture generator for mock providers.
 *
 * Usage: php tools/mock-providers/generate-fixtures.php
 *
 * Writes:
 *   fixtures/ndmplay-bets.json     — 150 rows × 2 players, enveloped shape fields
 *   fixtures/ndmplay-games.json    — 10 games across 5 categories
 *   fixtures/novaxlive-bets.json   — 150 rows × 2 players, FUMA shape fields
 *   fixtures/novaxlive-games.json  — 10 games, only live + poker (gameCategoryId 1 & 5)
 */

mt_srand(20260414);

$FIXTURE_DIR = __DIR__ . '/fixtures';
if (!is_dir($FIXTURE_DIR)) {
    mkdir($FIXTURE_DIR, 0755, true);
}

// =====================================================
// NDMPlay games (snake_case, 5 categories, 2 each)
// =====================================================
$ndmGames = [
    ['game_code' => 'ndm_baccarat',    'game_name' => 'NDM Baccarat',      'category' => 'live'],
    ['game_code' => 'ndm_roulette',    'game_name' => 'NDM Roulette',      'category' => 'live'],
    ['game_code' => 'ndm_wolf',        'game_name' => 'NDM Wolf',          'category' => 'slot'],
    ['game_code' => 'ndm_treasure',    'game_name' => 'NDM Treasure',      'category' => 'slot'],
    ['game_code' => 'ndm_ocean',       'game_name' => 'NDM Ocean',         'category' => 'fishing'],
    ['game_code' => 'ndm_shark_hunt',  'game_name' => 'NDM Shark Hunt',    'category' => 'fishing'],
    ['game_code' => 'ndm_dice',        'game_name' => 'NDM Dice',          'category' => 'minigame'],
    ['game_code' => 'ndm_plinko',      'game_name' => 'NDM Plinko',        'category' => 'minigame'],
    ['game_code' => 'ndm_texas',       'game_name' => 'NDM Texas Holdem',  'category' => 'poker'],
    ['game_code' => 'ndm_stud',        'game_name' => 'NDM Seven Stud',    'category' => 'poker'],
];
foreach ($ndmGames as &$g) {
    $g['thumbnail'] = "https://res.hotdog-gaming.com/banners/hd-regency-lingling.png";
}
unset($g);
file_put_contents(
    "$FIXTURE_DIR/ndmplay-games.json",
    json_encode($ndmGames, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

// =====================================================
// NovaXLive games (FUMA shape: gType/name/image/gameCategoryId)
// Only live (1) and poker (5), 5 games per category
// =====================================================
$novaGames = [
    ['gType' => 'novax_live_baccarat',  'name' => 'Nova Live Baccarat',    'gameCategoryId' => 1],
    ['gType' => 'novax_speed_bj',       'name' => 'Nova Speed Blackjack',  'gameCategoryId' => 1],
    ['gType' => 'novax_live_roulette',  'name' => 'Nova Live Roulette',    'gameCategoryId' => 1],
    ['gType' => 'novax_dragon_tiger',   'name' => 'Nova Dragon Tiger',     'gameCategoryId' => 1],
    ['gType' => 'novax_sic_bo',         'name' => 'Nova Sic Bo',           'gameCategoryId' => 1],
    ['gType' => 'novax_holdem',         'name' => "Nova Hold'em",          'gameCategoryId' => 5],
    ['gType' => 'novax_video_poker',    'name' => 'Nova Video Poker',      'gameCategoryId' => 5],
    ['gType' => 'novax_caribbean_stud', 'name' => 'Nova Caribbean Stud',   'gameCategoryId' => 5],
    ['gType' => 'novax_three_card',     'name' => 'Nova Three Card Poker', 'gameCategoryId' => 5],
    ['gType' => 'novax_pai_gow',        'name' => 'Nova Pai Gow',          'gameCategoryId' => 5],
];
foreach ($novaGames as &$g) {
    $g['image'] = "https://res.hotdog-gaming.com/banners/hd-aztec-hunt.png";
}
unset($g);
file_put_contents(
    "$FIXTURE_DIR/novaxlive-games.json",
    json_encode($novaGames, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

// =====================================================
// NDMPlay bet history (150 rows × 2 players = 300)
// =====================================================
$players = ['p1', 'p2'];
$playerIds = ['p1' => 101, 'p2' => 102];
$BASE_TS = strtotime('2026-01-01 00:00:00 UTC');  // base time for deterministic bet timestamps
$ndmBets = [];
$seq = 1;

foreach ($players as $uname) {
    for ($i = 0; $i < 150; $i++) {
        $game = $ndmGames[mt_rand(0, count($ndmGames) - 1)];
        $bet = mt_rand(100, 5000) / 100;
        $isWin = mt_rand(0, 100) < 48;
        $win = $isWin ? round($bet * (mt_rand(150, 400) / 100), 2) : 0.0;
        $winLoss = round($win - $bet, 2);
        $ts = $BASE_TS + ($seq * 900);

        $ndmBets[] = [
            'txn_id'        => sprintf('NDM-TXN-%06d', $seq),
            'round_id'      => sprintf('NDM-R-%06d', $seq),
            'username'      => $uname,
            'user_id'       => $playerIds[$uname],
            'platform'      => 'km',
            'game_code'     => $game['game_code'],
            'game_name'     => $game['game_name'],
            'valid_bet'     => number_format($bet, 2, '.', ''),
            'win_loss'      => number_format($winLoss, 2, '.', ''),
            'settle_amount' => number_format($win, 2, '.', ''),
            'bet_time'      => gmdate('c', $ts),
        ];
        $seq++;
    }
}
file_put_contents(
    "$FIXTURE_DIR/ndmplay-bets.json",
    json_encode($ndmBets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

// =====================================================
// NovaXLive bet history (FUMA shape)
// =====================================================
$novaBets = [];
$seq = 1;
$runningBal = ['p1' => 500.00, 'p2' => 500.00];

foreach ($players as $uname) {
    for ($i = 0; $i < 150; $i++) {
        $game = $novaGames[mt_rand(0, count($novaGames) - 1)];
        $bet = mt_rand(100, 5000) / 100;
        $isWin = mt_rand(0, 100) < 48;
        $win = $isWin ? round($bet * (mt_rand(150, 400) / 100), 2) : 0.0;

        $startV = round($runningBal[$uname], 2);
        $endV = round($startV - $bet + $win, 2);
        $runningBal[$uname] = $endV;
        $ts = $BASE_TS + ($seq * 900);

        $novaBets[] = [
            'time'   => gmdate('Y-m-d H:i:s', $ts),
            'gname'  => $game['name'],
            'gType'  => $game['gType'],
            'bet'    => $bet,
            'win'    => $win,
            'startV' => $startV,
            'endV'   => $endV,
            'des'    => $isWin ? 'spin settled' : 'loss settled',
            'uid'    => $uname,
            'seqNo'  => sprintf('NOVA-SEQ-%06d', $seq),
        ];
        $seq++;
    }
}
file_put_contents(
    "$FIXTURE_DIR/novaxlive-bets.json",
    json_encode($novaBets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Generated fixtures:\n";
foreach (glob("$FIXTURE_DIR/*.json") as $f) {
    echo "  " . basename($f) . " — " . number_format(filesize($f)) . " bytes\n";
}
