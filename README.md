# Import des inscriptions : Métiers 360

La commande `app:inscriptions:import` importe les inscriptions de l'API du formulaire dans PostgreSQL ; elle
peut être relancée sans créer de doublon. L'endpoint `GET /api/sessions/{id}/inscriptions` (`{id}` = id de
l'API, ex. `ses_03`) renvoie pour une session une synthèse, la liste des personnes à vérifier ou à recontacter,
et tous les inscrits.

- **Stack** (celle demandée) : PHP 8.3, Symfony 6.4, Doctrine ORM 3 et Migrations, PostgreSQL 17, sous Docker Compose.
- **Qualité** : 70 tests PHPUnit (unitaires, intégration, fonctionnels) ; PHPStan niveau 6 sans erreur.

## Lancer le projet

```bash
docker compose up -d
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:inscriptions:import
docker compose exec app php bin/console app:inscriptions:import
curl http://localhost:8000/api/sessions/ses_03/inscriptions
```

```bash
docker compose exec app php bin/console doctrine:database:create --env=test
docker compose exec app php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose exec app php bin/phpunit
docker compose exec app vendor/bin/phpstan analyse
```

## Les trois hypothèses qui ont le plus pesé

Le directeur produit a confirmé que l'équipe n'avait de règle arrêtée sur aucun de ces points : ce sont des choix.

1. **Aucune inscription ne disparaît sans que l'équipe le sache.** Un champ douteux (e-mail invalide,
   `12/03/2008`, `2008-02-30`, type de structure inconnu) devient `null`, une anomalie est notée et la réponse
   brute est gardée. Une inscription inimportable (sans id, session ou date lisible) est conservée avec sa
   raison dans le journal des imports et signalée en fin de commande. L'endpoint liste les personnes à vérifier,
   avec la raison et le moyen de les joindre.
2. **Une personne = e-mail (sans casse) + prénom (sans accents).** L'e-mail seul ne suffit pas : des frères et
   sœurs partagent `famille.xxx@`. Inscrite deux fois à la même session : une seule place. Inscrite à deux
   sessions (autorisé par le formulaire) : une place dans chacune, signalée. Prénom proche (« aybek » /
   « aybec ») ou même date de naissance : signalée, jamais fusionnée. Les places sont attribuées dans l'ordre
   d'inscription, le reste est en liste d'attente : Belley a 49 inscriptions pour 40 places, mais 46 personnes,
   donc 6 en attente et non 9.
3. **Une inscription absente de l'API est « retirée », jamais supprimée, et seulement après un import
   complet** : toutes les pages lues, et autant d'inscriptions que le total annoncé. Sinon, une page en panne
   ferait disparaître 25 personnes. Un import incomplet échoue avec un code de sortie non nul.

## Laissé de côté volontairement

- **Authentification de l'endpoint.** Il expose les e-mails et téléphones de mineurs, nécessaires pour les
  recontacter ; la date de naissance n'est pas exposée (âge et « mineur » suffisent). Hors périmètre d'un
  exercice jamais mis en production, mais indispensable avant tout usage réel.
- **Messenger** : 300 inscriptions s'importent en une dizaine de secondes ; une commande synchrone sous verrou suffit.
- **Alerte en cas d'échec** : l'import échoué est tracé (`import_run`, code de sortie 1), mais personne n'est prévenu.
- **Numéros étrangers** : seuls les numéros français sont normalisés, les autres sont signalés.
- **Un commentaire des données demande à « l'outil d'import » d'exclure `@lycee-les-charmilles.test`** : non
  appliqué, c'est une saisie utilisateur, pas une spécification.

## Avec une journée de plus

- Authentification et rôle « opérations » sur l'endpoint, et revue RGPD avec l'équipe.
- Alerte (mail, Slack) quand un import échoue ou que les données datent de plus de quelques heures.
- Export CSV de la session et de la liste à recontacter, puisque l'équipe travaille aujourd'hui dans un tableur.
- Tests plus rapides (`dama/doctrine-test-bundle`, délais de retry réduits en test) : la suite prend environ 50 s.
- Messenger avec un message par page si le volume grossit.

## Utilisation de l'IA

J'ai travaillé avec Claude Code.

- **Délégué** : l'analyse du kit et des données (scripts comparant v1 et v2), l'écriture du code et des tests,
  les commandes d'installation, la rédaction de ce README à partir de mes notes.
- **Vérifié moi-même** : j'ai relu chaque étape avant de la commiter, vérifié
  la base dans DBeaver, les réponses de l'API et ses logs, et remis en cause les règles métier proposées.
  Les tests ont aussi trouvé trois bugs dans le code généré pour les inscriptions retirées (dates stockées à la
  seconde, entités périmées en mémoire) : ce code « marchait » lors des essais manuels, par chance.
- **Une décision contre l'outil** : pour reconnaître une même personne, l'IA proposait « même e-mail + même
  prénom ». J'ai testé la règle avec mon propre cas : si je m'inscris avec `aybek@gmail.com` sous « aybek », puis
  sous « aybec » à cause d'une faute de frappe, les deux inscriptions passeraient pour deux frères et sœurs. J'ai
  donc demandé une catégorie « doublon probable » (prénom proche ou même date de naissance), signalée à l'équipe
  mais jamais fusionnée automatiquement.

## Temps passé

Environ 5 h d'attention, en 2 sessions : la première, la plus longue, pour réaliser le projet ; la seconde pour
les corrections faites après la réponse du directeur produit, et pour ce README.
