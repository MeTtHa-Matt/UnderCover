<div align="center">

# 🕵️ Undercover

### Le jeu de rôle social et d'infiltration, à jouer entre amis sur un seul téléphone

[![PWA](https://img.shields.io/badge/PWA-Ready-5A0FC8?style=for-the-badge&logo=pwa&logoColor=white)](#)
[![Offline](https://img.shields.io/badge/Mode-Hors%20ligne-2ea44f?style=for-the-badge&logo=cachet&logoColor=white)](#)
[![License](https://img.shields.io/badge/Licence-À%20définir-lightgrey?style=for-the-badge)](#)
[![Made with JS](https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)](#)

</div>

---

## 🎭 Description

**Undercover** est une application web de jeu de rôle social pour plusieurs joueurs, pensée pour être jouée **sur un seul téléphone**, à la manière d'un jeu de société. Chaque joueur reçoit secrètement un mot et un rôle : **Civil**, **Undercover** ou **Mr White**. À travers les indices donnés à voix haute et les votes, le groupe doit démasquer les infiltrés — sans se faire piéger.

L'application fonctionne **entièrement hors ligne** une fois installée, grâce à une architecture de type PWA (Progressive Web App).

---

## 📁 Structure du projet

```
undercover/
├── index.html         # Structure HTML + métadonnées PWA + point d'ancrage du rendu dynamique
├── style.css           # Thème sombre, typographie, cartes, boutons, animations
├── script.js           # Logique principale du jeu et rendu dynamique
├── words_list.js        # Liste des paires de mots (Civils / Undercover)
├── manifest.json        # Manifeste PWA (installation standalone, config mobile)
└── sw.js                # Service worker (cache & fonctionnement hors ligne)
```

| Fichier | Rôle |
|---|---|
| `index.html` | Base HTML de l'application, contient la `div#app` pour le rendu dynamique |
| `style.css` | Apparence sombre, composants UI, animations |
| `script.js` | Moteur du jeu : rôles, votes, scores, sauvegarde |
| `words_list.js` | Banque de paires de mots utilisées en partie |
| `manifest.json` | Configuration PWA (icônes, nom, mode standalone) |
| `sw.js` | Mise en cache des ressources pour le mode hors ligne |

---

## ✨ Fonctionnalités principales

### ⚙️ Configuration de la partie
- 👥 De **3 à 20 joueurs**
- 🎯 Choix du nombre d'**Undercover** et de **Mr White**
- ✏️ Saisie facultative des noms des joueurs
- ✅ Vérification automatique des règles :
  - au moins un Civil
  - au moins un infiltré
  - nombre d'infiltrés ≤ nombre de Civils

### 🎲 Attribution des rôles
- Rôles possibles : **Civil** · **Undercover** · **Mr White**
- Distribution **aléatoire**, avec tentative d'éviter d'attribuer le même rôle deux fois de suite à un même joueur
- Un mot commun est donné aux Civils, un mot proche (mais différent) aux Undercover
- Mr White ne reçoit **aucun mot**

### 🔐 Phase de révélation
- Le téléphone circule de joueur en joueur
- Chacun consulte discrètement son rôle et son mot secret

### 🗳️ Phase de vote
- Affichage des joueurs encore en vie
- Élimination d'un joueur, avec **confirmation** avant validation

### 🏆 Résolution & score
- Civil ou Undercover éliminé → son rôle et son mot sont révélés
- Mr White éliminé → il peut tenter de **deviner le mot des Civils** pour renverser la partie
- Conditions de victoire :
  - 🟢 **Civils** gagnent si tous les infiltrés sont éliminés
  - 🔴 **Infiltrés** gagnent s'ils atteignent l'égalité ou la majorité
  - ⚪ **Mr White** peut gagner en devinant le mot
- Attribution de points selon le camp gagnant
- 📊 Classement général sauvegardé dans le `localStorage`

### 💾 Reprise et sauvegarde
- État de la partie persistant via `localStorage`
- Derniers réglages et dernière partie mémorisés
- Relance rapide avec les mêmes joueurs, ou reconfiguration complète
- Réinitialisation possible du classement général

---

## 📱 PWA & mode hors ligne

- Le fichier `manifest.json` permet d'**installer l'application** comme une app standalone (écran d'accueil mobile)
- Le `sw.js` met en cache les ressources essentielles à l'installation :
  `index.html`, `script.js`, `style.css`, `words_list.js`, `manifest.json`, icônes
- Stratégie appliquée : **cache d'abord, réseau en secours** (*cache-first, network fallback*)

---

## 📚 Exemple de liste de mots

Le fichier `words_list.js` contient des paires de mots proches, dont le rôle Undercover doit se fondre parmi les Civils :

| Civil | Undercover |
|---|---|
| ☕ Café | 🍵 Thé |
| 🐶 Chien | 🐱 Chat |
| 🏖️ Plage | 🏊 Piscine |
| 🍕 Pizza | 🍔 Burger |
| 🚗 Voiture | 🏍️ Moto |
| 📺 Netflix | ▶️ YouTube |
| 🌙 Lune | ☀️ Soleil |
| 🚆 Train | ✈️ Avion |
| 🌧️ Pluie | ❄️ Neige |
| 🧛 Vampire | 🧟 Zombie |

---

## 🚀 Exécution

Deux façons simples de lancer le jeu :

1. **En local** : ouvrir directement `index.html` dans un navigateur.
2. **En ligne** : déployer le dossier sur un serveur (local ou distant) puis y accéder depuis un mobile.

> 💡 L'application est conçue en priorité pour un usage **mobile** et **hors ligne** — installez-la sur l'écran d'accueil pour la meilleure expérience.

---

<div align="center">

Fait avec 🕵️‍♂️ et un peu de suspicion entre amis.

</div>