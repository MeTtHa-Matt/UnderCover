const LS_GAME = "uc_game";
const LS_LAST_SETUP = "uc_last_setup";
const LS_SCORES = "uc_scores";
const LS_LAST_GAME = "uc_last_game";

function loadJSON(key, fallback) {
  try {
    const raw = localStorage.getItem(key);
    return raw ? JSON.parse(raw) : fallback;
  } catch (e) {
    return fallback;
  }
}

let game = loadJSON(LS_GAME, null);
let lastSetup = loadJSON(LS_LAST_SETUP, null);
let lastGame = loadJSON(LS_LAST_GAME, null);
let scores = loadJSON(LS_SCORES, {});
let setupError = null;
let setupAttempt = null;

function saveGame() {
  localStorage.setItem(LS_GAME, JSON.stringify(game));
}
function saveLastSetup() {
  localStorage.setItem(LS_LAST_SETUP, JSON.stringify(lastSetup));
}
function saveLastGame() {
  localStorage.setItem(LS_LAST_GAME, JSON.stringify(lastGame));
}
function saveScores() {
  localStorage.setItem(LS_SCORES, JSON.stringify(scores));
}

function esc(s) {
  const d = document.createElement("div");
  d.textContent = s == null ? "" : String(s);
  return d.innerHTML;
}

function roleLabel(role) {
  if (role === "civil") return "Civil";
  if (role === "undercover") return "Undercover";
  if (role === "mrwhite") return "Mr White";
  return role;
}

function secureRandom() {
  if (window.crypto && crypto.getRandomValues) {
    return crypto.getRandomValues(new Uint32Array(1))[0] / 0xffffffff;
  }
  return Math.random();
}

function randomInt(max) {
  return Math.floor(secureRandom() * max);
}

function shuffle(arr) {
  for (let i = arr.length - 1; i > 0; i--) {
    const j = randomInt(i + 1);
    [arr[i], arr[j]] = [arr[j], arr[i]];
  }
  return arr;
}

function pickRoleWithPenalty(roles, previousRole) {
  if (!previousRole) {
    return roles.splice(randomInt(roles.length), 1)[0];
  }

  const weights = roles.map((role) => (role === previousRole ? 0.5 : 1));
  const totalWeight = weights.reduce((sum, w) => sum + w, 0);
  let r = secureRandom() * totalWeight;
  for (let i = 0; i < roles.length; i++) {
    r -= weights[i];
    if (r < 0) {
      return roles.splice(i, 1)[0];
    }
  }
  return roles.splice(roles.length - 1, 1)[0];
}

function showConfirmModal(message) {
  return new Promise((resolve) => {
    const overlay = document.createElement("div");
    overlay.className = "modal-overlay";
    overlay.innerHTML = `
      <div class="modal-card">
        <div class="modal-title">Confirmation</div>
        <div class="modal-message">${esc(message)}</div>
        <div class="modal-actions">
          <button type="button" class="btn modal-btn modal-btn-cancel">Annuler</button>
          <button type="button" class="btn modal-btn modal-btn-confirm">Éliminer</button>
        </div>
      </div>
    `;

    const cancelBtn = overlay.querySelector(".modal-btn-cancel");
    const confirmBtn = overlay.querySelector(".modal-btn-confirm");

    function cleanup(value) {
      if (document.body.contains(overlay)) document.body.removeChild(overlay);
      document.removeEventListener("keydown", onKeyDown);
      resolve(value);
    }

    cancelBtn.addEventListener("click", () => cleanup(false));
    confirmBtn.addEventListener("click", () => cleanup(true));
    overlay.addEventListener("click", (event) => {
      if (event.target === overlay) cleanup(false);
    });

    function onKeyDown(event) {
      if (event.key === "Escape") {
        cleanup(false);
      } else if (event.key === "Enter") {
        event.preventDefault();
        cleanup(true);
      }
    }

    document.body.appendChild(overlay);
    document.addEventListener("keydown", onKeyDown);
    confirmBtn.focus();
  });
}

function getDefaultInfiltrés(nbPlayers) {
  if (nbPlayers <= 4) return { nb_undercover: 1, nb_mrwhite: 0 };
  if (nbPlayers <= 6) return { nb_undercover: 1, nb_mrwhite: 1 };
  if (nbPlayers <= 10) return { nb_undercover: 2, nb_mrwhite: 1 };
  if (nbPlayers <= 13) return { nb_undercover: 3, nb_mrwhite: 1 };
  if (nbPlayers <= 16) return { nb_undercover: 3, nb_mrwhite: 2 };
  return { nb_undercover: 4, nb_mrwhite: 2 };
}

function checkWin(g) {
  let civilAlive = 0,
    infAlive = 0;
  g.players.forEach((p) => {
    if (!p.alive) return;
    if (p.role === "civil") civilAlive++;
    else infAlive++;
  });
  if (infAlive === 0) g.winner = "civils";
  else if (infAlive >= civilAlive) g.winner = "infiltres";
  else g.winner = null;
}

function computeScores(g) {
  const out = {};
  const mrWhiteAlive = g.players.some((p) => p.role === "mrwhite" && p.alive);
  g.players.forEach((p, i) => {
    let pts = 0;
    if (g.winner === "civils") {
      if (p.role === "civil") pts = 1;
    } else if (g.winner === "infiltres") {
      if (mrWhiteAlive) {
        if (p.role === "mrwhite" && p.alive) pts = 2;
      } else {
        if (p.role === "undercover" && p.alive) pts = 2;
      }
    } else if (g.winner === "mrwhite") {
      if (p.role === "mrwhite") pts = 3;
    }
    out[i] = pts;
  });
  return out;
}

function buildGame(config) {
  const nbPlayers = config.nb_players;
  const nbUndercover = config.nb_undercover;
  const nbMrWhite = config.nb_mrwhite;
  const names = config.names || [];

  let nbCivils = nbPlayers - nbUndercover - nbMrWhite;
  if (nbCivils < 1) nbCivils = 1;

  let roles = Array(nbCivils)
    .fill("civil")
    .concat(Array(nbUndercover).fill("undercover"))
    .concat(Array(nbMrWhite).fill("mrwhite"));
  while (roles.length < nbPlayers) roles.push("civil");
  roles = roles.slice(0, nbPlayers);

  const previousRoleByName = new Map();
  let previousRoles = [];
  if (lastGame && Array.isArray(lastGame.players)) {
    lastGame.players.forEach((p, index) => {
      const name = (p.name || "").trim();
      if (name) previousRoleByName.set(name, p.role);
      previousRoles[index] = p.role;
    });
  }

  const assignedRoles = new Array(nbPlayers);
  const order = Array.from({ length: nbPlayers }, (_, i) => i);
  shuffle(order);
  for (const i of order) {
    const previousRole =
      previousRoleByName.get((names[i] || "").trim()) ||
      previousRoles[i] ||
      null;
    assignedRoles[i] = pickRoleWithPenalty(roles, previousRole);
  }

  let pair = WORD_PAIRS[randomInt(WORD_PAIRS.length)];
  if (secureRandom() < 0.5) pair = [pair[1], pair[0]];
  const [civilWord, undercoverWord] = pair;

  const players = [];
  for (let i = 0; i < nbPlayers; i++) {
    let name = (names[i] || "").trim();
    if (!name) name = "Joueur " + (i + 1);
    const role = assignedRoles[i];
    const word =
      role === "civil"
        ? civilWord
        : role === "undercover"
          ? undercoverWord
          : "";
    players.push({ name, role, word, alive: true });
  }

  return {
    phase: "reveal",
    reveal_index: 0,
    players,
    civil_word: civilWord,
    undercover_word: undercoverWord,
    winner: null,
    last_eliminated: null,
    guess_info: null,
    round_scores: null,
  };
}

function validateSetupValues(nbPlayers, nbUndercover, nbMrWhite) {
  const nbCivils = nbPlayers - nbUndercover - nbMrWhite;
  const nbInfiltres = nbUndercover + nbMrWhite;
  if (nbCivils < 1) {
    return "Il faut au moins 1 Civil : réduis le nombre d'Undercover et/ou de Mr White.";
  }
  if (nbInfiltres < 1) {
    return "Il faut au moins 1 infiltré (Undercover ou Mr White) pour jouer.";
  }
  if (nbInfiltres > nbCivils) {
    return (
      "Trop d'infiltrés : le nombre d'Undercover + Mr White (" +
      nbInfiltres +
      ") ne doit pas dépasser le nombre de Civils (" +
      nbCivils +
      ")."
    );
  }
  return null;
}

/* ---------------------------------------------------------
 *  ACTIONS
 * ------------------------------------------------------- */

function actionSetup(nbPlayers, nbUndercover, nbMrWhite, names) {
  nbPlayers = Math.max(3, Math.min(20, nbPlayers | 0));
  nbUndercover = Math.max(0, nbUndercover | 0);
  nbMrWhite = Math.max(0, nbMrWhite | 0);

  const error = validateSetupValues(nbPlayers, nbUndercover, nbMrWhite);
  if (error) {
    setupError = error;
    setupAttempt = {
      nb_players: nbPlayers,
      nb_undercover: nbUndercover,
      nb_mrwhite: nbMrWhite,
      names,
    };
    render();
    return;
  }
  const config = {
    nb_players: nbPlayers,
    nb_undercover: nbUndercover,
    nb_mrwhite: nbMrWhite,
    names,
  };
  setupError = null;
  setupAttempt = null;
  lastSetup = config;
  saveLastSetup();
  game = buildGame(config);
  saveGame();
  render();
}

function actionNextReveal() {
  game.reveal_index++;
  if (game.reveal_index >= game.players.length) game.phase = "vote";
  saveGame();
  render();
}

function actionEliminate(pid) {
  if (!game.players[pid] || !game.players[pid].alive) return;
  game.players[pid].alive = false;
  game.last_eliminated = pid;
  game.guess_info = null;
  if (game.players[pid].role === "mrwhite") {
    game.phase = "mrwhite_guess";
  } else {
    checkWin(game);
    game.phase = "result";
  }
  saveGame();
  render();
}

function actionMrwhiteGuess(guess) {
  guess = (guess || "").trim();
  const correct = guess.toLowerCase() === (game.civil_word || "").toLowerCase();
  game.guess_info = { guess, correct };
  if (correct) game.winner = "mrwhite";
  else checkWin(game);
  game.phase = "result";
  saveGame();
  render();
}

function actionContinue() {
  if (game.winner) {
    const roundScores = computeScores(game);
    game.round_scores = roundScores;
    game.players.forEach((p, i) => {
      if (!(p.name in scores)) scores[p.name] = 0;
      scores[p.name] += roundScores[i];
    });
    saveScores();
    game.phase = "end";
    lastGame = game;
    saveLastGame();
  } else {
    game.phase = "vote";
  }
  game.last_eliminated = null;
  game.guess_info = null;
  saveGame();
  render();
}

function actionReset() {
  if (game) {
    lastGame = game;
    saveLastGame();
  }
  game = null;
  if (lastSetup) {
    game = buildGame(lastSetup);
  }
  saveGame();
  render();
}

function actionChangeSetup() {
  game = null;
  saveGame();
  render();
}

function actionResetScores() {
  showConfirmModal(
    "Terminer la partie ? (le classement actuel sera supprimé)",
  ).then((confirmed) => {
    if (!confirmed) return;
    scores = {};
    lastSetup = null;
    game = null;
    saveScores();
    saveLastSetup();
    saveGame();
    render();
  });
}

/* ---------------------------------------------------------
 *  RENDU
 * ------------------------------------------------------- */

function scoresBlockHTML() {
  const entries = Object.entries(scores);
  if (entries.length === 0) return "";
  entries.sort((a, b) => b[1] - a[1]);
  return `
                <div class="card">
                    <div style="font-weight:700;margin-bottom:8px;">Classement général</div>
                    <ul class="final-list">
                        ${entries
                          .map(
                            ([name, pts]) => `
                            <li>
                                <span>${esc(name)}</span>
                                <b>${pts} pt${pts > 1 ? "s" : ""}</b>
                            </li>`,
                          )
                          .join("")}
                    </ul>
                </div>
                <button type="button" class="btn ghost" id="btnResetScores">Terminer la partie</button>
            `;
}

function renderSetup() {
  const prefill = setupAttempt || lastSetup || {};
  const nbPlayers = prefill.nb_players ?? 6;
  const names = prefill.names || [];
  const defaults = getDefaultInfiltrés(nbPlayers);
  const nbUndercover = prefill.nb_undercover ?? defaults.nb_undercover;
  const nbMrWhite = prefill.nb_mrwhite ?? defaults.nb_mrwhite;

  const app = document.getElementById("app");
  app.innerHTML = `
                ${
                  setupError
                    ? `
                <div class="card error-card">
                    <div class="error-row">
                        <div class="icon"></div>
                        <div class="msg">${esc(setupError)}</div>
                    </div>
                </div>`
                    : ""
                }
                <form id="setupForm">
                    <div class="card">
                        <label for="nb_players">Nombre de joueurs (3–20)</label>
                        <input type="number" id="nb_players" min="3" max="20" value="${nbPlayers}" required>

                        <div class="row3">
                            <div>
                                <label for="nb_undercover">Undercover</label>
                                <input type="number" id="nb_undercover" min="0" value="${nbUndercover}" required>
                            </div>
                            <div>
                                <label for="nb_mrwhite">Mr White</label>
                                <input type="number" id="nb_mrwhite" min="0" value="${nbMrWhite}" required>
                            </div>
                        </div>
                        <div id="roleDefaults" style="color:var(--text-dim);font-size:.82rem;margin-top:8px;">
                            Valeurs par défaut : ${defaults.nb_undercover} Undercover et ${defaults.nb_mrwhite} Mr White pour ${nbPlayers} joueurs.
                        </div>

                        <label style="margin-top:18px;">Noms des joueurs (facultatif)</label>
                        <div class="names-list" id="namesList"></div>
                    </div>

                    <button type="submit" class="btn">Lancer la partie ▶</button>
                </form>

                ${scoresBlockHTML()}

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
            `;

  const nbInput = document.getElementById("nb_players");
  const ucInput = document.getElementById("nb_undercover");
  const mwInput = document.getElementById("nb_mrwhite");
  const roleDefaults = document.getElementById("roleDefaults");
  const namesList = document.getElementById("namesList");
  let firstRender = true;
  let manualRoleChange = false;

  function renderNameInputs() {
    const n = Math.max(3, Math.min(20, parseInt(nbInput.value || "0", 10)));
    const current = namesList.querySelectorAll("input");
    const existing = [];
    current.forEach((i) => existing.push(i.value));
    namesList.innerHTML = "";
    for (let i = 0; i < n; i++) {
      const inp = document.createElement("input");
      inp.type = "text";
      inp.placeholder = "Joueur " + (i + 1);
      if (existing[i] !== undefined) inp.value = existing[i];
      else if (firstRender && names[i]) inp.value = names[i];
      else inp.value = "";
      namesList.appendChild(inp);
    }
    firstRender = false;
  }

  function updateRoleDefaults() {
    const nb = Math.max(3, Math.min(20, parseInt(nbInput.value || "0", 10)));
    const defaults = getDefaultInfiltrés(nb);
    if (!manualRoleChange) {
      ucInput.value = defaults.nb_undercover;
      mwInput.value = defaults.nb_mrwhite;
    }
    roleDefaults.textContent = `Valeurs par défaut : ${defaults.nb_undercover} Undercover et ${defaults.nb_mrwhite} Mr White pour ${nb} joueurs.`;
    renderNameInputs();
  }

  nbInput.addEventListener("input", updateRoleDefaults);
  ucInput.addEventListener("input", () => {
    manualRoleChange = true;
  });
  mwInput.addEventListener("input", () => {
    manualRoleChange = true;
  });
  updateRoleDefaults();

  function renderNameInputs() {
    const n = Math.max(3, Math.min(20, parseInt(nbInput.value || "0", 10)));
    const current = namesList.querySelectorAll("input");
    const existing = [];
    current.forEach((i) => existing.push(i.value));
    namesList.innerHTML = "";
    for (let i = 0; i < n; i++) {
      const inp = document.createElement("input");
      inp.type = "text";
      inp.placeholder = "Joueur " + (i + 1);
      if (existing[i] !== undefined) inp.value = existing[i];
      else if (firstRender && names[i]) inp.value = names[i];
      else inp.value = "";
      namesList.appendChild(inp);
    }
    firstRender = false;
  }
  nbInput.addEventListener("input", renderNameInputs);
  renderNameInputs();

  document.getElementById("setupForm").addEventListener("submit", function (e) {
    e.preventDefault();
    const nbPlayersVal = parseInt(nbInput.value || "0", 10);
    const nbUndercoverVal = parseInt(ucInput.value || "0", 10);
    const nbMrWhiteVal = parseInt(mwInput.value || "0", 10);
    const namesVal = Array.from(namesList.querySelectorAll("input")).map(
      (i) => i.value,
    );
    actionSetup(nbPlayersVal, nbUndercoverVal, nbMrWhiteVal, namesVal);
  });

  const btnResetScores = document.getElementById("btnResetScores");
  if (btnResetScores)
    btnResetScores.addEventListener("click", actionResetScores);
}

function renderReveal() {
  const idx = game.reveal_index;
  const player = game.players[idx];
  const total = game.players.length;
  const app = document.getElementById("app");
  app.innerHTML = `
                <div class="progress">Joueur ${idx + 1} / ${total}</div>
                <div class="card">
                    <div class="pass-icon">📱➡️</div>
                    <div class="center-text" style="color:var(--text-dim);font-size:.85rem;">Passe le téléphone à</div>
                    <div class="player-name-big">${esc(player.name)}</div>

                    <div class="reveal-card" id="revealCard">
                        <div class="reveal-role-tag" id="revealTag">Rôle secret</div>
                        <div class="reveal-word" id="revealWord">••••••••••</div>
                    </div>

                    <div class="tap-hint" id="tapHint">Touche la carte pour révéler ton rôle</div>

                    <button type="button" class="btn" id="revealBtn">Afficher mon rôle</button>
                    <button type="button" class="btn secondary" id="nextBtn" style="display:none;">J'ai vu — Joueur suivant ✔</button>
                </div>
            `;
  document.getElementById("revealBtn").addEventListener("click", function () {
    const tag = document.getElementById("revealTag");
    const word = document.getElementById("revealWord");
    if (player.role === "mrwhite") {
      tag.textContent = "Rôle";
      word.textContent = "MR WHITE — pas de mot !";
      word.style.fontSize = "1.4rem";
    } else {
      tag.textContent = player.role === "civil" ? "Ton mot" : "Ton mot";
      word.textContent = player.word;
      word.style.fontSize = "";
    }
    document.getElementById("tapHint").style.display = "none";
    this.style.display = "none";
    document.getElementById("nextBtn").style.display = "block";
  });
  document
    .getElementById("nextBtn")
    .addEventListener("click", actionNextReveal);
}

function renderVote() {
  let aliveCivil = 0,
    aliveInf = 0;
  game.players.forEach((p) => {
    if (!p.alive) return;
    if (p.role === "civil") aliveCivil++;
    else aliveInf++;
  });
  const app = document.getElementById("app");
  app.innerHTML = `
                <div class="status-bar">
                    <div>Civils vivants : <b>${aliveCivil}</b></div>
                    <div>Infiltrés vivants : <b>${aliveInf}</b></div>
                </div>
                <div class="card">
                    <div class="center-text" style="margin-bottom:10px;">
                        Discutez, donnez vos indices, puis votez pour éliminer un joueur.
                    </div>
                    <div class="players-grid">
                        ${game.players
                          .map((p, i) =>
                            p.alive
                              ? `<button type="button" class="player-btn" data-pid="${i}">${esc(p.name)}</button>`
                              : `<div class="player-btn dead">${esc(p.name)}</div>`,
                          )
                          .join("")}
                    </div>
                </div>
                <button type="button" class="btn ghost" id="btnAbandon">↺ Abandonner / Nouvelle partie</button>
            `;
  app.querySelectorAll(".player-btn[data-pid]").forEach((btn) => {
    btn.addEventListener("click", function () {
      const pid = parseInt(this.dataset.pid, 10);
      showConfirmModal(
        `Confirmer l'élimination de « ${this.textContent.trim()} » ?`,
      ).then((confirmed) => {
        if (confirmed) actionEliminate(pid);
      });
    });
  });
  document.getElementById("btnAbandon").addEventListener("click", actionReset);
}

function renderMrWhiteGuess() {
  const p = game.players[game.last_eliminated];
  const app = document.getElementById("app");
  app.innerHTML = `
                <div class="card center-text">
                    <div class="player-name-big">${esc(p.name)}</div>
                    <div style="color:var(--text-dim);margin-bottom:14px;">
                        était <b>Mr White</b> ! Il/elle a une dernière chance : deviner le mot des civils
                        pour gagner immédiatement.
                    </div>
                    <form id="guessForm">
                        <input type="text" id="guessInput" placeholder="Le mot des civils est..." autofocus required>
                        <button type="submit" class="btn">Valider ma réponse</button>
                    </form>
                </div>
            `;
  document.getElementById("guessForm").addEventListener("submit", function (e) {
    e.preventDefault();
    actionMrwhiteGuess(document.getElementById("guessInput").value);
  });
}

function renderResult() {
  const p = game.players[game.last_eliminated];
  const roleClass = "role-" + p.role;
  const app = document.getElementById("app");
  app.innerHTML = `
                <div class="card center-text">
                    <div class="player-name-big">${esc(p.name)}</div>
                    <div>a été éliminé(e) !</div>
                    <div class="result-role ${roleClass}">${roleLabel(p.role).toUpperCase()}</div>
                    ${
                      p.role !== "mrwhite"
                        ? `
                    <div class="word-hint" style="margin-top:8px;">Son mot était : <b>${esc(p.word)}</b></div>`
                        : ""
                    }

                    ${
                      game.guess_info
                        ? `
                    <div style="margin-top:16px;padding:12px;border-radius:12px;background:#1c1530;">
                        ${
                          game.guess_info.correct
                            ? `Il/elle avait deviné <b>${esc(game.guess_info.guess)}</b> — c'est correct !`
                            : `Il/elle avait proposé « ${esc(game.guess_info.guess)} » — mauvaise réponse (le vrai mot était <b>${esc(game.civil_word)}</b>).`
                        }
                    </div>`
                        : ""
                    }

                    ${
                      game.winner
                        ? `
                    <div class="win-banner" style="margin-top:18px;">
                        ${
                          game.winner === "civils"
                            ? "Les Civils gagnent !"
                            : game.winner === "mrwhite"
                              ? "Mr White gagne !"
                              : "Les Infiltrés gagnent !"
                        }
                    </div>`
                        : ""
                    }

                    <button type="button" class="btn" id="btnContinue" style="margin-top:10px;">
                        ${game.winner ? "Voir le résumé final" : "Continuer la partie ▶"}
                    </button>
                </div>
            `;
  document
    .getElementById("btnContinue")
    .addEventListener("click", actionContinue);
}

function renderEnd() {
  const roundScores = game.round_scores || computeScores(game);
  const order = game.players
    .map((_, i) => i)
    .sort((a, b) => roundScores[b] - roundScores[a]);
  const app = document.getElementById("app");
  app.innerHTML = `
                <div class="card center-text">
                    <div class="win-banner">
                        ${
                          game.winner === "civils"
                            ? "Les Civils gagnent !"
                            : game.winner === "mrwhite"
                              ? "Mr White gagne !"
                              : "Les Infiltrés gagnent !"
                        }
                    </div>
                    <div class="word-hint" style="margin-top:6px;">
                        Mot des civils : <b>${esc(game.civil_word)}</b>
                        &nbsp;•&nbsp;
                        Mot des undercover : <b>${esc(game.undercover_word)}</b>
                    </div>
                </div>

                <div class="card">
                    <div style="font-weight:700;margin-bottom:8px;">Classement de la manche</div>
                    <ul class="final-list">
                        ${order
                          .map((i) => {
                            const p = game.players[i];
                            const pts = roundScores[i];
                            return `
                            <li>
                                <span>${esc(p.name)} ${p.alive ? "" : "💀"}</span>
                                <span style="display:flex;align-items:center;gap:8px;">
                                    <span class="result-role role-${p.role}">${roleLabel(p.role).toUpperCase()}</span>
                                    <b>${pts} pt${pts > 1 ? "s" : ""}</b>
                                </span>
                            </li>`;
                          })
                          .join("")}
                    </ul>
                </div>

                ${scoresBlockHTML()}

                <button type="button" class="btn" id="btnReset">Nouvelle partie (mêmes joueurs)</button>
                <button type="button" class="btn secondary" id="btnChangeSetup">Changer les joueurs / paramètres</button>
            `;
  document.getElementById("btnReset").addEventListener("click", actionReset);
  document
    .getElementById("btnChangeSetup")
    .addEventListener("click", actionChangeSetup);
  const btnResetScores = document.getElementById("btnResetScores");
  if (btnResetScores)
    btnResetScores.addEventListener("click", actionResetScores);
}

function render() {
  const phase = game ? game.phase : "setup";
  if (phase === "setup") renderSetup();
  else if (phase === "reveal") renderReveal();
  else if (phase === "vote") renderVote();
  else if (phase === "mrwhite_guess") renderMrWhiteGuess();
  else if (phase === "result") renderResult();
  else if (phase === "end") renderEnd();
  else renderSetup();
}

render();

/* ---------------------------------------------------------
 *  PWA — installation & fonctionnement hors ligne
 * ------------------------------------------------------- */
if ("serviceWorker" in navigator) {
  window.addEventListener("load", () => {
    navigator.serviceWorker.register("sw.js").catch(() => {});
  });
}
