# CLAUDE.md — Ziyada (e-commerce bien-être)

> Instructions de référence pour travailler dans ce dépôt. **Avant toute tâche : lire `docs/PLAN.md`** (état d'avancement et prochaines étapes). **Après toute tâche : le mettre à jour** (case cochée + ligne dans le journal).

## 1. Le projet en bref

- **Ziyada** : boutique en ligne B2C de produits bien-être naturels — huile de nigelle, gélules et capsules, gummies, savons à saponification à froid, shilajit, déodorants. Domaine : `ziyada.fr`.
- Le code vient de `my-commerce` (boutique Nidemiel), copié **sans historique**. Marque et domaines sont renommés ; le **design, les textes et le modèle produit sont en cours de refonte** (voir le plan).
- **Objectifs** : vendre en ligne (Stripe + Colissimo), très bon référencement **SEO et GEO** (moteurs de recherche et IA génératives), code **robuste, sécurisé, performant, de qualité**, qui suit les bonnes pratiques.
- **Périmètre de lancement** : France métropolitaine, français uniquement, B2C. **Pas de vente en gros** (le module grossiste est à supprimer).
- Détails produit, marque et thème : `docs/PRODUIT-ET-THEME.md`. Architecture : `docs/ARCHITECTURE.md`.

## 2. Stack (vérifiée dans le dépôt)

- PHP 8.4 (`>=8.4.16`), Symfony 8.0, Doctrine ORM 3, PostgreSQL 16, FrankenPHP (Caddy).
- Front : Twig, Bootstrap 5, JavaScript « vanilla » + jQuery, assets servis depuis `public/assets/`. Appels asynchrones en `fetch`/AJAX.
- Admin : EasyAdmin 4 (`/admin`, rôles `ROLE_OPERATOR` < `ROLE_ADMIN` < `ROLE_SUPER_ADMIN`).
- Paiement : Stripe (PaymentIntents + webhooks). Livraison : Colissimo (étiquettes, points relais, suivi).
- Asynchrone : Symfony Messenger (transport Doctrine) avec le service `messenger-worker`.
- PDF : Dompdf (factures). Images : Intervention Image (variantes WebP).
- Divers : Sentry, reCAPTCHA v3, connexion Google (OAuth), Mailpit en dev, Netdata en prod.
- Qualité : PHPUnit, PHPStan niveau 5 (voir `phpstan.neon`), CI GitHub Actions (`.github/workflows/ci.yaml`).

## 3. Commandes (toujours via Docker, service `php`)

```bash
docker compose up -d                              # démarrer la stack de dev
docker compose exec php composer test             # PHPUnit
docker compose exec php composer analyse          # PHPStan
docker compose exec php php bin/console <cmd>     # console Symfony
docker compose exec php php bin/console doctrine:migrations:diff      # générer une migration
docker compose exec php php bin/console doctrine:migrations:migrate   # l'appliquer
docker compose exec php php bin/console doctrine:schema:validate      # cohérence entités / base
stripe listen --forward-to https://localhost/webhooks/stripe --skip-verify   # webhooks Stripe en local
```

Commandes applicatives : `app:create-user`, `app:stock:release-expired-reservations` (cron), `app:sync-shipment-tracking` (cron, suivi Colissimo), `app:regenerate-image-variants`, `app:optimize-images`, `app:clean-orphan-images`. Planification : `docs/cron-tasks.md`.

> Si l'environnement de travail n'a pas PHP ≥ 8.4.16 (ou pas Docker), **ne pas prétendre avoir lancé les tests** : le dire, vérifier la syntaxe (`php -l`) et s'appuyer sur la CI.

## 4. Règles non négociables

### Honnêteté et méthode
- **Ne jamais inventer** fichiers, routes, services, entités, champs, variables d'environnement ou comportements. En cas de doute, lire le code ou **poser une question ciblée avant de coder**.
- Travailler par **petits incréments** testables et faciles à relire. Préférer le standard Symfony aux patterns maison.
- Livrer : fichiers modifiés, commandes pour tester, impacts (base/migrations, sécurité, SEO, perf).

### Code PHP / Symfony
- PSR-12 + PSR-4, `declare(strict_types=1);`, typage strict. Contrôleurs fins, logique métier dans des services. Pas de duplication.
- Toute entrée utilisateur passe par le Validator ou un formulaire/DTO.
- Journaliser avec Monolog **sans données sensibles** (pas de clés, tokens, données de carte).

### Sécurité (priorité e-commerce)
- **CSRF** sur toute action qui modifie un état (formulaires, endpoints AJAX, actions admin sensibles). **Jamais de mutation par GET.**
- Accès : voters / `#[IsGranted]` sur toute route sensible ; un client ne lit que **ses** commandes, adresses, factures.
- Doctrine : paramètres liés uniquement, **aucune concaténation SQL**. Twig : échappement par défaut ; `|raw` **uniquement** sur du contenu passé par le sanitizer (`normalize_editor_html`).
- Secrets (Stripe, Colissimo, Google, reCAPTCHA, Sentry, S3…) **uniquement en variables d'environnement**, jamais en code, en logs ni dans un fichier commité. Ne pas stocker de clés **live** en base.
- Uploads : validation MIME et taille, noms de fichiers sûrs.
- Limiter le débit des endpoints publics (login, contact, recherche, webhooks, alertes stock…) avec le composant Rate Limiter.

### Paiement (Stripe) — règles critiques
- **Un paiement n'est jamais « validé » sur la foi du navigateur.** La source de vérité est le **webhook** vérifié par signature (`STRIPE_WEBHOOK_SECRET`).
- Handler **idempotent** : chaque événement Stripe est enregistré (entité `StripeWebhookEvent`, contrainte d'unicité) ; un rejeu ne doit jamais créer ni modifier deux fois la même commande.
- Montants **toujours recalculés côté serveur** depuis la base ; en **centimes entiers** (champs `*Cents`), jamais en flottants. Prix, remises, stock et statut ne se décident jamais côté client.
- Statuts via les enums `PaymentStatus` (`Attente`, `Paye`, `Rembourse`, `Echoue`, `Conteste`) et `FulfillmentStatus` (`Brouillon`, `Preparation`, `Expedie`, `Livre`, `Annule`). Transitions pilotées par le back (webhook, admin), jamais par le client.
- Remboursement partiel ≠ remboursement total. Stock : réservation (30 min) puis décrément ; les remboursements restaurent le stock une seule fois.

### Livraison (Colissimo)
- Appels API avec **timeout** (déjà : 5 s / 10 s). Un échec Colissimo ne doit **jamais** casser le checkout : dégrader proprement.
- Poids de commande calculé côté serveur ; point relais mémorisé dans la commande (`pickupPointSnapshot`).

### Base de données
- **Toute modification de schéma passe par une migration Doctrine.** Ne jamais modifier une migration déjà appliquée en production.
- Listes paginées (admin compris). Index sur les colonnes filtrées/triées. Transactions explicites quand plusieurs écritures sont liées (commande + lignes + stock).

### Performance
- Objectifs Core Web Vitals (seuils « bon » de Google) : **LCP < 2,5 s, INP < 200 ms, CLS < 0,1**.
- Images : WebP/variantes générées, dimensions explicites, `loading="lazy"` hors du premier écran, image LCP prioritaire. Pas de librairie JS/CSS ajoutée sans nécessité ; supprimer ce qui est inutilisé.
- Pas de requête répétée à chaque page (utiliser le cache taggé des « globals » du storefront). Éviter le N+1.

### SEO et GEO (SEO + visibilité dans les IA)
- Toute page publique a : `<title>` et meta description uniques, canonical, un seul `<h1>`, Open Graph, données structurées JSON-LD adaptées, fil d'Ariane. Passer par `SeoResolver` / `SeoPayload` plutôt que du HTML ad hoc.
- Contenu **répondant directement aux questions** (réponse d'abord, détails ensuite), factuel, sourcé, daté. Pas de contenu dupliqué ni de texte inventé.
- L'indexation est pilotée par `IndexabilityPolicy` : `SITE_INDEXABLE=false` partout sauf la prod publique.
- Référence détaillée : `docs/seo-guide.md` et les rapports d'audit SEO.

### Allégations santé et conformité (produits bien-être)
- **Aucune allégation de santé, d'efficacité ou de résultat** (ex. « éclaircissant », « anti-âge », « booste… », « soigne… ») sans validation préalable. Voir la section dédiée de `docs/PRODUIT-ET-THEME.md`. En cas de doute, **ne pas écrire l'allégation** et poser la question.

## 5. Conventions de travail

- **Une branche par chantier** (`feat/…`, `fix/…`, `docs/…`, `chore/…`), pull request vers `main`, **CI verte** (PHPUnit + PHPStan) avant fusion. Commits en français, courts et explicites (`feat: …`, `fix: …`).
- Ne jamais committer `.env.local`, de clés ou de dumps de base. `.env` ne contient que des valeurs vides ou non sensibles.
- Textes de l'interface, de l'admin et de la doc en **français**. Noms de code en anglais (convention existante : certains enums sont en français, ne pas renommer sans raison).
- Pas de refactor « au passage » : un sujet par PR.

## 6. Définition de « terminé »

- [ ] Le comportement demandé fonctionne, y compris les cas d'erreur (réseau, 4xx/5xx, double soumission).
- [ ] Tests ajoutés ou mis à jour pour toute règle métier touchée (prix, panier, stock, statuts, webhook) ; un bug corrigé = un test de non-régression.
- [ ] `composer test` et `composer analyse` passent (ou impossibilité d'exécuter signalée).
- [ ] Sécurité relue (CSRF, droits d'accès, échappement, secrets).
- [ ] SEO/GEO relu si une page publique change (title, meta, canonical, JSON-LD, liens internes).
- [ ] Migration fournie si le schéma change ; impacts de déploiement notés.
- [ ] `docs/PLAN.md` mis à jour ; documentation touchée corrigée.

## 7. Héritage de `my-commerce` à connaître

- URLs encore « miel » (`/miels/{slug}`, `/faq-miel`, `/guide-sensoriel-miel`, `/carnet-degustation-miel`, `/grossiste-miel`) : **à renommer ou supprimer** (voir plan).
- L'entité `Product` contient des champs propres au miel (profil de dégustation, texture, couleur, notes aromatiques…) et des champs grossiste : **à remplacer** par un modèle adapté au bien-être.
- `PaymentMethod` stocke des clés Stripe **de test** en base : ne jamais y mettre de clés live (la production passe par l'environnement).
- Les migrations (86) et leur contenu Nidemiel seront remplacés par une migration initiale propre.
- Certains documents de `docs/` contiennent encore des exemples « miel » : à adapter au catalogue Ziyada.

## 8. Carte de la documentation

| Document | Contenu |
|---|---|
| `docs/PLAN.md` | **Plan, phases, avancement, décisions, questions ouvertes** |
| `docs/ARCHITECTURE.md` | Structure du code, modèle de données, flux commande/paiement/livraison |
| `docs/PRODUIT-ET-THEME.md` | Marque, catalogue, identité visuelle, ton, conformité des allégations |
| `docs/seo-guide.md` + `docs/audit-seo-*.md` | Règles SEO et rapports d'audit (politique robots/IA incluse) |
| `docs/product-lifecycle-seo.md` | Cycle de vie des produits et SEO |
| `docs/mise-en-production.md` | Déploiement pas à pas |
| `docs/cron-tasks.md`, `docs/colissimo-tracking.md` | Tâches planifiées, suivi Colissimo |
| `docs/backup-strategy.md`, `docs/monitoring.md`, `docs/email-spf-dkim-dmarc.md` | Sauvegardes, supervision, e-mail |
| `docs/audit-code-2026-07-07.md` | Audit de code hérité (correctifs et recommandations restantes) |
