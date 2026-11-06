# Répondeur — CRM WhatsApp et formulaire

Remplace un répondeur téléphonique, pour plusieurs organisations (itroom, dufermont…). Chacune
a sa page publique `/<slug>` : WhatsApp ou formulaire avec lien privé. Son équipe traite tout
dans le back-office, classé par client, et ne voit jamais les données d'une autre organisation.
Symfony 8.1 / PHP 8.4-fpm / MariaDB 11.4 / Twig, servi par Docker Compose.

## Démarrer

```bash
make up        # php-fpm, nginx, mariadb, mailpit
make migrate
make console app:organization:create itroom "IT-Room" --notify=adresse@exemple.fr
make admin <email> <mot-de-passe> --organization=itroom   # ou --super : toutes les organisations
make test      # la base whatsapp_crm_test se crée avec :
               # docker compose exec -e APP_ENV=test php bin/console doctrine:database:create
```

- Page publique d'une organisation : http://localhost:8140/itroom
- Back-office : http://localhost:8140/admin
- Courriels (Mailpit) : http://localhost:8026
- MariaDB : 127.0.0.1:3321

Ports non standard volontaires : la machine héberge beaucoup d'autres projets Docker.
Les commandes console passent par le conteneur (`make console <cmd>`).

## Tester sans Meta

```bash
make console app:whatsapp:simulate itroom 33612345678 "#itroom Bonjour" --name="Camille"
```

Sans `WHATSAPP_ACCESS_TOKEN`, les réponses sont enregistrées avec le statut « Simulé, non
envoyé ». Rien ne doit se prétendre envoyé alors que ce n'est pas le cas.

## Configuration

La config locale non secrète est dans `compose.yaml`. Les valeurs par défaut des variables
sont dans `config/services.yaml`. Les secrets vont dans `.env.local`, ignoré par git :

```dotenv
WHATSAPP_ACCESS_TOKEN=      # même token que le hook : le numéro est partagé
WHATSAPP_PHONE_NUMBER_ID=   # ID du numéro, pas le numéro lui-même
WHATSAPP_RELAY_SECRET=      # partagé avec le hook, signe ce qu'il relaie
WHATSAPP_PUBLIC_NUMBER=     # numéro affiché : active le bouton des pages publiques
```

## Circuit WhatsApp

Un seul numéro et un seul webhook Meta pour tous les projets IT-Room : le hook
`immo.alesski.fr` (`/home/ubuntu/immo-sergic/whatsapp-sergic`, Sergic, Partenord, BDN).

- Entrant : `wa.me/<numéro>?text=#itroom ` préremplit le code. Le hook retient le lien entre
  ce téléphone et le code, et relaie le contenu Meta brut, signé (`X-Hub-Signature-256`,
  secret `WHATSAPP_RELAY_SECRET`), vers `POST /webhook/relay/<slug>`.
- Sortant : le CRM appelle directement l'API Graph avec le token du numéro.
- Le code `#itroom` est retiré du message. Un 404 signale au hook un code inconnu.

## Production

`ubuntu@51.38.187.88:/home/ubuntu/whatsapp-crm`, https://crm.v1.re7itroom.fr derrière Traefik.
Déployer : récupérer le code puis `./deploy.sh`. Les secrets sont dans `.env.prod` sur le
serveur (modèle : `.env.prod.example`). Après une modification de ce fichier :
`docker compose -f compose.prod.yaml --env-file .env.prod up -d`.

Le serveur manque de mémoire : garder les `mem_limit` de `compose.prod.yaml`.

## Règles métier à ne pas casser

- **Étanchéité** : toute lecture passe par l'organisation courante (`CurrentOrganization`). Une
  route avec un identifiant appelle `assertOwns()` : 404 si la fiche est à une autre organisation.
- **Relais** : la signature est obligatoire. Sans secret configuré, tout est refusé.
- **Doublons** : `Message.waMessageId` est unique, c'est ce qui rend les renvois sans effet.
  Sur une collision simultanée, l'exception n'est pas interceptée exprès : l'appelant réessaie.
- **Pas de worker** : tout est synchrone, comme dans arbre2026. Les envois Meta ont un
  timeout de 10 secondes.
- **Fenêtre de 24 h** : au-delà du dernier message client, Meta refuse le texte libre. Le
  formulaire de réponse disparaît. Les modèles validés ne sont pas encore gérés.
- **Bot** : message fixe, au premier contact puis après 12 h de silence (`Inbox::QUIET_PERIOD`).
  Le même seuil déclenche l'alerte par courriel. Il ne change jamais le statut du fil.
- **Lien privé** : `/<slug>/suivi/<jeton>`, un jeton par conversation, jamais par client. Détenir un lien ne donne
  accès qu'à ce fil, même si plusieurs demandes partagent la même adresse.
- **Formulaire** : données déclaratives, elles complètent une fiche et ne l'écrasent jamais.
- **Referrer-Policy** : `same-origin`, pas `no-referrer` qui enverrait `Origin: null` sur les POST.
