# Répondeur — CRM WhatsApp et formulaire

Remplace un répondeur téléphonique : les clients écrivent sur WhatsApp ou par formulaire,
tout arrive dans un back-office unique, classé par client.
Symfony 8.1 / PHP 8.4-fpm / MariaDB 11.4 / Twig, servi par Docker Compose.

## Démarrer

```bash
make up        # php-fpm, nginx, mariadb, mailpit
make migrate
make admin <email> <mot-de-passe>
make test      # la base whatsapp_crm_test se crée avec :
               # docker compose exec -e APP_ENV=test php bin/console doctrine:database:create
```

- Page contact publique : http://localhost:8140/
- Back-office : http://localhost:8140/admin
- Courriels (Mailpit) : http://localhost:8026
- MariaDB : 127.0.0.1:3321

Ports non standard volontaires : la machine héberge beaucoup d'autres projets Docker.
Les commandes console passent par le conteneur (`make console <cmd>`).

## Tester sans Meta

Tant que l'application n'est pas en ligne, Meta ne peut pas joindre le webhook :

```bash
make console app:whatsapp:simulate 33612345678 "Bonjour" --name="Camille"
```

Sans `WHATSAPP_ACCESS_TOKEN`, les réponses sont enregistrées avec le statut « Simulé, non
envoyé ». Rien ne doit se prétendre envoyé alors que ce n'est pas le cas.

## Configuration

La config locale non secrète est dans `compose.yaml`. Les valeurs par défaut des variables
sont dans `config/services.yaml`. Les secrets vont dans `.env.local`, ignoré par git :

```dotenv
WHATSAPP_ACCESS_TOKEN=      # token permanent d'un utilisateur système Meta
WHATSAPP_PHONE_NUMBER_ID=   # ID du numéro, pas le numéro lui-même
WHATSAPP_APP_SECRET=        # sert à vérifier X-Hub-Signature-256
WHATSAPP_VERIFY_TOKEN=      # chaîne aléatoire, à recopier dans la config du webhook Meta
WHATSAPP_PUBLIC_NUMBER=     # numéro affiché : active le bouton sur la page contact
```

## Brancher Meta une fois en ligne

1. Remplir les variables ci-dessus sur le serveur.
2. Dans l'app Meta, section WhatsApp > Configuration : URL de rappel
   `https://<domaine>/webhook/whatsapp` et le `WHATSAPP_VERIFY_TOKEN`.
3. S'abonner au champ `messages` (il couvre aussi les statuts de livraison).
4. Vérifier que `WHATSAPP_API_VERSION` (`v24.0` par défaut) est encore supportée.

## Production

`ubuntu@51.38.187.88:/home/ubuntu/whatsapp-crm`, https://crm.v1.re7itroom.fr derrière Traefik.
Déployer : récupérer le code puis `./deploy.sh`. Les secrets sont dans `.env.prod` sur le
serveur (modèle : `.env.prod.example`). Après une modification de ce fichier :
`docker compose -f compose.prod.yaml --env-file .env.prod up -d`.

Le serveur manque de mémoire : garder les `mem_limit` de `compose.prod.yaml`.

## Règles métier à ne pas casser

- **Webhook** : `X-Hub-Signature-256` est obligatoire. Sans secret configuré, tout est refusé.
- **Doublons** : `Message.waMessageId` est unique, c'est ce qui rend les renvois de Meta sans
  effet. Sur une collision simultanée, l'exception n'est pas interceptée exprès : Meta réessaie.
- **Pas de worker** : tout est synchrone, comme dans arbre2026. Les envois Meta ont un
  timeout de 10 secondes.
- **Fenêtre de 24 h** : au-delà du dernier message client, Meta refuse le texte libre. Le
  formulaire de réponse disparaît. Les modèles validés ne sont pas encore gérés.
- **Bot** : message fixe, au premier contact puis après 12 h de silence (`Inbox::QUIET_PERIOD`).
  Le même seuil déclenche l'alerte par courriel. Il ne change jamais le statut du fil.
- **Lien privé** : un jeton par conversation, jamais par client. Détenir un lien ne donne
  accès qu'à ce fil, même si plusieurs demandes partagent la même adresse.
- **Formulaire** : données déclaratives, elles complètent une fiche et ne l'écrasent jamais.
- **Referrer-Policy** : `same-origin`, pas `no-referrer` qui enverrait `Origin: null` sur les POST.
