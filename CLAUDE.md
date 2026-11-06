# Ma Relation Clients — CRM WhatsApp et formulaire

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

- Présentation de l'outil : http://localhost:8140/
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

- Entrant : `wa.me/<numéro>?text=…` préremplit une phrase contenant `(#itroom)`, à envoyer telle
  quelle d'abord. Il reçoit le message automatique de l'organisation, et l'alerte part sur sa question. Un client déjà
  rattaché ne change d'organisation qu'avec la forme `(#code)`. Le hook retient le lien entre
  ce téléphone et le code, et relaie le contenu Meta brut, signé (`X-Hub-Signature-256`,
  secret `WHATSAPP_RELAY_SECRET`), vers `POST /webhook/relay/<slug>`.
- Sortant : le CRM appelle directement l'API Graph avec le token du numéro.
- La phrase et le code sont retirés du message. Un 404 signale au hook un code inconnu.
- Côté hook : `CrmRouter` (table Postgres `crm_route`), `CrmRelayMessageHandler`, file
  `crm_relay` avec relances pendant environ 4 heures. Un client y reste sans limite de durée,
  et revient aux bots s'il tape le code d'un projet (`sergic`). Toute panne de routage retombe
  sur les bots.
- Vocaux : le hook les transcrit (Whisper, clé OpenAI dans son `app_config`) et ajoute le champ
  `transcription` au message relayé. Si cela échoue, le message arrive sans. L'audio n'est pas gardé.
  Le CRM renvoie le texte compris au client, même bot désactivé.
- Coches bleues : à l'ouverture de la conversation dans l'admin (`Inbox::markAsRead`), pas à la
  réception. Le hook ne le fait pas pour les clients relayés.
- Numéro partagé : +33 7 66 67 35 58. Si le token du hook change, le recopier dans `.env.prod`.

## Production

`ubuntu@51.38.187.88:/home/ubuntu/whatsapp-crm`, https://ma-relation-clients.fr derrière Traefik.
`www` et l'ancienne adresse crm.v1.re7itroom.fr redirigent, sauf `/webhook/` sur cette dernière : le
hook y relaie encore (`CRM_RELAY_URL` dans son `.env`). Ne pas retirer cette exception sans changer le hook.
Déployer : récupérer le code puis `./deploy.sh`. Les secrets sont dans `.env.prod` sur le
serveur (modèle : `.env.prod.example`). Après une modification de ce fichier :
`docker compose -f compose.prod.yaml --env-file .env.prod up -d`.

Le serveur manque de mémoire : garder les `mem_limit` de `compose.prod.yaml`.

## Règles métier à ne pas casser

- **Production** : ne jamais supprimer de données, même de test, sans accord explicite. Tester
  en local. Il n'y a pas encore de sauvegarde de la base.
- **Super-admin** : lui seul gère organisations et comptes (`/admin/organisations`). L'adresse
  d'une organisation ne change plus après création : c'est aussi son code WhatsApp.
- **Identité** : couleurs et logo par organisation, sur l'admin et les pages publiques. Le logo
  est en base (`OrganizationLogo`) car l'image de production n'a pas de volume. Pas de SVG : il
  peut embarquer du script. La couleur du texte se calcule pour rester lisible.
- **Messagerie** : chaque organisation a sa boîte OVH (`OrganizationTransport`), qui est aussi
  l'expéditeur, OVH refusant une autre adresse. Sans boîte, retour au `MAILER_DSN` global. Le mot
  de passe est chiffré (`SecretBox`, clé dérivée d'APP_SECRET) : changer APP_SECRET impose de le
  ressaisir. L'expéditeur par défaut se règle dans `/admin/organisations` (`MailSettings`) ;
  `MAIL_FROM` n'est que la valeur de départ.
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
  Il ne change jamais le statut du fil.
- **Conversation clôturée** : plus de zone de saisie dans l'admin ni sur le lien de suivi, et le
  serveur refuse aussi l'écriture. Un bouton rouvre. Sur WhatsApp, le client ne peut pas être
  empêché d'écrire : son message rouvre la conversation.
- **Déploiement** : `Dockerfile.prod` installe les dépendances avant de copier le code, pour ne pas
  retélécharger depuis GitHub à chaque fois. Garder cet ordre.
- **Pages d'erreur** : `templates/bundles/TwigBundle/Exception/error.html.twig` pour Symfony (à
  voir en dev sur `/_error/404`), `public/erreur.html` servie par nginx quand PHP est tombé.
- **Mesure d'audience** : GTM n'est chargé qu'après accord (`_consent.html.twig`, 6 mois). À n'inclure
  que sur les pages vitrine et `/itroom`, jamais sur l'admin ni un lien de suivi, dont l'adresse
  contient le jeton privé. Toute évolution doit se refléter dans `/confidentialite`.
- **CSS** : incrémenter `assets_version` (`config/packages/twig.yaml`) à chaque modification, sinon les
  navigateurs gardent l'ancienne feuille de style.
- **Dernière réponse** : deuxième bouton de l'admin. Le client se voit alors proposer de clôturer
  ou de continuer : boutons WhatsApp (`WhatsAppClient::sendTextWithChoice`, texte limité à 1024
  caractères par Meta) ou bloc sur le lien de suivi (`Conversation::awaitingClosure`). Le lien de
  suivi garde en plus un bouton de clôture permanent. L'équipe est prévenue d'une clôture ;
  « Continuer » ne change rien tant que le client n'a pas écrit.
- **Messages par défaut** : la fiche d'une organisation les affiche depuis les constantes de
  `Inbox` et les vrais modèles de courriel. Un nouveau texte automatique s'y ajoute
  (`OrganizationController::defaultMessages`).
- **Alertes** : chaque message client prévient tous les comptes de l'organisation et son adresse
  d'alerte, sauf la phrase d'ouverture WhatsApp. L'envoi se fait après la réponse à la requête
  (`Notifier::sendPending`), jamais pendant : un SMTP lent bloquerait le client et le relais.
- **Lien privé dans l'admin** : affiché dans la conversation pour l'équipe de l'organisation,
  avec l'avertissement qu'écrire depuis ce lien revient à écrire comme le client.
- **Lien privé** : `/<slug>/suivi/<jeton>`, un jeton par conversation, jamais par client. Détenir un lien ne donne
  accès qu'à ce fil, même si plusieurs demandes partagent la même adresse.
- **Formulaire** : données déclaratives, elles complètent une fiche et ne l'écrasent jamais.
- **Referrer-Policy** : `same-origin`, pas `no-referrer` qui enverrait `Origin: null` sur les POST.
