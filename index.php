<?php
/**
 * UNDERCOVER — jeu de société à jouer à plusieurs sur un seul téléphone
 * Un seul fichier PHP, session côté serveur, pas de base de données.
 */

session_start();

/* ---------------------------------------------------------------------
 *  DONNÉES : paires de mots (civil / undercover)
 * ------------------------------------------------------------------- */
$WORD_PAIRS = [
    ["Café", "Thé"],
    ["Chien", "Chat"],
    ["Plage", "Piscine"],
    ["Pizza", "Burger"],
    ["Voiture", "Moto"],
    ["Hiver", "Automne"],
    ["Guitare", "Piano"],
    ["Netflix", "YouTube"],
    ["Coca", "Pepsi"],
    ["Lune", "Soleil"],
    ["Docteur", "Infirmier"],
    ["Facebook", "Instagram"],
    ["Train", "Avion"],
    ["Riz", "Pâtes"],
    ["Foot", "Rugby"],
    ["Roi", "Président"],
    ["Vampire", "Zombie"],
    ["Pluie", "Neige"],
    ["Iphone", "Samsung"],
    ["Boxe", "Karaté"],
    ["Whisky", "Vodka"],
    ["Piscine", "Océan"],
    ["Professeur", "Directeur"],
    ["Robot", "Extraterrestre"],
    ["Château", "Palais"],
    ["Comédie", "Drame"],
    ["Vélo", "Trottinette"],
    ["Sushi", "Sashimi"],
    ["Montagne", "Colline"],
    ["Banque", "Poste"],
    ["Fromage", "Yaourt"],
    ["Cinéma", "Théâtre"],
    ["Tigre", "Lion"],
    ["Bibliothèque", "Librairie"],
    ["Skateboard", "Rollers"],
    ["Camping", "Hôtel"],
    ["Bière", "Vin"],
    ["Chocolat", "Bonbon"],
    ["Twitter", "TikTok"],
    ["Hôpital", "Clinique"],
];

/* ---------------------------------------------------------------------
 *  FONCTIONS UTILITAIRES
 * ------------------------------------------------------------------- */

function resetGame()
{
    unset($_SESSION['game']);
}

function roleLabel($role)
{
    switch ($role) {
        case 'civil':
            return 'Civil';
        case 'undercover':
            return 'Undercover';
        case 'mrwhite':
            return 'Mr White';
    }
    return $role;
}

/** Recalcule les vivants par rôle et détermine un éventuel vainqueur */
function checkWin(&$game)
{
    $civilAlive = 0;
    $infiltresAlive = 0; // undercover + mr white
    foreach ($game['players'] as $p) {
        if (!$p['alive'])
            continue;
        if ($p['role'] === 'civil')
            $civilAlive++;
        else
            $infiltresAlive++;
    }
    if ($infiltresAlive === 0) {
        $game['winner'] = 'civils';
    } elseif ($infiltresAlive >= $civilAlive) {
        $game['winner'] = 'infiltres';
    } else {
        $game['winner'] = null;
    }
}

/**
 * Calcule les points gagnés par chaque joueur pour la manche en cours,
 * en fonction de son rôle et du camp vainqueur :
 *  - Civils : 1 point chacun si les civils gagnent
 *  - Undercover : 2 points s'il est encore en vie et que les infiltrés gagnent
 *  - Mr White : 3 points s'il trouve le mot des civils après avoir été éliminé
 *               2 points s'il est encore en vie et que les infiltrés gagnent
 * Retourne un tableau [index_joueur => points]
 */
function computeScores($game)
{
    $scores = [];
    foreach ($game['players'] as $i => $p) {
        $pts = 0;
        if ($game['winner'] === 'civils') {
            if ($p['role'] === 'civil') {
                $pts = 1;
            }
        } elseif ($game['winner'] === 'infiltres') {
            if (($p['role'] === 'undercover' || $p['role'] === 'mrwhite') && $p['alive']) {
                $pts = 2;
            }
        } elseif ($game['winner'] === 'mrwhite') {
            if ($p['role'] === 'mrwhite') {
                $pts = 3;
            }
        }
        $scores[$i] = $pts;
    }
    return $scores;
}

/* ---------------------------------------------------------------------
 *  ENDPOINT AJAX — révélation du secret d'un joueur
 *  (ne renvoie le mot / rôle qu'au moment du clic, jamais avant,
 *   et uniquement pour le joueur dont c'est le tour)
 * ------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_secret') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['game'])) {
        echo json_encode(['error' => 'no_game']);
        exit;
    }
    $g = $_SESSION['game'];
    $pid = intval($_POST['pid'] ?? -1);

    // On ne dévoile QUE le joueur dont c'est actuellement le tour,
    // pour empêcher de récupérer le secret d'un autre joueur.
    if ($g['phase'] !== 'reveal' || $pid !== $g['reveal_index'] || !isset($g['players'][$pid])) {
        echo json_encode(['error' => 'invalid']);
        exit;
    }

    $p = $g['players'][$pid];
    if ($p['role'] === 'mrwhite') {
        echo json_encode([
            'role' => 'mrwhite',
            'label' => 'Rôle',
            'text' => 'MR WHITE — pas de mot !',
        ]);
    } else {
        echo json_encode([
            'role' => $p['role'],
            'label' => $p['role'] === 'civil' ? 'Ton mot' : 'Ton mot (undercover)',
            'text' => $p['word'],
        ]);
    }
    exit;
}

/* ---------------------------------------------------------------------
 *  TRAITEMENT DES ACTIONS (POST) — pattern Post/Redirect/Get
 * ------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'setup') {
        $nbPlayers = max(3, min(20, intval($_POST['nb_players'] ?? 0)));
        $nbUndercover = max(0, intval($_POST['nb_undercover'] ?? 0));
        $nbMrWhite = max(0, intval($_POST['nb_mrwhite'] ?? 0));
        $names = $_POST['names'] ?? [];

        // Garde-fous
        if ($nbUndercover + $nbMrWhite >= $nbPlayers) {
            $nbUndercover = max(0, $nbPlayers - 2);
            $nbMrWhite = 0;
        }
        $nbCivils = $nbPlayers - $nbUndercover - $nbMrWhite;
        if ($nbCivils < 1)
            $nbCivils = 1;

        // Rôles à distribuer, mélangés
        $roles = array_fill(0, $nbCivils, 'civil');
        $roles = array_merge($roles, array_fill(0, $nbUndercover, 'undercover'));
        $roles = array_merge($roles, array_fill(0, $nbMrWhite, 'mrwhite'));
        while (count($roles) < $nbPlayers)
            $roles[] = 'civil'; // sécurité
        $roles = array_slice($roles, 0, $nbPlayers);
        shuffle($roles);

        // Paire de mots aléatoire, sens aléatoire
        $pair = $WORD_PAIRS[array_rand($WORD_PAIRS)];
        if (rand(0, 1) === 1)
            $pair = array_reverse($pair);
        [$civilWord, $undercoverWord] = $pair;

        $players = [];
        for ($i = 0; $i < $nbPlayers; $i++) {
            $name = trim($names[$i] ?? '');
            if ($name === '')
                $name = 'Joueur ' . ($i + 1);
            $role = $roles[$i];
            $word = $role === 'civil' ? $civilWord : ($role === 'undercover' ? $undercoverWord : '');
            $players[] = [
                'name' => $name,
                'role' => $role,
                'word' => $word,
                'alive' => true,
            ];
        }

        $_SESSION['game'] = [
            'phase' => 'reveal',
            'reveal_index' => 0,
            'players' => $players,
            'civil_word' => $civilWord,
            'undercover_word' => $undercoverWord,
            'winner' => null,
            'last_eliminated' => null,
            'guess_info' => null,
            'round_scores' => null,
        ];
    } elseif ($action === 'next_reveal' && isset($_SESSION['game'])) {
        $g = &$_SESSION['game'];
        $g['reveal_index']++;
        if ($g['reveal_index'] >= count($g['players'])) {
            $g['phase'] = 'vote';
        }
    } elseif ($action === 'eliminate' && isset($_SESSION['game'])) {
        $g = &$_SESSION['game'];
        $pid = intval($_POST['pid'] ?? -1);
        if (isset($g['players'][$pid]) && $g['players'][$pid]['alive']) {
            $g['players'][$pid]['alive'] = false;
            $g['last_eliminated'] = $pid;
            $g['guess_info'] = null;

            if ($g['players'][$pid]['role'] === 'mrwhite') {
                $g['phase'] = 'mrwhite_guess';
            } else {
                checkWin($g);
                $g['phase'] = 'result';
            }
        }
    } elseif ($action === 'mrwhite_guess' && isset($_SESSION['game'])) {
        $g = &$_SESSION['game'];
        $guess = trim($_POST['guess'] ?? '');
        $correct = (mb_strtolower($guess) === mb_strtolower($g['civil_word']));
        $g['guess_info'] = [
            'guess' => $guess,
            'correct' => $correct,
        ];
        if ($correct) {
            $g['winner'] = 'mrwhite';
        } else {
            checkWin($g);
        }
        $g['phase'] = 'result';
    } elseif ($action === 'continue' && isset($_SESSION['game'])) {
        $g = &$_SESSION['game'];
        if (!empty($g['winner'])) {
            // Calcule les points de la manche et les cumule dans le classement général
            $roundScores = computeScores($g);
            $g['round_scores'] = $roundScores;

            if (!isset($_SESSION['scores']))
                $_SESSION['scores'] = [];
            foreach ($g['players'] as $i => $p) {
                if (!isset($_SESSION['scores'][$p['name']])) {
                    $_SESSION['scores'][$p['name']] = 0;
                }
                $_SESSION['scores'][$p['name']] += $roundScores[$i];
            }

            $g['phase'] = 'end';
        } else {
            $g['phase'] = 'vote';
        }
        $g['last_eliminated'] = null;
        $g['guess_info'] = null;
    } elseif ($action === 'reset') {
        resetGame();
    } elseif ($action === 'reset_scores') {
        unset($_SESSION['scores']);
    }

    header('Location: index.php');
    exit;
}

$game = $_SESSION['game'] ?? null;
$phase = $game['phase'] ?? 'setup';
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>Undercover</title>
    <style>
        :root {
            --bg-1: #0b0f1e;
            --bg-2: #1a1030;
            --accent: #8b5cf6;
            --accent-2: #22d3ee;
            --danger: #ef4444;
            --success: #22c55e;
            --card: #171225;
            --card-border: #2e2547;
            --text: #f1eefc;
            --text-dim: #a99fc9;
            --radius: 18px;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background: radial-gradient(circle at 50% -10%, var(--bg-2), var(--bg-1) 60%);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .wrap {
            max-width: 480px;
            margin: 0 auto;
            min-height: 100vh;
            padding: calc(20px + env(safe-area-inset-top)) 18px calc(28px + env(safe-area-inset-bottom));
            display: flex;
            flex-direction: column;
        }

        h1 {
            font-size: 1.6rem;
            text-align: center;
            margin: 4px 0 2px;
            letter-spacing: 1px;
        }

        h1 span {
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .subtitle {
            text-align: center;
            color: var(--text-dim);
            font-size: .85rem;
            margin-bottom: 22px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .35);
        }

        label {
            display: block;
            font-size: .85rem;
            color: var(--text-dim);
            margin: 14px 0 6px;
        }

        input[type=number],
        input[type=text] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: #0e0a1b;
            color: var(--text);
            font-size: 1rem;
            outline: none;
        }

        input:focus {
            border-color: var(--accent);
        }

        .row3 {
            display: flex;
            gap: 10px;
        }

        .row3>div {
            flex: 1;
        }

        .btn {
            display: block;
            width: 100%;
            text-align: center;
            padding: 15px 16px;
            border: none;
            border-radius: 14px;
            font-size: 1.02rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 14px;
            color: #fff;
            background: linear-gradient(90deg, var(--accent), #6d28d9);
            box-shadow: 0 8px 20px rgba(139, 92, 246, .35);
            transition: transform .1s ease;
        }

        .btn:active {
            transform: scale(.97);
        }

        .btn.secondary {
            background: #241b3a;
            box-shadow: none;
            border: 1px solid var(--card-border);
        }

        .btn.danger {
            background: linear-gradient(90deg, #ef4444, #b91c1c);
            box-shadow: 0 8px 20px rgba(239, 68, 68, .3);
        }

        .btn.ghost {
            background: transparent;
            border: 1px solid var(--card-border);
            box-shadow: none;
            color: var(--text-dim);
        }

        .names-list input {
            margin-bottom: 8px;
        }

        .rules {
            font-size: .82rem;
            color: var(--text-dim);
            line-height: 1.5;
        }

        details {
            margin-top: 16px;
        }

        summary {
            cursor: pointer;
            color: var(--accent-2);
            font-size: .85rem;
            font-weight: 600;
        }

        /* --- reveal phase --- */
        .pass-icon {
            font-size: 3rem;
            text-align: center;
            margin-bottom: 6px;
        }

        .center-text {
            text-align: center;
        }

        .player-name-big {
            font-size: 1.4rem;
            font-weight: 700;
            text-align: center;
            margin: 6px 0 18px;
        }

        .reveal-card {
            position: relative;
            border-radius: var(--radius);
            padding: 36px 20px;
            text-align: center;
            background: linear-gradient(160deg, #241a3f, #160f28);
            border: 1px solid var(--card-border);
            min-height: 150px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .reveal-word {
            font-size: 1.8rem;
            font-weight: 800;
            letter-spacing: .5px;
            user-select: none;
        }

        .reveal-role-tag {
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-dim);
        }

        .tap-hint {
            margin-top: 14px;
            font-size: .8rem;
            color: var(--text-dim);
        }

        .progress {
            text-align: center;
            font-size: .8rem;
            color: var(--text-dim);
            margin-bottom: 10px;
        }

        /* --- vote phase --- */
        .players-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 6px;
        }

        .player-btn {
            padding: 16px 8px;
            border-radius: 14px;
            border: 1px solid var(--card-border);
            background: #1c1530;
            color: var(--text);
            font-size: .95rem;
            font-weight: 600;
            text-align: center;
            cursor: pointer;
        }

        .player-btn:active {
            transform: scale(.96);
        }

        .player-btn.dead {
            opacity: .35;
            text-decoration: line-through;
            background: transparent;
        }

        .status-bar {
            display: flex;
            justify-content: space-around;
            margin-bottom: 16px;
            font-size: .8rem;
            color: var(--text-dim);
        }

        .status-bar b {
            color: var(--text);
        }

        /* --- result / end --- */
        .result-role {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: .8rem;
            font-weight: 700;
            margin-top: 6px;
        }

        .role-civil {
            background: rgba(34, 197, 94, .15);
            color: var(--success);
        }

        .role-undercover {
            background: rgba(239, 68, 68, .15);
            color: var(--danger);
        }

        .role-mrwhite {
            background: rgba(148, 163, 184, .2);
            color: #e2e8f0;
        }

        .win-banner {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 800;
            margin: 8px 0 4px;
        }

        .final-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .final-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 4px;
            border-bottom: 1px solid var(--card-border);
            font-size: .92rem;
        }

        .final-list li:last-child {
            border-bottom: none;
        }

        .word-hint {
            color: var(--text-dim);
            font-size: .78rem;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <h1>🕵️ <span>UNDERCOVER</span></h1>
        <div class="subtitle">Le jeu du mot secret — un seul téléphone, plusieurs joueurs</div>

        <?php if ($phase === 'setup'): ?>

            <!-- ============ ÉCRAN DE CONFIGURATION ============ -->
            <form method="post" id="setupForm">
                <input type="hidden" name="action" value="setup">
                <div class="card">
                    <label for="nb_players">Nombre de joueurs (3–20)</label>
                    <input type="number" id="nb_players" name="nb_players" min="3" max="20" value="6" required>

                    <div class="row3">
                        <div>
                            <label for="nb_undercover">Undercover</label>
                            <input type="number" id="nb_undercover" name="nb_undercover" min="0" value="1" required>
                        </div>
                        <div>
                            <label for="nb_mrwhite">Mr White</label>
                            <input type="number" id="nb_mrwhite" name="nb_mrwhite" min="0" value="1" required>
                        </div>
                    </div>

                    <label style="margin-top:18px;">Noms des joueurs (facultatif)</label>
                    <div class="names-list" id="namesList"></div>
                </div>

                <button type="submit" class="btn">Lancer la partie ▶</button>
            </form>

            <?php if (!empty($_SESSION['scores'])): ?>
                <div class="card">
                    <div style="font-weight:700;margin-bottom:8px;">🏆 Classement général</div>
                    <ul class="final-list">
                        <?php $general = $_SESSION['scores'];
                        arsort($general); ?>
                        <?php foreach ($general as $name => $pts): ?>
                            <li>
                                <span><?= htmlspecialchars($name) ?></span>
                                <b><?= $pts ?> pt<?= $pts > 1 ? 's' : '' ?></b>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <form method="post" onsubmit="return confirm('Réinitialiser le classement général ?');">
                    <input type="hidden" name="action" value="reset_scores">
                    <button type="submit" class="btn ghost">🗑️ Réinitialiser le classement général</button>
                </form>
            <?php endif; ?>

            <details class="card">
                <summary>Règles du jeu</summary>
                <div class="rules" style="margin-top:10px;">
                    Chaque <b>Civil</b> reçoit un mot secret. Les <b>Undercover</b> reçoivent
                    un mot proche mais différent. Les <b>Mr White</b> ne reçoivent aucun mot.<br><br>
                    À tour de rôle, chaque joueur donne un indice (un mot) lié à son mot secret,
                    sans le dire directement. Après une manche d'indices, tout le monde vote
                    pour éliminer le joueur qu'il pense être un infiltré.<br><br>
                    Si un <b>Mr White</b> est éliminé, il peut tenter de deviner le mot des
                    civils pour gagner immédiatement.<br><br>
                    Les Civils gagnent si tous les infiltrés (Undercover + Mr White) sont éliminés.
                    Les infiltrés gagnent s'ils sont à égalité ou en supériorité numérique face aux civils restants.<br><br>
                    <b>Points :</b> 1 point pour chaque Civil si les civils gagnent · 2 points pour
                    chaque Undercover encore en vie si les infiltrés gagnent · 2 points pour Mr White
                    s'il est encore en vie et que les infiltrés gagnent · 3 points pour Mr White s'il
                    trouve le mot des civils après avoir été éliminé.
                </div>
            </details>

            <script>
                const nbInput = document.getElementById('nb_players');
                const namesList = document.getElementById('namesList');
                function renderNameInputs() {
                    const n = Math.max(3, Math.min(20, parseInt(nbInput.value || '0', 10)));
                    const current = namesList.querySelectorAll('input');
                    const existing = [];
                    current.forEach(i => existing.push(i.value));
                    namesList.innerHTML = '';
                    for (let i = 0; i < n; i++) {
                        const inp = document.createElement('input');
                        inp.type = 'text';
                        inp.name = 'names[]';
                        inp.placeholder = 'Joueur ' + (i + 1);
                        inp.value = existing[i] || '';
                        namesList.appendChild(inp);
                    }
                }
                nbInput.addEventListener('input', renderNameInputs);
                renderNameInputs();

                document.getElementById('setupForm').addEventListener('submit', function (e) {
                    const players = parseInt(nbInput.value || '0', 10);
                    const uc = parseInt(document.getElementById('nb_undercover').value || '0', 10);
                    const mw = parseInt(document.getElementById('nb_mrwhite').value || '0', 10);
                    if (uc + mw >= players) {
                        e.preventDefault();
                        alert('Le nombre d\'Undercover + Mr White doit être inférieur au nombre total de joueurs (il faut au moins 1 Civil).');
                    }
                });
            </script>

        <?php elseif ($phase === 'reveal'): ?>

            <!-- ============ ÉCRAN DE RÉVÉLATION (passe-le-téléphone) ============ -->
            <?php
            $idx = $game['reveal_index'];
            $player = $game['players'][$idx];
            $total = count($game['players']);
            ?>
            <div class="progress">Joueur <?= $idx + 1 ?> / <?= $total ?></div>

            <div class="card">
                <div class="pass-icon">📱➡️</div>
                <div class="center-text" style="color:var(--text-dim);font-size:.85rem;">Passe le téléphone à</div>
                <div class="player-name-big"><?= htmlspecialchars($player['name']) ?></div>

                <!--
                Important : le contenu réel (mot ou "MR WHITE") n'est PAS envoyé
                au navigateur ici. On affiche un texte générique identique pour
                tout le monde, et le vrai secret n'est récupéré via AJAX qu'au
                moment du clic. Cela évite qu'on devine le rôle en observant la
                forme/longueur du texte flouté ou en lisant le code source.
            -->
                <div class="reveal-card" id="revealCard">
                    <div class="reveal-role-tag" id="revealTag">Rôle secret</div>
                    <div class="reveal-word" id="revealWord">••••••••••</div>
                </div>

                <div class="tap-hint" id="tapHint">Touche la carte pour révéler ton rôle</div>

                <button type="button" class="btn" id="revealBtn" onclick="doReveal()">👁️ Afficher mon rôle</button>

                <form method="post" id="nextForm" style="display:none;">
                    <input type="hidden" name="action" value="next_reveal">
                    <button type="submit" class="btn secondary">J'ai vu — Joueur suivant ✔</button>
                </form>
            </div>

            <script>
                function doReveal() {
                    const btn = document.getElementById('revealBtn');
                    btn.disabled = true;
                    fetch('index.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=get_secret&pid=<?= (int) $idx ?>'
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.error) {
                                alert('Impossible de révéler le rôle. Recharge la page.');
                                btn.disabled = false;
                                return;
                            }
                            document.getElementById('revealTag').textContent = data.label;
                            const wordEl = document.getElementById('revealWord');
                            wordEl.textContent = data.text;
                            wordEl.style.fontSize = (data.role === 'mrwhite') ? '1.4rem' : '';
                            document.getElementById('tapHint').style.display = 'none';
                            btn.style.display = 'none';
                            document.getElementById('nextForm').style.display = 'block';
                        })
                        .catch(() => {
                            alert('Erreur réseau. Réessaie.');
                            btn.disabled = false;
                        });
                }
            </script>

        <?php elseif ($phase === 'vote'): ?>

            <!-- ============ ÉCRAN DE VOTE ============ -->
            <?php
            $aliveCivil = 0;
            $aliveInf = 0;
            foreach ($game['players'] as $p) {
                if (!$p['alive'])
                    continue;
                if ($p['role'] === 'civil')
                    $aliveCivil++;
                else
                    $aliveInf++;
            }
            ?>
            <div class="status-bar">
                <div>🟢 Civils vivants : <b><?= $aliveCivil ?></b></div>
                <div>🔴 Infiltrés vivants : <b><?= $aliveInf ?></b></div>
            </div>

            <div class="card">
                <div class="center-text" style="margin-bottom:10px;">
                    Discutez, donnez vos indices, puis votez pour éliminer un joueur.
                </div>
                <form method="post" id="voteForm">
                    <input type="hidden" name="action" value="eliminate">
                    <input type="hidden" name="pid" id="votePid" value="">
                    <div class="players-grid">
                        <?php foreach ($game['players'] as $i => $p): ?>
                            <?php if ($p['alive']): ?>
                                <div class="player-btn" onclick="voteFor(<?= $i ?>, this)"><?= htmlspecialchars($p['name']) ?></div>
                            <?php else: ?>
                                <div class="player-btn dead"><?= htmlspecialchars($p['name']) ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>

            <script>
                function voteFor(pid, el) {
                    if (!confirm('Confirmer l\'élimination de « ' + el.textContent.trim() + ' » ?')) return;
                    document.getElementById('votePid').value = pid;
                    document.getElementById('voteForm').submit();
                }
            </script>

            <form method="post">
                <input type="hidden" name="action" value="reset">
                <button type="submit" class="btn ghost">↺ Abandonner / Nouvelle partie</button>
            </form>

        <?php elseif ($phase === 'mrwhite_guess'): ?>

            <!-- ============ MR WHITE TENTE DE DEVINER LE MOT ============ -->
            <?php $p = $game['players'][$game['last_eliminated']]; ?>
            <div class="card center-text">
                <div style="font-size:2rem;">🎩</div>
                <div class="player-name-big"><?= htmlspecialchars($p['name']) ?></div>
                <div style="color:var(--text-dim);margin-bottom:14px;">
                    était <b>Mr White</b> ! Il/elle a une dernière chance : deviner le mot des civils
                    pour gagner immédiatement.
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="mrwhite_guess">
                    <input type="text" name="guess" placeholder="Le mot des civils est..." autofocus required>
                    <button type="submit" class="btn">Valider ma réponse</button>
                </form>
            </div>

        <?php elseif ($phase === 'result'): ?>

            <!-- ============ RÉSULTAT D'UNE ÉLIMINATION ============ -->
            <?php
            $p = $game['players'][$game['last_eliminated']];
            $roleClass = 'role-' . $p['role'];
            ?>
            <div class="card center-text">
                <div style="font-size:2rem;">⚖️</div>
                <div class="player-name-big"><?= htmlspecialchars($p['name']) ?></div>
                <div>a été éliminé(e) !</div>
                <div class="result-role <?= $roleClass ?>"><?= strtoupper(roleLabel($p['role'])) ?></div>
                <?php if ($p['role'] !== 'mrwhite'): ?>
                    <div class="word-hint" style="margin-top:8px;">Son mot était : <b><?= htmlspecialchars($p['word']) ?></b>
                    </div>
                <?php endif; ?>

                <?php if (!empty($game['guess_info'])): ?>
                    <div style="margin-top:16px;padding:12px;border-radius:12px;background:#1c1530;">
                        <?php if ($game['guess_info']['correct']): ?>
                            🎉 Il/elle avait deviné <b><?= htmlspecialchars($game['guess_info']['guess']) ?></b> — c'est correct !
                        <?php else: ?>
                            ❌ Il/elle avait proposé « <?= htmlspecialchars($game['guess_info']['guess']) ?> » — mauvaise réponse (le
                            vrai mot était <b><?= htmlspecialchars($game['civil_word']) ?></b>).
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($game['winner'])): ?>
                    <div class="win-banner" style="margin-top:18px;">
                        <?php if ($game['winner'] === 'civils'): ?>
                            🏆 Les Civils gagnent !
                        <?php elseif ($game['winner'] === 'mrwhite'): ?>
                            🏆 Mr White gagne !
                        <?php else: ?>
                            🏆 Les Infiltrés gagnent !
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" style="margin-top:10px;">
                    <input type="hidden" name="action" value="continue">
                    <button type="submit"
                        class="btn"><?= !empty($game['winner']) ? 'Voir le résumé final 🏁' : 'Continuer la partie ▶' ?></button>
                </form>
            </div>

        <?php elseif ($phase === 'end'): ?>

            <!-- ============ ÉCRAN DE FIN DE PARTIE ============ -->
            <?php
            $roundScores = $game['round_scores'] ?? computeScores($game);
            // Ordre d'affichage : points décroissants
            $order = array_keys($game['players']);
            usort($order, function ($a, $b) use ($roundScores) {
                return $roundScores[$b] <=> $roundScores[$a];
            });
            ?>
            <div class="card center-text">
                <div style="font-size:2.4rem;">🏁</div>
                <div class="win-banner">
                    <?php if ($game['winner'] === 'civils'): ?>
                        Les Civils gagnent !
                    <?php elseif ($game['winner'] === 'mrwhite'): ?>
                        Mr White gagne !
                    <?php else: ?>
                        Les Infiltrés gagnent !
                    <?php endif; ?>
                </div>
                <div class="word-hint" style="margin-top:6px;">
                    Mot des civils : <b><?= htmlspecialchars($game['civil_word']) ?></b>
                    &nbsp;•&nbsp;
                    Mot des undercover : <b><?= htmlspecialchars($game['undercover_word']) ?></b>
                </div>
            </div>

            <div class="card">
                <div style="font-weight:700;margin-bottom:8px;">Classement de la manche</div>
                <ul class="final-list">
                    <?php foreach ($order as $i):
                        $p = $game['players'][$i];
                        $pts = $roundScores[$i]; ?>
                        <li>
                            <span><?= htmlspecialchars($p['name']) ?>         <?= $p['alive'] ? '' : '💀' ?></span>
                            <span style="display:flex;align-items:center;gap:8px;">
                                <span class="result-role role-<?= $p['role'] ?>"><?= strtoupper(roleLabel($p['role'])) ?></span>
                                <b><?= $pts ?> pt<?= $pts > 1 ? 's' : '' ?></b>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if (!empty($_SESSION['scores'])): ?>
                <div class="card">
                    <div style="font-weight:700;margin-bottom:8px;">🏆 Classement général</div>
                    <ul class="final-list">
                        <?php $general = $_SESSION['scores'];
                        arsort($general); ?>
                        <?php foreach ($general as $name => $pts): ?>
                            <li>
                                <span><?= htmlspecialchars($name) ?></span>
                                <b><?= $pts ?> pt<?= $pts > 1 ? 's' : '' ?></b>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="reset">
                <button type="submit" class="btn">🔄 Nouvelle partie</button>
            </form>
            <form method="post" onsubmit="return confirm('Réinitialiser le classement général ?');">
                <input type="hidden" name="action" value="reset_scores">
                <button type="submit" class="btn ghost">🗑️ Réinitialiser le classement général</button>
            </form>

        <?php endif; ?>

    </div>
</body>

</html>