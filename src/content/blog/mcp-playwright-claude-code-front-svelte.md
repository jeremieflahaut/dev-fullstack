---
title: "Le MCP Playwright : donner des yeux à Claude Code sur mon front Svelte"
description: "Brancher le serveur MCP Playwright sur Claude Code pour qu'il pilote un navigateur, lise le DOM et la console, et débogue lui-même mes composants Svelte."
pubDate: 2026-06-16
tags: ["ia", "svelte"]
---

Je suis à l'aise sur un backend, beaucoup moins devant un navigateur. Quand il m'arrive de sortir une petite app Svelte, le même schéma se répète : je demande un composant à Claude Code, il me pond du code propre… que je dois aller tester moi-même, copier l'erreur de la console, la recoller dans le chat, recommencer. L'assistant code à l'aveugle, et c'est moi qui fais l'aller-retour entre l'éditeur et le navigateur. Le serveur MCP Playwright change cette mécanique : il donne à l'agent un navigateur qu'il pilote tout seul.

## Le chaînon manquant : un navigateur que l'agent pilote

MCP (Model Context Protocol) est le protocole standard par lequel Claude Code parle à des outils externes. Un serveur MCP expose des actions, l'agent les appelle comme il appelle ses outils internes. Le serveur [Playwright MCP](https://github.com/microsoft/playwright-mcp) expose, justement, un navigateur Chromium piloté par Playwright.

L'idée maligne tient dans la façon dont l'agent « voit » la page. Plutôt qu'une capture d'écran — lourde en tokens et que le modèle interprète mal —, l'outil `browser_snapshot` renvoie l'**arbre d'accessibilité** de la page : une représentation textuelle et structurée du DOM, avec les rôles, les libellés et les états. C'est exactement le format qu'un LLM lit le mieux, et c'est bien plus fiable qu'une image pour savoir si un bouton est désactivé ou si un message d'erreur s'affiche.

Les actions principales du serveur :

- `browser_navigate` : ouvrir une URL ;
- `browser_snapshot` : lire l'arbre d'accessibilité ;
- `browser_click`, `browser_type` : interagir ;
- `browser_console_messages` : récupérer les logs et erreurs JS ;
- `browser_take_screenshot` : capturer en image, quand on veut juger du visuel.

## Brancher le serveur sur Claude Code

La configuration la plus simple est un fichier `.mcp.json` à la racine du projet (versionnable, partagé avec l'équipe) :

```json
{
  "mcpServers": {
    "playwright": {
      "command": "npx",
      "args": ["@playwright/mcp@latest"]
    }
  }
}
```

L'équivalent en une ligne, si vous préférez la CLI :

```bash
claude mcp add playwright -- npx @playwright/mcp@latest
```

Au prochain lancement, la commande `/mcp` dans Claude Code liste les serveurs connectés et leurs outils ; `playwright` doit y figurer avec ses actions `browser_*`. Rien d'autre à installer : `npx` récupère le paquet et Playwright télécharge son Chromium au premier usage.

## La boucle de feedback, en pratique

Prenons un composant Svelte 5 banal : un champ e-mail qui doit afficher une erreur et désactiver le bouton tant que la saisie est invalide.

```svelte
<script lang="ts">
  let email = $state('');
  let soumis = $state(false);

  const emailValide = $derived(
    /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)
  );

  function envoyer(event: SubmitEvent) {
    event.preventDefault();
    soumis = true;
  }
</script>

<form onsubmit={envoyer}>
  <label for="email">Adresse e-mail</label>
  <input id="email" type="email" bind:value={email} />

  {#if soumis && !emailValide}
    <p role="alert">Adresse e-mail invalide.</p>
  {/if}

  <button type="submit" disabled={!emailValide}>Envoyer</button>
</form>
```

Le serveur de dev tourne (`npm run dev`, sur `localhost:5173`). Au lieu de décrire à la main ce que je veux vérifier, je laisse l'agent fermer la boucle. Sa séquence d'outils ressemble à ça : `browser_navigate` vers la page, `browser_type` d'une saisie invalide dans le champ, `browser_click` sur « Envoyer », puis `browser_snapshot` pour constater le résultat. Ce que la snapshot lui renvoie, c'est du texte structuré, pas une image :

```yaml
- textbox "Adresse e-mail": pas-un-email
- alert: "Adresse e-mail invalide."
- button "Envoyer" [disabled]
```

L'agent lit là, noir sur blanc, que l'alerte est présente et que le bouton est bien désactivé — sans que j'aie ouvert le navigateur. S'il avait manqué un `preventDefault`, `browser_console_messages` aurait remonté l'erreur ou le rechargement de page, et il aurait corrigé avant même de me rendre la main. La boucle « écris → teste → corrige » se fait de son côté, là où je faisais l'aller-retour manuellement.

## Ce que ça change quand on n'est pas à l'aise en front

Sur du backend, je sais lire un test qui échoue et remonter à la cause. Sur du front, mon angle mort, c'est l'écart entre le code et le rendu : un composant qui « compile » mais ne réagit pas, un état Svelte qui ne se propage pas, une erreur silencieuse dans la console. Ce sont précisément les choses qu'un test unitaire ne couvre pas et que, faute de réflexes, je repère lentement.

Donner à l'agent les yeux sur la page réelle déplace ce travail d'inspection vers lui. Il vérifie le comportement observable — l'état d'un bouton, l'apparition d'un message, l'absence d'erreur console — et je relis un diff déjà confronté au rendu, pas une hypothèse. Pour quelqu'un qui touche au front par intermittence, c'est moins de friction sur la partie où j'en ai le plus.

## Les limites, parce qu'il y en a

L'outil n'est pas magique, et quelques contreparties méritent d'être dites.

- **L'arbre d'accessibilité n'est pas le visuel.** Une snapshot confirme qu'un bouton existe et qu'il est cliquable, pas qu'il est centré, lisible ou à la bonne couleur. Pour ça il faut `browser_take_screenshot` — coûteux en tokens, et le jugement esthétique du modèle reste limité. Les régressions purement CSS lui échappent largement.
- **Le serveur de dev, c'est à vous de le lancer.** Le MCP pilote un navigateur, il ne gère pas votre build. Si le `npm run dev` est tombé, l'agent voit une page blanche et peut partir dans une mauvaise direction.
- **Le rendu prend du temps.** Sur une app qui charge des données, l'agent doit attendre (`browser_wait_for`) avant de lire la page, sous peine de conclure trop vite. Mieux vaut le lui préciser dans la consigne.
- **Le contexte se remplit vite.** Chaque snapshot consomme des tokens. Sur une page dense, enchaîner les inspections grignote la fenêtre de contexte ; je cadre donc ce que je lui demande de vérifier plutôt que de le laisser explorer en boucle.

Aucune de ces limites n'est rédhibitoire, mais elles rappellent que l'outil vérifie un comportement, pas une intention de design. Le jugement « est-ce que c'est joli, est-ce que c'est clair pour l'utilisateur » reste de mon côté.

## Ce qu'il faut retenir

Le MCP Playwright comble le trou entre « l'assistant écrit du front » et « l'assistant sait si son front marche ». Pour un profil backend qui bricole du Svelte de temps en temps, c'est l'outil qui transforme une suite d'allers-retours manuels en une boucle que l'agent ferme seul.

- Un `.mcp.json` de quatre lignes suffit à brancher le serveur ; `/mcp` confirme la connexion.
- L'agent lit la page via l'arbre d'accessibilité (`browser_snapshot`), un format texte fiable et économe, et garde la capture d'écran pour le visuel.
- Il interagit, lit la console et corrige avant de vous rendre la main — à condition que le serveur de dev tourne et qu'on lui dise d'attendre les rendus.
- Il valide le comportement, pas le design : le coup d'œil esthétique reste humain.

La prochaine fois que vous demanderez un composant à Claude Code, laissez-le aller le tester lui-même dans le navigateur. C'est l'étape que, jusqu'ici, vous faisiez à sa place.
