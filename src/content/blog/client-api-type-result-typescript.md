---
title: "Un client d'API typé en TypeScript, sans librairie, avec un type Result"
description: "Un wrapper fetch de 30 lignes qui renvoie les erreurs comme des valeurs, type l'enveloppe 422 de Laravel, et force le composant Svelte à tout gérer."
pubDate: 2026-07-08
tags: ["typescript", "svelte", "laravel"]
---

Quand je sors une petite app Svelte devant une API Laravel, la partie qui pourrit le plus vite, ce sont les appels réseau. `fetch` ne lève pas d'exception sur un statut 500, mais il en lève une si le réseau tombe ; le `res.json()` renvoie du `any` qui contamine tout ce qu'il touche ; et l'erreur de validation 422, on l'oublie jusqu'au jour où l'utilisateur voit une page blanche. On finit avec des `try/catch` dispersés qui attrapent une partie des cas et en ratent d'autres. Je préfère traiter les erreurs comme des valeurs, pas comme des exceptions — et laisser le compilateur me forcer à toutes les regarder.

## Le problème avec `fetch` brut

Voici l'appel qu'on écrit sans réfléchir, et tout ce qui cloche dedans :

```ts
async function chargerUtilisateur(id: number) {
  const res = await fetch(`/api/users/${id}`);
  const data = await res.json(); // any
  return data; // any, qui va se propager partout
}
```

Trois pièges dans ces trois lignes. D'abord, `fetch` ne rejette **que** sur une panne réseau : un 404 ou un 500 passe ici sans broncher, et `res.json()` tente de parser une page d'erreur. Ensuite, `res.json()` est typé `any` : à partir de là, plus aucune vérification, l'autocomplétion ment. Enfin, rien ne distingue « le serveur a planté » de « la validation a refusé le formulaire » — deux cas qui méritent pourtant un message très différent à l'écran.

## Le type Result, une union discriminée

L'idée tient en un type : une fonction qui peut échouer ne renvoie pas la donnée directement, elle renvoie un `Result` — soit un succès qui contient la donnée, soit un échec qui contient l'erreur.

```ts
export type Result<T> =
  | { ok: true; data: T }
  | { ok: false; error: ApiError };
```

C'est une **union discriminée** : le champ `ok` sert de discriminant. Le compilateur sait que si `ok` vaut `true`, alors `data` existe — et refuse d'accéder à `data` tant qu'on n'a pas vérifié `ok`. Impossible d'oublier le cas d'erreur : le seul moyen d'atteindre la donnée, c'est de traiter la branche `false` d'abord.

L'erreur elle-même est une union, parce que « une erreur d'API » n'est pas une seule chose. Je distingue trois familles :

```ts
export type ApiError =
  | { kind: 'network' }
  | { kind: 'server'; status: number }
  | { kind: 'validation'; message: string; errors: Record<string, string[]> };
```

La forme `validation` calque exactement l'enveloppe que renvoie Laravel sur un 422 : un `message` global et un objet `errors` qui associe à chaque champ un tableau de messages. Ce n'est pas un choix arbitraire, c'est le contrat de sortie du `FormRequest` de Laravel, typé une bonne fois pour toutes.

## Le wrapper `apiFetch`

Tout le travail se concentre dans une seule fonction, qui ne lève jamais d'exception et renvoie toujours un `Result` :

```ts
export async function apiFetch<T>(
  input: string,
  init?: RequestInit,
): Promise<Result<T>> {
  let response: Response;

  try {
    response = await fetch(input, {
      ...init,
      headers: { Accept: 'application/json', ...init?.headers },
    });
  } catch {
    return { ok: false, error: { kind: 'network' } };
  }

  if (response.status === 422) {
    const body = (await response.json()) as {
      message: string;
      errors: Record<string, string[]>;
    };
    return { ok: false, error: { kind: 'validation', ...body } };
  }

  if (!response.ok) {
    return { ok: false, error: { kind: 'server', status: response.status } };
  }

  return { ok: true, data: (await response.json()) as T };
}
```

Une trentaine de lignes qui referment chaque trou vu plus haut. Le `try/catch` transforme la seule exception que `fetch` sait produire — la panne réseau — en valeur `network`. Le 422 est intercepté avant tout le reste et rangé dans la branche `validation`. Tout autre statut non-2xx devient une erreur `server` qui garde le code. Et l'assertion `as T` reste **le seul** endroit du code où l'on affirme une forme à la main : le `any` est confiné ici, il ne fuit plus.

Un mot sur cette assertion : elle n'est pas une vérification, c'est une promesse que je fais au compilateur. Je choisis d'écrire les types de réponse manuellement plutôt que d'embarquer une génération de types — j'y reviens à la fin.

## Consommer proprement, côté Svelte

Reste à afficher tout ça sans retomber dans le `try/catch`. Je modélise l'état de l'appel comme une seconde union discriminée, `FetchState`, qui couvre les quatre situations d'un écran : rien demandé, en cours, réussi, échoué.

```ts
export type FetchState<T> =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'success'; data: T }
  | { status: 'error'; error: ApiError };
```

Un composant Svelte 5 qui soumet un formulaire de création d'article se branche dessus directement :

```svelte
<script lang="ts">
  import { apiFetch, type FetchState } from './api';

  type Article = { id: number; titre: string };

  let titre = $state('');
  let etat = $state<FetchState<Article>>({ status: 'idle' });

  async function envoyer(event: SubmitEvent) {
    event.preventDefault();
    etat = { status: 'loading' };

    const resultat = await apiFetch<Article>('/api/articles', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ titre }),
    });

    etat = resultat.ok
      ? { status: 'success', data: resultat.data }
      : { status: 'error', error: resultat.error };
  }
</script>

<form onsubmit={envoyer}>
  <label for="titre">Titre</label>
  <input id="titre" bind:value={titre} />
  <button type="submit" disabled={etat.status === 'loading'}>Publier</button>
</form>

{#if etat.status === 'loading'}
  <p>Envoi en cours…</p>
{:else if etat.status === 'success'}
  <p>Article « {etat.data.titre} » créé.</p>
{:else if etat.status === 'error'}
  {#if etat.error.kind === 'validation'}
    <ul>
      {#each Object.entries(etat.error.errors) as [champ, messages]}
        <li>{champ} : {messages.join(', ')}</li>
      {/each}
    </ul>
  {:else if etat.error.kind === 'network'}
    <p role="alert">Connexion impossible. Vérifiez votre réseau.</p>
  {:else}
    <p role="alert">Le serveur a renvoyé une erreur ({etat.error.status}).</p>
  {/if}
{/if}
```

Ce qui compte ici, c'est que le template **ne peut pas** afficher `etat.data` avant d'être entré dans la branche `success`, ni lire `etat.error.errors` avant d'avoir confirmé le `kind: 'validation'` : à chaque `{#if}`, TypeScript rétrécit l'union et interdit l'accès aux champs des autres cas. L'oubli d'un état — le chargement qu'on ne montre pas, l'erreur réseau qu'on laisse silencieuse — devient une faute visible plutôt qu'un bug découvert en production. Les erreurs de validation, elles, s'affichent champ par champ sans que j'aie eu à connaître à l'avance les noms des champs : ils sortent de l'enveloppe Laravel.

## Les contreparties, honnêtement

Ce pattern n'est pas gratuit, et il n'est pas toujours le bon choix.

- **Les types de réponse sont écrits à la main.** `apiFetch<Article>(…)` affirme une forme que rien ne vérifie au runtime. Si l'API change et que le front ne suit pas, le compilateur ne le verra pas — c'est un mensonge assumé. Dès que le contrat bouge souvent, ou que l'API expose un schéma OpenAPI, une génération de types depuis ce schéma (avec un outil comme [`openapi-fetch`](https://www.npmjs.com/package/openapi-fetch)) devient plus sûre que ma promesse manuelle : les types suivent le contrat automatiquement.
- **La validation runtime reste absente.** Pour garantir que la réponse a bien la forme annoncée, il faudrait valider le JSON avec un schéma (Zod ou équivalent). C'est un cran de robustesse que ce wrapper minimal ne prend pas — je l'assume pour un petit front, je l'ajouterais sur une app qui grossit.
- **Le wrapper reste volontairement naïf.** Il suppose un corps JSON en réponse ; un `204 No Content` ou une réponse HTML le ferait trébucher sur `res.json()`. Sur mes usages, l'API renvoie toujours du JSON ; sinon, une garde sur le `Content-Type` ou le statut 204 s'ajoute en deux lignes.

## Ce qu'il faut retenir

Traiter les erreurs comme des valeurs plutôt que comme des exceptions change la charge mentale : ce n'est plus à moi de me souvenir des cas à gérer, c'est le compilateur qui refuse de compiler tant qu'ils ne le sont pas.

- Un type `Result<T>` discriminé sur `ok` : la donnée est inaccessible tant que l'erreur n'est pas traitée.
- Une union `ApiError` qui distingue réseau, serveur et validation — cette dernière calquée sur l'enveloppe 422 de Laravel.
- Un `apiFetch` d'une trentaine de lignes qui ne lève jamais, et confine le seul `any` du code à une assertion unique.
- Un `FetchState` côté Svelte qui rend l'oubli d'un état — chargement, erreur réseau — impossible à compiler.

Pas de dépendance, pas de codegen : le jour où le projet grandit, on remplace l'assertion manuelle par une génération de types ou une validation Zod, sans rien changer à la forme que le composant consomme.
