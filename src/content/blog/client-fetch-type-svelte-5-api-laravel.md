---
title: "Un client fetch typé en Svelte 5 pour consommer une API Laravel"
description: "Une classe .svelte.ts qui centralise data, chargement et erreur en $state, avec un fetch générique calé sur la forme des API Resource de Laravel."
pubDate: 2026-07-06
tags: ["svelte", "typescript"]
---

Une petite app Svelte qui consomme mon API Laravel, et le même bout de code qui se répète dans chaque composant : un `fetch`, un `try/catch`, trois variables pour l'état de chargement, et une réponse qu'on déballe à la main sans jamais savoir ce qu'elle contient. Multiplié par quinze écrans, ça devient un copier-coller fragile où chaque composant réinvente la même plomberie, souvent un peu différemment. Un client `fetch` typé et réutilisable règle ça une bonne fois : une classe qui porte l'état, une fonction générique calée sur la forme des réponses Laravel, et un état explicite plutôt que des booléens qui se contredisent.

## Le problème : le fetch recopié partout

Voici à quoi ressemble un composant qui charge une liste, dans sa version « chacun se débrouille » :

```svelte
<script lang="ts">
  import { onMount } from 'svelte';

  let users = $state<any[]>([]);
  let loading = $state(true);
  let error = $state<string | null>(null);

  onMount(async () => {
    try {
      const response = await fetch('/api/users');
      const body = await response.json();
      users = body.data;
    } catch (e) {
      error = 'Impossible de charger';
    } finally {
      loading = false;
    }
  });
</script>
```

Trois défauts sautent aux yeux. La réponse est typée `any` : personne ne sait ce qu'il y a dans `body.data`, et le premier `user.nom` au lieu de `user.name` ne pétera qu'à l'exécution. Un `fetch` en échec réseau et un `4xx` renvoyé par Laravel finissent dans le même `catch` avec le même message vague. Et ces trois variables — `users`, `loading`, `error` — vivent leur vie chacune de leur côté, à recopier dans le composant suivant.

## Typer la forme d'une réponse Laravel

Une API Resource de Laravel a une forme régulière : la donnée est toujours enveloppée dans une clé `data`, et une erreur renvoie un objet `{ "message": "…" }`. C'est exactement le genre de contrat qu'on peut coder une fois pour toutes. Je place tout ça dans un module `api.svelte.ts` — l'extension `.svelte.ts` est ce qui autorise les runes (`$state`) en dehors d'un composant.

```ts
// api.svelte.ts
type ResourceResponse<T> = { data: T };

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  static async from(response: Response): Promise<ApiError> {
    let message = `Erreur ${response.status}`;
    try {
      const body = await response.json();
      if (body?.message) message = body.message;
    } catch {
      // corps non-JSON : on garde le message par défaut
    }
    return new ApiError(response.status, message);
  }
}

export async function fetchResource<T>(
  url: string,
  signal?: AbortSignal,
): Promise<T> {
  const response = await fetch(url, {
    headers: { Accept: 'application/json' },
    signal,
  });

  if (!response.ok) {
    throw await ApiError.from(response);
  }

  const body = (await response.json()) as ResourceResponse<T>;
  return body.data;
}
```

La fonction est générique : `fetchResource<User[]>(…)` renvoie un `Promise<User[]>` déjà débarrassé de l'enveloppe `data`. Le typage colle à la réalité du back sans qu'aucun composant n'ait à connaître cette convention. Et une réponse `422` de Laravel ne se confond plus avec une coupure réseau : la première lève une `ApiError` avec son statut et le message du serveur, la seconde reste une exception `fetch` classique.

Pour une collection paginée, Laravel ajoute une clé `meta`. Il suffit d'un second type et d'une variante qui ne déballe pas :

```ts
type PaginatedCollection<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
};

export async function fetchCollection<T>(
  url: string,
  signal?: AbortSignal,
): Promise<PaginatedCollection<T>> {
  const response = await fetch(url, {
    headers: { Accept: 'application/json' },
    signal,
  });

  if (!response.ok) {
    throw await ApiError.from(response);
  }

  return (await response.json()) as PaginatedCollection<T>;
}
```

## Un état, pas trois booléens

Avant de brancher tout ça sur un composant, une décision de modélisation. Les trois variables `loading`, `error` et `data` peuvent, ensemble, décrire des situations qui n'existent pas : chargement à `true` *et* une erreur présente, ou des données *et* une erreur en même temps. Chaque combinaison impossible est un `if` de trop à écrire dans le template, et un bug qui attend.

La réponse est un type discriminé : un seul champ `status` qui rend les états mutuellement exclusifs.

```ts
type State<T> =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'success'; data: T }
  | { status: 'error'; error: ApiError };
```

Impossible d'être en `loading` et de porter une `error` : le compilateur l'interdit. On expose ensuite `data`, `loading` et `error` comme de simples lectures dérivées de ce statut unique.

## La classe réactive

C'est là que les runes entrent en jeu. Une classe porte l'état dans un champ `$state`, et l'expose via des accesseurs. Point important : on **n'exporte pas** directement une variable `$state` réassignable depuis un module (`export let data = $state(…)` déclenche l'erreur `state_invalid_export`). On encapsule l'état dans une classe, et c'est la classe qu'on exporte — chaque composant instancie la sienne.

```ts
export class ApiResource<T> {
  #state = $state<State<T>>({ status: 'idle' });
  #controller: AbortController | null = null;

  constructor(private readonly loader: (signal: AbortSignal) => Promise<T>) {}

  get loading(): boolean {
    return this.#state.status === 'loading';
  }

  get data(): T | null {
    return this.#state.status === 'success' ? this.#state.data : null;
  }

  get error(): ApiError | null {
    return this.#state.status === 'error' ? this.#state.error : null;
  }

  async load(): Promise<void> {
    this.#controller?.abort();
    const controller = new AbortController();
    this.#controller = controller;
    this.#state = { status: 'loading' };

    try {
      const data = await this.loader(controller.signal);
      this.#state = { status: 'success', data };
    } catch (error) {
      if (controller.signal.aborted) {
        return; // annulation volontaire : on ne touche pas à l'état
      }
      this.#state = {
        status: 'error',
        error:
          error instanceof ApiError
            ? error
            : new ApiError(0, 'Impossible de joindre le serveur.'),
      };
    }
  }
}
```

La classe ne connaît pas l'URL : elle reçoit un `loader`, une fonction qui prend un `AbortSignal` et renvoie la donnée. C'est ce qui la rend générique — elle enveloppe aussi bien `fetchResource` qu'une collection paginée, sans rien savoir de la forme.

Le `load()` gère aussi l'annulation. À chaque appel, il coupe la requête précédente (`this.#controller?.abort()`) avant d'en lancer une nouvelle. Sans ça, sur un champ de recherche qui déclenche un `fetch` à chaque frappe, une réponse lente arrivant après une réponse rapide écraserait le bon résultat. Ici, la requête annulée lève une exception qu'on ignore explicitement grâce à `signal.aborted` : l'état n'est pas pollué par une erreur qui n'en est pas une.

## Côté composant

Le composant, lui, maigrit d'autant. Plus de `onMount`, plus de `try/catch`, plus de trois variables : une instance, un appel, et un rendu qui suit le statut.

```svelte
<script lang="ts">
  import { ApiResource, fetchResource } from './api.svelte';
  import type { User } from './types';

  const users = new ApiResource((signal) =>
    fetchResource<User[]>('/api/users', signal),
  );

  users.load();
</script>

{#if users.loading}
  <p>Chargement…</p>
{:else if users.error}
  <p role="alert">{users.error.message}</p>
{:else if users.data}
  <ul>
    {#each users.data as u (u.id)}
      <li>{u.name}</li>
    {/each}
  </ul>
{/if}
```

Le `{#each users.data as u}` est typé : `u` est un `User`, l'éditeur autocomplète `u.name` et refuse `u.nom`. Le re-fetch — après un formulaire, un filtre, un bouton « rafraîchir » — se résume à rappeler `users.load()` : l'annulation de la requête en vol est déjà prise en charge. Et parce que les accesseurs lisent un champ `$state`, la réactivité de Svelte 5 rafraîchit le template toute seule à chaque changement de statut.

## Quand ne pas faire ça

Ce client vise un cas précis : une **petite SPA côté client** qui tape sur une API séparée. Dans ce cadre il est difficile à battre en simplicité. Hors de ce cadre, deux nuances honnêtes.

Si vous êtes sur **SvelteKit** avec du rendu serveur, sa fonction `load` charge la donnée avant le rendu, gère les états et évite le flash de chargement — inutile de la court-circuiter avec un `fetch` client. Et si vos besoins montent en gamme (cache partagé entre écrans, invalidation, retentatives, requêtes dépendantes), une bibliothèque comme **TanStack Query** fait tout ça sérieusement ; réécrire son équivalent serait une fausse économie.

Enfin, le piège à connaître : une instance créée **au niveau module** (`export const users = new ApiResource(…)`) est partagée. Côté client uniquement, c'est parfois pratique pour un cache global. Mais en contexte SSR, ce `$state` de module est partagé entre toutes les requêtes serveur — donc entre tous les utilisateurs. On ne s'en soucie pas dans une SPA pur client, mais c'est exactement pour ça qu'ici chaque composant instancie *sa* ressource.

## Ce qu'il faut retenir

Consommer une API depuis un front Svelte 5 ne réclame pas de recopier le même `fetch` partout. Trois pièces suffisent : une fonction générique calée sur la forme des réponses Laravel — l'enveloppe `data`, le message d'erreur —, un état discriminé qui rend les combinaisons impossibles réellement impossibles, et une classe `.svelte.ts` qui porte cet état en `$state` et l'expose proprement.

Le composant ne fait plus que déclarer sa ressource et suivre son statut. Reste à garder l'échelle en tête : ce pattern brille sur une petite SPA client ; dès que le rendu serveur ou un vrai cache entrent en jeu, `load` ou une bibliothèque dédiée reprennent l'avantage.
