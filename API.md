# API du formulaire d'inscription

Serveur simulant l'API de l'outil de formulaire externe qui collecte les inscriptions.

## Démarrer

Prérequis : Docker et Docker Compose.

```bash
docker compose up -d
curl 'http://localhost:8090/submissions?page=1'
```

Services lancés :

| Service | Accès depuis la machine | Accès depuis un autre conteneur du compose |
|---|---|---|
| API du formulaire | `http://localhost:8090` | `http://form-api:8090` |
| PostgreSQL 17 | `localhost:5432`, base `app`, utilisateur `app`, mot de passe `app` | `database:5432` |
| PHP 8.3 (Composer, `pdo_pgsql`, `intl`) | `http://localhost:8000` dès que `public/` existe | `http://app:8000` |

Le conteneur `app` monte le dossier du projet sur `/app` : `docker compose exec app composer …`,
`docker compose exec app php bin/console …`. Il ne sert `public/` que si ce dossier existait à son
démarrage : après avoir créé le projet Symfony, relancez-le (`docker compose restart app`). Vous pouvez
le modifier ou ajouter vos propres services (serveur web, worker…) à ce `docker-compose.yml`.

## `GET /submissions`

Liste les inscriptions au formulaire, de la plus ancienne à la plus récente.

### Paramètres

| Paramètre | Type | Défaut | Description |
|---|---|---|---|
| `page` | entier ≥ 1 | `1` | Numéro de page. 25 inscriptions par page. |

Une page au-delà de la dernière renvoie une liste vide.

### Réponse `200`

```json
{
  "form": {
    "id": "frm_7Hq2xL",
    "title": "Découvrir les métiers en réalité virtuelle — Ain, novembre 2026"
  },
  "data": [
    {
      "id": "sub_k3m9qz7tpe",
      "status": "submitted",
      "created_at": "2026-09-14T07:29:00Z",
      "updated_at": "2026-09-14T07:29:00Z",
      "answers": {
        "first_name": "Léa",
        "last_name": "Martin",
        "email": "lea.martin@example.com",
        "phone": "06 39 98 12 34",
        "birth_date": "2009-05-21",
        "structure_type": "lycee",
        "structure_name": "Lycée des Quatre-Vallées",
        "session": {
          "id": "ses_01",
          "label": "Bourg-en-Bresse — 17 novembre",
          "date": "2026-11-17",
          "location": "Espace des métiers, Bourg-en-Bresse",
          "capacity": 80
        },
        "comment": null
      }
    }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 293, "total_pages": 12 }
}
```

### Champs d'une inscription

| Champ | Description |
|---|---|
| `id` | Identifiant de la réponse au formulaire. |
| `status` | `submitted` ou `cancelled` (la personne a annulé son inscription depuis le lien reçu par mail). |
| `created_at`, `updated_at` | Date de soumission et de dernière modification, ISO 8601 UTC. |
| `answers.first_name`, `answers.last_name` | Prénom et nom. |
| `answers.email` | Adresse e-mail. |
| `answers.phone` | Téléphone, facultatif, saisie libre. |
| `answers.birth_date` | Date de naissance, `AAAA-MM-JJ`. |
| `answers.structure_type` | Type de structure qui accompagne la personne : `lycee`, `mission_locale` ou `france_travail`. |
| `answers.structure_name` | Nom de la structure. |
| `answers.session` | Session choisie dans le formulaire, avec son nombre de places (`capacity`). |
| `answers.comment` | Commentaire libre, facultatif. |

### Erreurs

| Code | Cas |
|---|---|
| `400` | Paramètre `page` invalide. |
| `5xx` | Erreur du service. |

Corps d'erreur :

```json
{ "error": "internal_error", "message": "An unexpected error occurred." }
```

## `GET /health`

Renvoie `{"status": "ok"}` si le service est démarré.
