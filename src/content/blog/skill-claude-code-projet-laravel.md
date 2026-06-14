---
title: "Une skill Claude Code « projet » pour Laravel : scripts, assets et bon usage"
description: "Au-delà du SKILL.md en prose : anatomie d'une vraie skill projet versionnée, avec scripts/ et assets/, pour scaffolder un job de queue Laravel et son test Pest."
pubDate: 2026-06-14
tags: ["laravel", "ia"]
---

À chaque nouveau job de queue, je recolle le même playbook : la bonne signature, `$tries`, un `backoff`, un `failed()` qui loggue, et un test Pest qui vérifie que le tout part bien sur la file. C'est mécanique, c'est toujours identique, et c'est exactement le genre de rituel qu'on finit par bâcler un vendredi soir. Plutôt que de le réexpliquer à mon assistant à chaque fois, j'en ai fait une skill Claude Code versionnée dans le dépôt. Les tutos s'arrêtent souvent au `SKILL.md` en prose ; je voudrais montrer les briques qu'ils zappent — `scripts/`, `assets/` — et dire quand une skill n'est pas le bon outil.

## Le problème : un playbook qu'on recolle à la main

Une skill, dans Claude Code, c'est un dossier qui apprend à l'assistant à exécuter une tâche précise selon *vos* conventions. La forme minimale qu'on voit partout, c'est un fichier `SKILL.md` qui décrit la marche à suivre en français. Ça marche, mais ça reste de la prose : l'assistant lit vos consignes, puis les réinterprète à sa façon. Pour un scaffolding où chaque détail compte (la liste exacte des traits, la valeur de `$tries`), cette réinterprétation est précisément ce qu'on veut éviter.

L'idée, c'est de faire descendre la skill d'un cran : un peu de prose pour le contexte, mais le travail déterministe confié à un script et un template versionnés.

## Anatomie d'une skill projet

Une skill projet vit dans `.claude/skills/<nom>/`. Le minimum, c'est `SKILL.md` avec un frontmatter :

```markdown
---
name: scaffold-queue-job
description: Scaffolde un job de queue Laravel conforme aux conventions du projet (tries, backoff, failed) avec son test Pest. À utiliser quand on demande de créer un job, une tâche asynchrone ou un traitement en file d'attente.
---
```

Le champ à soigner, c'est `description`. C'est lui — et lui seul — que l'assistant lit en permanence pour décider s'il doit charger la skill. C'est le mécanisme de *progressive disclosure* : le corps du `SKILL.md` n'est lu **que** lorsque la description matche la demande. Tant que la skill ne sert pas, son coût en contexte est quasi nul. Une description vague (« aide pour les jobs ») se déclenchera mal ; une description qui liste les déclencheurs concrets (« créer un job, une tâche asynchrone, un traitement en file d'attente ») se déclenche au bon moment.

À côté de `SKILL.md`, trois dossiers conventionnels changent la donne :

- `scripts/` : des scripts **exécutés verbatim**. L'assistant les lance, il ne les réécrit pas. C'est là que vit le déterminisme.
- `assets/` : les fichiers utilisés pour produire le résultat — ici, les templates du job et du test.
- `references/` : de la doc longue, chargée à la demande seulement si la tâche l'exige.

## L'exemple complet : scaffolder un job de queue

Voici la skill de bout en bout. D'abord le corps du `SKILL.md`, qui reste volontairement court — il oriente, il n'exécute pas :

```markdown
# Scaffold d'un job de queue

Quand on demande un nouveau job de queue :

1. Récupérer le nom en PascalCase (ex. `SendInvoiceEmail`).
2. Exécuter le script de génération :

   ```bash
   bash .claude/skills/scaffold-queue-job/scripts/make-job.sh <NomDuJob>
   ```

3. Ne pas écrire les fichiers à la main : le script copie les templates
   de `assets/` et substitue le nom. Compléter ensuite uniquement la
   logique métier dans `handle()`.
4. Vérifier que le test passe : `php artisan test --filter=<NomDuJob>`.
```

Le cœur déterministe, c'est `scripts/make-job.sh`. Il refuse d'écraser un fichier existant et substitue le nom dans les deux templates :

```bash
#!/usr/bin/env bash
set -euo pipefail

if [ $# -ne 1 ]; then
  echo "Usage: make-job.sh <NomDuJob en PascalCase>" >&2
  exit 1
fi

NAME="$1"
SKILL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
JOB_PATH="app/Jobs/${NAME}.php"
TEST_PATH="tests/Feature/Jobs/${NAME}Test.php"

if [ -f "$JOB_PATH" ]; then
  echo "Le job ${JOB_PATH} existe déjà, abandon." >&2
  exit 1
fi

mkdir -p "$(dirname "$JOB_PATH")" "$(dirname "$TEST_PATH")"
sed "s/__JOB__/${NAME}/g" "${SKILL_DIR}/assets/job.stub" > "$JOB_PATH"
sed "s/__JOB__/${NAME}/g" "${SKILL_DIR}/assets/job.test.stub" > "$TEST_PATH"

echo "Créé : ${JOB_PATH}"
echo "Créé : ${TEST_PATH}"
```

Le template `assets/job.stub` porte toutes les conventions maison, figées une bonne fois :

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class __JOB__ implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $modelId,
    ) {
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        // TODO : logique métier.
    }

    public function failed(Throwable $e): void
    {
        Log::error('__JOB__ a échoué', [
            'model_id' => $this->modelId,
            'message' => $e->getMessage(),
        ]);
    }
}
```

Notez le constructeur en *property promotion* qui ne transporte qu'un identifiant, pas un modèle Eloquent entier — un réflexe utile dès qu'on sérialise un job. Et `assets/job.test.stub`, son test Pest :

```php
<?php

use App\Jobs\__JOB__;
use Illuminate\Support\Facades\Queue;

it('est poussé sur la file', function () {
    Queue::fake();

    __JOB__::dispatch(1);

    Queue::assertPushed(__JOB__::class, function (__JOB__ $job) {
        return $job->modelId === 1;
    });
});

it('réessaie trois fois', function () {
    expect((new __JOB__(1))->tries)->toBe(3);
});
```

Résultat : quand je demande « crée-moi un job qui envoie la facture », l'assistant charge la skill, lance le script, et j'obtiens deux fichiers conformes au premier coup. Le `sed` ne fait pas de fautes de frappe ; il n'oublie jamais le test.

## Projet ou perso : où ranger la skill

L'emplacement n'est pas un détail. `.claude/skills/` est committé dans le dépôt : la skill est partagée avec toute l'équipe via git, elle évolue par pull request comme le reste du code, et chacun scaffolde des jobs identiques. C'est ça qui transforme une astuce personnelle en convention d'équipe.

À l'inverse, `~/.claude/skills/` est votre dossier personnel, hors dépôt : pratique pour vos outils transverses à tous vos projets, mais invisible pour vos collègues. La règle est simple : si la convention appartient au projet, elle va dans le dépôt.

## Quand une skill n'est *pas* le bon outil

Une skill reste une **suggestion** chargée dans le même contexte : l'assistant peut la suivre, ou pas. Trois cas où c'est le mauvais outil :

- **Vous voulez une garantie déterministe à chaque fois ?** Prenez un *hook*. Un hook s'exécute automatiquement sur un évènement (avant un commit, après une écriture de fichier), sans dépendre du bon vouloir de l'assistant. Pour « lancer Pint avant chaque commit », c'est un hook, pas une skill.
- **Vous voulez juste consigner un fait ?** « Les migrations vont dans `database/migrations`, on utilise MySQL » n'a pas besoin d'un dossier dédié : une ligne dans `CLAUDE.md` suffit, et elle est toujours présente dans le contexte.
- **Vous voulez isoler un gros travail exploratoire ?** Un sous-agent tourne dans son **propre contexte**, sans polluer le vôtre. Pour « audite toute la couche de paiement », c'est un sous-agent, pas une skill.

La skill brille pour une tâche récurrente, outillée, déclenchée à la demande. Hors de ce créneau, un autre mécanisme est plus adapté — et le reconnaître évite de tout transformer en skill.

## La check-list pour transformer un playbook en skill

Avant de coder une skill, passez le playbook au crible :

1. **C'est récurrent et identique ?** Sinon, ça ne mérite pas l'outillage.
2. **Une partie est purement mécanique ?** Mettez-la dans un `scripts/` exécuté verbatim, pas dans la prose.
3. **Le résultat suit un gabarit fixe ?** Sortez-le dans `assets/` plutôt que de le décrire.
4. **La `description` liste les vrais déclencheurs ?** C'est elle qui décide du chargement.
5. **La convention appartient au projet ?** Alors `.claude/skills/`, committé, partagé.
6. **Cherchez-vous une garantie, un simple fait, ou un contexte isolé ?** Alors c'est un hook, un `CLAUDE.md`, ou un sous-agent — pas une skill.

Le gain n'est pas la frappe en moins : c'est la convention qui ne dérive plus. Le jour où l'on passe `$tries` à 5, on modifie un template, on commite, et toute l'équipe scaffolde déjà la nouvelle version.
