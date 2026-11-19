# Architecture — Ziyada

> Référence technique, relevée dans le code. Ne décrit que ce qui existe ; ce qui est prévu est dans `docs/PLAN.md`. Les éléments marqués **(à supprimer)** viennent de `my-commerce` et sont retirés en phase 0.

## 1. Vue d'ensemble

Application Symfony 8 monolithique (PHP 8.4) : le storefront en Twig, l'administration en EasyAdmin, quelques endpoints JSON pour le panier, le paiement, les adresses et la recherche. Les tâches lentes (e-mails, alertes) passent par Messenger. Tout tourne dans Docker avec FrankenPHP (serveur Caddy intégré).

```
Navigateur ──HTTPS──> FrankenPHP/Caddy ──> Symfony (php)
                                              ├─ PostgreSQL 16 (service `database`)
                                              ├─ Messenger worker (service `messenger-worker`)
                                              ├─ Stripe (API + webhooks entrants)
                                              ├─ Colissimo (étiquettes, points relais, suivi)
                                              └─ e-mail (Mailpit en dev, SMTP en prod)
```

## 2. Organisation du code (`src/`)

| Dossier | Rôle |
|---|---|
| `Controller/` | Pages publiques (accueil, catégorie, produit, panier, checkout, compte, blog, FAQ, contact, pages, comparateur, liste de souhaits, alertes stock, sitemap/robots) |
| `Controller/Admin/` | Tableau de bord et CRUD EasyAdmin (produits, catégories, commandes, expéditions, factures, avis, médias, pages, blog, FAQ, sliders, transporteurs, moyens de paiement, paramètres, utilisateurs, journal d'audit…) |
| `Controller/Api/` | Endpoints JSON : adresses, commande, points relais, création du PaymentIntent Stripe |
| `Controller/Webhook/` | `StripeWebhookController` (`/webhooks/stripe`) |
| `Controller/Security/` | Connexion Google (OAuth) |
| `Service/` | Logique métier : `CartService`, `StockAllocator`, `RefundService`, `StripeService`, `PaymentConfigResolver`, `CompareService`, `WishlistService`, `MediaFileManager`, `EditorHtmlNormalizer` ; sous-dossiers `Carrier/` (Colissimo) et `Invoice/` |
| `Seo/` | `SeoResolver` (title, meta, Open Graph, JSON-LD), `SeoPayload`, `IndexabilityPolicy` |
| `Storefront/` | `StorefrontGlobalsProvider` : données communes du layout, mises en cache |
| `Twig/` | Extensions Twig (globals du storefront, contenu éditorial, notes des produits) |
| `Entity/`, `Repository/`, `Enum/` | Modèle de données (Doctrine), requêtes, états métier |
| `Message/`, `MessageHandler/` | Tâches asynchrones (e-mails transactionnels, notifications admin, alertes) |
| `EventListener/`, `EventSubscriber/` | Réactions aux événements (stock, médias, expédition, audit, panier, canonical) |
| `Security/` | `Authenticator`, `GoogleAuthenticator`, `EmailVerifier`, `AddressVoter` |
| `Command/` | Commandes console (stock, suivi Colissimo, images, création d'utilisateur) |
| `Form/`, `Dto/`, `Trait/` | Formulaires, objets d'entrée validés, comportements partagés |

Templates : `templates/` (layout `base.html.twig`, `header/`, `footer/`, `home/`, `product/`, `cart/`, `checkout/`, `account/`, `blog/`, `faq/`, `emails/`, `invoice/pdf.html.twig`, `admin/`…). Assets statiques : `public/assets/` (css, js, images, polices, owlcarousel, bootstrap). Favicons : `public/favicon/`.

## 3. Modèle de données

Entités principales (`src/Entity/`) :

- **Catalogue** : `Product`, `Category`, `Media` (images/vidéos), `Review`, `Wishlist`, `StockAlert`, `Sliders`, `ProductLot`.
- **Commande** : `Order`, `OrderDetails` (lignes), `Address`, `Carrier`, `PaymentMethod`, `Shipment`, `ShipmentStatus`, `Invoice`, `StripeWebhookEvent`.
- **Contenu** : `Blog`, `Page`, `Faq`, `FaqLink`, `Contact`, `Setting` (nom du site, adresse, TVA, mentions légales, seuil de livraison offerte, hero de la home, logo…).
- **Comptes et traçabilité** : `User`, `ResetPasswordRequest`, `AuditLog`.
- **À supprimer** : `HoneyTasting`, `WholesaleTier` ; champs de `Product` propres au miel (profil de dégustation, texture, couleur, notes aromatiques) et au grossiste (`wholesale*`).

Points structurants :
- Les **montants sont en centimes entiers** (`itemsTotalHtCents`, `taxAmountCents`, `orderTotalTtcCents`, `carrierPriceSnapshotCents`…) et la commande conserve des **instantanés** (nom et prix du transporteur, nom du moyen de paiement, point relais) pour rester exacte si le catalogue évolue.
- Les rôles : `ROLE_USER`, `ROLE_OPERATOR`, `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` (hiérarchie dans `security.yaml` ; `/admin` exige `ROLE_OPERATOR`).
- Les modifications sensibles sont tracées dans `AuditLog` (`AuditSubscriber`).

## 4. États métier

- **Paiement** (`PaymentStatus`) : `Attente`, `Paye`, `Rembourse`, `Echoue`, `Conteste`.
- **Préparation / expédition** (`FulfillmentStatus`) : `Brouillon`, `Preparation`, `Expedie`, `Livre`, `Annule`.
- **Colis** (`ShipmentStatusEnum`) : création, pris en charge, en transit, en livraison, livré, exception.
- **Avis** (`ReviewStatus`) : `Pending`, `Approved`, `Rejected` (modération avant publication).
- **Facture** (`InvoiceStatus`) : `Draft`, `Issued`, `Cancelled`.
- **Transporteur** (`CarrierType`) : `Colissimo` (API) ou `Manual` (suivi manuel).

## 5. Parcours de commande

1. **Panier** : stocké en session (et persisté pour un utilisateur connecté) ; routes `/cart/*`, géré par `CartService`.
2. **Checkout** (`GET /checkout`) : crée la commande en brouillon, **réserve le stock** (réservation de 30 min). La commande `app:stock:release-expired-reservations` (cron) libère les réservations abandonnées.
3. **Adresses, transporteur, point relais** : endpoints `Api/` ; montants et poids recalculés côté serveur.
4. **Paiement** : `POST /api/stripe/payment-intent/{orderId}` crée le PaymentIntent Stripe ; le client paie dans le navigateur.
5. **Confirmation par webhook** (`/webhooks/stripe`, signature vérifiée, idempotent grâce à `StripeWebhookEvent`). Événements gérés : `payment_intent.succeeded`, `.payment_failed`, `.canceled`, `.requires_action`, `charge.refunded` (partiel ou total), `charge.dispute.created` / `.closed`, alertes de fraude Radar. Chacun met à jour l'état de la commande et déclenche les e-mails ou notifications via Messenger.
6. **Page de retour** (`/stripe/payment/success`) : affiche le résultat d'après `paymentStatus` en base (jamais d'après l'URL) ; page d'attente à rafraîchissement automatique tant que le webhook n'est pas arrivé.
7. **Facture** : `InvoiceBuilder`, `InvoiceNumberGenerator`, `InvoicePdfRenderer` (Dompdf) ; téléchargeable depuis le compte client.
8. **Expédition** : depuis l'admin, `ShipmentService` génère l'étiquette (Colissimo domicile ou point relais) ou prépare un suivi manuel ; `OrderShippedListener` envoie l'e-mail d'expédition.
9. **Suivi** : `app:sync-shipment-tracking` (cron, toutes les 15 min) interroge Colissimo, enregistre les événements sans doublon, passe la commande à « livrée » et envoie l'e-mail correspondant ; page de suivi dans le compte client.
10. **Après-vente** : demande d'avis (e-mail après livraison), remboursement depuis l'admin (`RefundService`, restaure le stock une seule fois ; le webhook sert de filet idempotent).

Colissimo (`Service/Carrier/ColissimoClient`) : génération d'étiquettes (domicile et point de retrait), jeton du widget de points relais (mis en cache), recherche de points relais, suivi. Appels avec timeouts courts. Détails : `docs/colissimo-tracking.md`.

## 6. Couche SEO

- `SeoResolver` produit un `SeoPayload` (title, description, canonical, robots, Open Graph, JSON-LD) pour l'accueil, les catégories, les produits, le blog, la FAQ et les pages statiques. JSON-LD présent : `Organization`, `WebSite` (+ recherche), `WebPage`, `Product` (+ `Offer`, avis), `BreadcrumbList`, `CollectionPage`, `BlogPosting`, `FAQPage`.
- `IndexabilityPolicy` décide de l'indexation à partir de `SITE_INDEXABLE`, `APP_BASE_URL` et `TRUSTED_HOSTS` (si `SITE_INDEXABLE=true`, une configuration invalide lève une exception). Tant que le site n'est pas indexable, `IndexabilityPolicyResponseListener` ajoute `X-Robots-Tag: noindex, nofollow` à toutes les réponses (y compris JSON, sitemap, robots.txt). `CanonicalRouterContextListener` force le schéma et le host du routeur sur `APP_BASE_URL`, pour que les URLs générées (canonical, sitemap) ne dépendent pas du `Host` de la requête.
- `robots.txt` et `sitemap.xml` sont générés dynamiquement.
- Règles et audits : `docs/seo-guide.md`, `docs/audit-seo-*.md`.

## 7. Cache et performance

- **Globals du storefront** (`globalSetting`, `globalHeaderPages`, `globalFooterPages`, `globalMegaCategories`) : fournis par `StorefrontGlobalsProvider`, cache **taggé**, clés versionnées, invalidation automatique par `StorefrontGlobalsCacheInvalidationListener`. Aucune donnée publique en session, aucune entité Doctrine en cache.
- Images : variantes générées (`app:regenerate-image-variants`, `app:optimize-images`), nettoyage des orphelines (`app:clean-orphan-images`), en-têtes de cache longs sur les assets statiques dans le Caddyfile.

## 8. Sécurité en place

- Firewall Symfony, `Authenticator` (formulaire) et connexion Google ; `AddressVoter` pour l'accès aux adresses ; `/webhooks/stripe` et la connexion Google sont publics, `/admin` exige `ROLE_OPERATOR`.
- CSRF sur formulaires, endpoints AJAX mutables et actions admin sensibles (audit de juillet 2026).
- Contenu éditorial passé par un sanitizer HTML (`normalize_editor_html`) avant tout `|raw`.
- Limiteurs de débit (`config/packages/rate_limiter.yaml`) : webhook Stripe, formulaire de contact, connexion, recherche.
- reCAPTCHA v3 sur le contact, l'inscription et la connexion ; Sentry pour les erreurs.
- En-têtes HTTP (Caddyfile) : `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Strict-Transport-Security`. **Pas encore de Content-Security-Policy** ; `X-XSS-Protection` (obsolète) à retirer — voir phase 6 du plan.

## 9. Infrastructure

- `compose.yaml` : `php` (FrankenPHP), `messenger-worker`, `database` (PostgreSQL 16 Alpine). `compose.override.yaml` (dev) ajoute Mailpit et des cibles de développement ; `compose.prod.yaml` (prod) ajoute Netdata et les volumes d'uploads. Dockerfile en plusieurs cibles (`frankenphp_dev`, `frankenphp_prod`).
- Configuration par variables d'environnement (`.env` ne contient aucun secret). Sauvegardes vers un stockage objet via rclone (`scripts/backup.sh`, `docs/backup-strategy.md`).
- CI : `.github/workflows/ci.yaml` (PHPUnit + PHPStan sous PHP 8.4 ; un job Docker de démarrage dont certaines étapes sont désactivées).
- Tâches planifiées : `docs/cron-tasks.md`. Mise en production : `docs/mise-en-production.md`. Supervision : `docs/monitoring.md`.

## 10. Tests

PHPUnit, `tests/Unit/` : panier, allocation de stock et réservations, médias, webhook Stripe, indexabilité SEO, e-mails d'expédition et de remboursement, listeners. Les lacunes à combler sont listées en phase 6 du plan.
