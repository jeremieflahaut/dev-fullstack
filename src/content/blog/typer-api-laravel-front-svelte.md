---
title: "Typer les réponses de son API Laravel dans un front Svelte, sans lib"
description: "Modéliser l'enveloppe data/meta/links et le 422 de Laravel en une union discriminée, avec un petit fetch typé maison, et remonter les erreurs par champ dans un formulaire Svelte 5."
pubDate: 2026-07-01
tags: ["svelte", "typescript"]
---

Sur un side project, quand l'envie est là, je monte volontiers une petite app Svelte devant une API Laravel que je connais par cœur. Et à chaque fois, le même relâchement : je recopie à la main les types des réponses, j'entoure mes `fetch` d'un `try/catch` fourre-tout, et je considère le problème réglé. Jusqu'au jour où le back renvoie un 422 de validation — et là, le formulaire affiche « une erreur est survenue » au lieu du message précis attaché au champ fautif. Le back sait exactement ce qui cloche, le front décide de tout jeter. Voici comment je récupère cette information, avec un seul petit module typé et zéro dépendance.

## Le piège : `fetch` ne lève pas sur un 4xx

Le premier réflexe qu'on hérite d'axios, c'est de traiter le `try/catch` comme un aiguillage : succès dans le `try`, erreur dans le `catch`. Avec `fetch`, c'est un contresens. `fetch` ne rejette sa promesse que sur une panne réseau — DNS injoignable, requête coupée. Un 422, un 500, un 403 sont des réponses HTTP parfaitement valides : la promesse est **résolue**, pas rejetée. Le `catch` ne se déclenche jamais pour un 422.

L'aiguillage se fait donc sur `response.ok` (vrai pour un statut 200-299), ou sur `response.status` quand on veut distinguer finement :

```ts
const response = await fetch('/api/articles');

// response.ok est faux ici sur un 422, mais aucune exception n'a été levée.
if (!response.ok) {
  // c'est à nous de traiter l'échec, le catch ne le fera pas
}
```

Tant qu'on garde ça en tête, le reste découle proprement : lire le corps, et le typer selon qu'on est dans le cas succès ou dans le cas erreur.

## Modéliser ce que Laravel renvoie vraiment

L'avantage, quand on code aussi le back, c'est qu'on connaît la forme exacte des réponses. Les *API Resources* paginées de Laravel produisent toujours la même enveloppe : un tableau `data`, un objet `links` de navigation, un objet `meta` de pagination. On la modélise une fois, en générique :

```ts
// Enveloppe d'une collection paginée, telle que la produisent les API Resources.
export type PaginatedResponse<T> = {
  data: T[];
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
};
```

Pour une ressource unique, Laravel enveloppe aussi le résultat dans une clé `data` : un `type SingleResponse<T> = { data: T }` suffit. L'autre forme à modéliser, c'est l'échec de validation. Le 422 de Laravel a une structure fixe : un `message` global, et un objet `errors` qui associe à chaque champ un **tableau** de messages (un champ peut cumuler plusieurs violations).

```ts
// Corps d'une réponse 422 (échec de validation) de Laravel.
export type ValidationError = {
  message: string;
  errors: Record<string, string[]>;
};
```

Ces deux ou trois types tiennent dans un fichier et décrivent fidèlement le contrat du back. Reste à les brancher sur un appel.

## Un `apiFetch` qui renvoie un Result

Plutôt que de laisser chaque composant refaire le test `response.ok` et le parsing, je centralise tout dans une fonction qui renvoie une **union discriminée** — un *Result*, comme en Rust, mais sans neverthrow ni la moindre dépendance. Le discriminant est le booléen `ok` : côté appelant, TypeScript saura qu'après `if (resultat.ok)` le champ `data` existe, et que dans la branche `else` ce sont `status` et `errors` qui sont disponibles.

```ts
export type ApiResult<T> =
  | { ok: true; data: T }
  | { ok: false; status: number; message: string; errors: Record<string, string[]> };

export async function apiFetch<T>(
  url: string,
  init: RequestInit = {},
): Promise<ApiResult<T>> {
  const response = await fetch(url, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...init.headers,
    },
  });

  if (response.ok) {
    return { ok: true, data: (await response.json()) as T };
  }

  if (response.status === 422) {
    const payload = (await response.json()) as ValidationError;
    return {
      ok: false,
      status: 422,
      message: payload.message,
      errors: payload.errors,
    };
  }

  // 401, 403, 500… : un échec sans détail par champ.
  return {
    ok: false,
    status: response.status,
    message: `Requête échouée (${response.status})`,
    errors: {},
  };
}
```

L'`Accept: application/json` n'est pas décoratif : sans lui, Laravel peut répondre à une erreur de validation par une redirection plutôt que par le JSON attendu. On force le back à parler JSON. Le point important, c'est que la fonction ne lève jamais d'exception pour un échec métier. Elle transforme les trois mondes — succès, validation, autre erreur — en une seule valeur que l'appelant décortique par *narrowing*. Le `try/catch`, lui, ne sert plus qu'à ce pour quoi il est fait : la vraie panne réseau, qu'on peut envelopper autour de l'appel si on veut la distinguer.

## Remonter les erreurs par champ dans un formulaire Svelte 5

C'est là que le typage se paie en confort. Dans un composant Svelte 5, je tiens l'état du formulaire avec des runes `$state`, dont un objet `erreurs` calqué sur la forme du 422. Après l'appel, un simple test sur `resultat.ok` puis sur le statut suffit à remplir cet objet, et le rendu affiche le message sous chaque champ concerné.

```svelte
<script lang="ts">
  import { apiFetch } from './lib/api';

  type Article = { id: number; titre: string };

  let titre = $state('');
  let corps = $state('');
  let erreurs = $state<Record<string, string[]>>({});
  let messageGlobal = $state('');
  let envoi = $state(false);

  async function soumettre(event: SubmitEvent) {
    event.preventDefault();
    envoi = true;
    erreurs = {};
    messageGlobal = '';

    const resultat = await apiFetch<{ data: Article }>('/api/articles', {
      method: 'POST',
      body: JSON.stringify({ titre, corps }),
    });

    envoi = false;

    if (resultat.ok) {
      titre = '';
      corps = '';
      return;
    }

    if (resultat.status === 422) {
      erreurs = resultat.errors; // { titre: ["Le titre est obligatoire."], … }
      return;
    }

    messageGlobal = resultat.message; // 401, 500…
  }
</script>

<form onsubmit={soumettre}>
  <label for="titre">Titre</label>
  <input id="titre" bind:value={titre} />
  {#if erreurs.titre}
    <p role="alert">{erreurs.titre[0]}</p>
  {/if}

  <label for="corps">Contenu</label>
  <textarea id="corps" bind:value={corps}></textarea>
  {#if erreurs.corps}
    <p role="alert">{erreurs.corps[0]}</p>
  {/if}

  {#if messageGlobal}
    <p role="alert">{messageGlobal}</p>
  {/if}

  <button type="submit" disabled={envoi}>Publier</button>
</form>
```

Chaque champ lit `erreurs.titre?.[0]` — le premier message de sa liste — et le back reste seul juge de ce qui est valide. Ajouter une règle de validation côté Laravel remonte automatiquement au bon endroit du formulaire, sans toucher au front. On a réuni le meilleur des deux côtés : le contrôle serveur fait foi, et l'utilisateur voit précisément quel champ corriger.

## Quand la version « à la main » ne suffit plus

Honnêteté technique oblige : ce module a une limite assumée. Les types sont écrits **à la main**, donc rien ne garantit qu'ils restent alignés sur le back. Le jour où une *API Resource* renomme un champ, TypeScript continue de croire l'ancienne forme — le cast `as T` ne valide rien à l'exécution. Sur un petit front que je maintiens seul, ce risque est marginal : je touche aux deux côtés dans la même session, et un `astro check` me rappelle vite à l'ordre.

Le calcul change dès que le contrat grossit ou qu'une autre personne consomme l'API. À ce moment-là, générer les types depuis une spécification OpenAPI devient justifié : un client comme [openapi-fetch](https://openapi-ts.dev/openapi-fetch/) produit un fetch typé à partir du schéma, avec un coût runtime nul, et fait échouer la compilation dès que le back diverge. La contrepartie, c'est une chaîne à mettre en place — exposer l'OpenAPI côté Laravel, regénérer les types à chaque évolution. Pour une petite app, c'est de la sur-ingénierie ; pour une API partagée qui vit sa vie, c'est le filet qui manquait. La règle que je m'applique : à la main tant que je suis seul maître des deux bouts, génération dès qu'un tiers dépend du contrat.

## Ce qu'il faut retenir

Consommer proprement une API Laravel depuis Svelte ne demande ni axios, ni couche d'abstraction lourde :

1. **`fetch` ne lève pas sur un 4xx/5xx** — l'aiguillage se fait sur `response.ok`, pas dans un `catch`.
2. **Modélisez les deux formes réelles** : l'enveloppe `data/meta/links` des API Resources, et le `{ message, errors }` du 422.
3. **Renvoyez une union discriminée** `{ ok: true; data } | { ok: false; status; errors }` depuis un unique `apiFetch` : le narrowing rend l'usage sûr et lisible.
4. **Remontez `errors[champ]`** dans l'état `$state` du formulaire pour afficher l'erreur là où elle se produit, en laissant le back seul juge.
5. **Passez à la génération OpenAPI** quand le contrat est partagé — pas avant.

Au bout du compte, c'est un fichier d'une trentaine de lignes qui absorbe la forme exacte des réponses Laravel et rend ses erreurs exploitables. Assez pour arrêter de jeter, sur un 422, l'information que le back a pris soin de fournir.
