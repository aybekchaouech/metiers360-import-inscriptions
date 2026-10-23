# Exercice technique — développeur·se back-end / fullstack (Symfony)

## Le cadre

- **Effort : une demi-journée de votre attention, pas plus.** Le temps où un agent travaille seul ne
  compte pas ; comptez celui que vous passez à lire, cadrer, guider, relire et tester. Nous ne cherchons
  pas un rendu exhaustif. S'arrêter à temps et expliquer ce qui manque fait partie de l'exercice.
- **Délai de rendu : 7 jours** après réception du sujet, à organiser comme vous le souhaitez.
- **La spécification est volontairement incomplète.** C'est notre quotidien : le besoin arrive rarement
  sous une forme définitive. Vous pouvez nous poser des questions par mail à tout moment
  (réponse sous une demi-journée ouvrée ; pour les questions posées les 22 et 23 octobre, réponse le
  lundi 26). Vous pouvez aussi trancher vous-même : notez alors l'hypothèse prise.
- **Les assistants IA sont autorisés**, et même bienvenus : c'est notre façon de travailler. Dites-nous
  simplement comment vous les avez utilisés et ce que vous avez vérifié par vous-même.
- Ce code ne sera jamais utilisé en production. Il sert uniquement de support au débrief.

## Le contexte

Métiers 360 organise des **opérations** : des journées de découverte des métiers en casque de réalité
virtuelle, montées avec une région ou un réseau (lycées, missions locales, agences France Travail).
Chaque opération propose plusieurs **sessions** (date, lieu, nombre de places).

Les inscriptions sont collectées par un outil de formulaire externe, qui expose une API REST paginée.
Aujourd'hui, l'équipe opérations exporte un fichier à la main et le retravaille dans un tableur. Nous
voulons importer automatiquement ces inscriptions dans notre base, pour que l'équipe dispose d'une
vue fiable de chaque session.

## Ce que nous vous fournissons

Une archive de démarrage, à versionner vous-même dès le début, contenant :

- un `docker-compose.yml` avec PostgreSQL 17, un conteneur PHP 8.3 (Composer) et un **serveur simulant
  l'API du formulaire** ;
- la documentation de cette API (`GET /submissions?page=N`, pagination par numéro de page) ;
- un jeu de données d'environ 300 inscriptions réparties sur une opération et quatre sessions.

Le serveur simulé se comporte comme un vrai service tiers : les données ne sont pas parfaitement
propres et l'API n'est pas toujours disponible.

## Ce que nous vous demandons

1. **Une commande Symfony** `app:inscriptions:import` qui importe les inscriptions de l'API dans
   PostgreSQL. Elle doit pouvoir être relancée (en cron, par exemple) sans créer de doublons.
2. **Le modèle de données** (entités Doctrine et migrations) qui vous paraît adapté.
3. **Un endpoint** `GET /api/sessions/{id}/inscriptions` qui renvoie, pour une session, la liste des
   inscrit·es et une synthèse utile à l'équipe opérations.
4. **Des tests** sur ce qui vous paraît le plus risqué.

Stack attendue, celle de nos services en production : **PHP 8.3, Symfony 6.4 (LTS), Doctrine ORM 3
et Doctrine Migrations, PostgreSQL 17, PHPUnit**, le tout sous Docker Compose. Pour appeler l'API,
le composant HttpClient de Symfony suffit.

Le reste est à votre choix, à condition de l'argumenter. Pour information, nous utilisons
Symfony Messenger (avec des files de messages) pour les traitements asynchrones, et PHPStan
(niveau 6) pour l'analyse statique : les utiliser n'est pas obligatoire, mais c'est bienvenu.

## À rendre

- Un lien vers un dépôt Git (GitLab, GitHub) ou une archive, avec l'historique des commits.
- Un `README` qui remplace celui de l'archive (une copie de ce sujet). Nous le lisons avant le débrief
  pour préparer nos questions : écrivez-le pour quelqu'un qui le découvre et dispose de cinq minutes.
  La place est volontairement limitée, à vous de choisir ce qui mérite d'y figurer :
  - comment lancer le projet (les commandes, sans commentaire) ;
  - les **trois** hypothèses qui ont le plus pesé, là où la spécification était muette ;
  - ce que vous avez volontairement laissé de côté, et pourquoi ;
  - ce que vous feriez avec une journée de plus ;
  - comment vous avez utilisé l'IA : ce que vous avez délégué, ce que vous avez vérifié vous-même, et
    **une** décision prise contre une proposition de l'outil, la plus marquante ;
  - le temps d'attention que vous y avez consacré, et en combien de sessions.
- Si vous avez écrit des fichiers de contexte, des spécifications ou des consignes pour un agent IA
  (`CLAUDE.md`, `AGENTS.md`, prompts…), vous pouvez les joindre. C'est facultatif.

## La suite

Un débrief d'une heure en visio : vous nous présentez vos choix, puis nous faisons évoluer le besoin
ensemble, en direct, sur votre code. Pas de piège : nous voulons voir comment vous raisonnez sur
un sujet que vous connaissez déjà bien. Pendant cette partie, vous pouvez utiliser vos outils
habituels, assistants IA compris, en partageant votre écran.
