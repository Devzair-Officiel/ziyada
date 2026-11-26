# Plan du projet Ziyada — avancement

> **Dernière mise à jour : 2026-11-19.** Ce document est la référence de l'avancement. Il se met à jour **à chaque tâche terminée** (case cochée + ligne dans le [journal](#journal-davancement)).
> Légende : ✅ fait · 🟡 en cours · ⬜ à faire · ❓ à décider

## 1. Vision et objectifs

Mettre en ligne **ziyada.fr**, boutique B2C de produits bien-être naturels, avec :

1. **Un e-commerce complet** : catalogue, panier, commande, paiement **Stripe**, livraison **Colissimo**, factures, e-mails, compte client, admin.
2. **Un excellent référencement** : **SEO** (Google, Bing) et **GEO** (être cité et recommandé par les IA génératives : ChatGPT, Gemini, Perplexity, Claude…).
3. **Un code robuste, sécurisé, performant et de qualité**, qui suit les bonnes pratiques (tests, analyse statique, CI, revue).

Principe directeur : **pas de date butoir, la qualité d'abord** — chaque phase doit être irréprochable avant de passer à la suivante. Le travail se fait par petits chantiers, chacun sur une branche et une PR.

## 2. Décisions prises

| Date | Décision |
|---|---|
| 2026-11-19 | Marque **Ziyada**, domaine **ziyada.fr**. Boutique B2C de produits bien-être. |
| 2026-11-19 | Code issu de `my-commerce` (Nidemiel), copié **sans historique** dans le dépôt `ziyada`. |
| 2026-11-19 | **Pas de grossiste** : module à supprimer. |
| 2026-11-19 | Modules conservés : **blog, avis clients, comparateur de produits, liste de souhaits + alertes retour en stock**, FAQ, comptes clients, connexion Google. |
| 2026-11-19 | Modules « miel » à supprimer : guide sensoriel, carnet de dégustation, fiche de dégustation (`HoneyTasting`). |
| 2026-11-19 | Livraison **France métropolitaine uniquement** au lancement. |
| 2026-11-19 | Site en **français uniquement** (architecture à garder compatible avec d'autres langues plus tard). |
| 2026-11-19 | **Pas de date de lancement** : qualité d'abord. |
| 2026-11-19 | Design **cohérent avec les étiquettes** des produits (crème/kraft, bleu nuit, or, noir). Logo à fournir plus tard. |
| 2026-11-19 | **Workflow** : branche + PR depuis VS Code avec Claude Code, planification dans le Projet Claude, une discussion par phase ou sous-phase. |

## 3. Vue d'ensemble des phases

| Phase | Sujet | Statut |
|---|---|---|
| 0 | Fondations, nettoyage et documentation | 🟡 |
| 1 | Catalogue et modèle produit bien-être | ⬜ |
| 2 | Design et thème Ziyada | ⬜ |
| 3 | Commande, paiement, livraison, juridique | ⬜ |
| 4 | SEO technique et contenu | ⬜ |
| 5 | GEO (visibilité dans les IA) | ⬜ |
| 6 | Qualité, sécurité, performance | ⬜ |
| 7 | Mise en production | ⬜ |
| 8 | Post-lancement | ⬜ |

> Les phases 4, 5 et 6 se préparent dès le début (règles dans `CLAUDE.md`) mais leurs chantiers dédiés viennent après le catalogue et le design, qui produisent le contenu à référencer.

---

## Phase 0 — Fondations, nettoyage et documentation

- ✅ Copier `my-commerce` dans le dépôt `ziyada` sans historique (2026-11-19)
- ✅ Renommer la marque et les domaines Nidemiel → Ziyada / ziyada.fr (`.env`, Caddyfile, compose prod, SEO, scripts, tests, docs). Migrations non touchées (2026-11-19)
- 🟡 Documentation et instructions : `CLAUDE.md`, `docs/PLAN.md`, `docs/ARCHITECTURE.md`, `docs/PRODUIT-ET-THEME.md` — **version 1 rédigée, à valider**
- ✅ `.clinerules` remplacé par un renvoi vers `CLAUDE.md`, `STRUCTURE.md` (fichier vide) supprimé (2026-11-19)
- ✅ Méthode de travail documentée dans `CLAUDE.md` (rôles, branche + PR, jamais de commit direct sur `main`) (2026-11-19)

### 0.1 Socle de vérification

- ⬜ Lancer la suite de tests et PHPStan sous PHP 8.4 (Docker ou CI) et corriger ce qui casse après le renommage
- ⬜ Nettoyer la CI : le job Docker `tests` contient des étapes désactivées (`if: false`) héritées du squelette Symfony ; les activer (base de test, migrations, PHPUnit) ou les retirer

### 0.2 Retrait du grossiste

- ⬜ **Supprimer le grossiste** : `GrossisteController`, routes `/grossiste-miel*`, entité `WholesaleTier`, champs `isWholesaleAvailable` / `wholesale*` de `Product`, templates `templates/grossiste/`, éventuels écrans admin, entrées de sitemap/SEO

### 0.3 Retrait des modules « miel »

- ⬜ **Supprimer les modules « miel »** : guide sensoriel (contrôleur, `GuideSensorielDataProvider`, templates `guide/`, CSS/JS dédiés), carnet de dégustation (`TastingNotebookController`, templates, PDF), `HoneyTasting` (entité, CRUD admin, `HoneyTastingExtension`, `HoneyTastingSchema`, `HoneyTastingPdfRenderer`)

### 0.4 Base propre

- ⬜ **Migration initiale propre** : remplacer les 86 migrations héritées (dont le seed de 10 miels Nidemiel) par une migration unique générée depuis les entités sur une base vide
- ⬜ **Renommer les URLs « miel »** : `/miels/{slug}` → `/produits/{slug}` (à confirmer), `/faq-miel` → `/faq`, etc. Mettre à jour sitemap, canonicals, liens internes
- ⬜ Adapter la documentation héritée (`docs/*`) : retirer les exemples « miel », « Nidemiel », « my-commerce » (ex. `/etc/cron.d/my-commerce`)
- ❓ Sort de `ProductLot` et du « quality hold » (traçabilité par lot / DLUO, utile pour des compléments alimentaires) : garder, simplifier ou retirer ?

## Phase 1 — Catalogue et modèle produit bien-être

- ⬜ Valider l'arborescence des catégories (proposition dans `docs/PRODUIT-ET-THEME.md` : Huiles, Gélules et capsules, Gummies, Savons, Résines, Déodorants)
- ⬜ Refondre l'entité `Product` : retirer `tasting*`, `textureLabel`, `colorLabel`, `aromaticNotes`, `tastingSuggestion` ; décider du sort de `hasLabAnalysis` / `labAnalysis` (analyses de laboratoire, utiles en confiance et en GEO)
- ⬜ Ajouter les champs utiles au bien-être (à valider) : composition / ingrédients, mode d'emploi, précautions et contre-indications, contenance ou nombre d'unités, origine, labels et certifications, numéro de lot et date de durabilité
- ❓ Variantes de produit (ex. 60 / 120 gélules) : nécessaires au lancement ?
- ⬜ Saisir le catalogue initial dans l'admin (12 produits identifiés sur les visuels + déodorants, voir `docs/PRODUIT-ET-THEME.md`)
- ⬜ Normaliser les photos produit (carré, 1200 × 1200 px minimum), textes alternatifs, ordre de la galerie
- ⬜ Rédiger les fiches produit (réponse d'abord, factuelles, **sans allégation non validée**)

## Phase 2 — Design et thème Ziyada

- ⬜ Recevoir le logo (SVG ou PNG fond transparent) et fixer la charte (couleurs, typos) — voir `docs/PRODUIT-ET-THEME.md`
- ⬜ Définir les **variables de design** (couleurs, typographie, espacements, rayons) et les centraliser en CSS
- ⬜ Audit du CSS/JS hérité (`style.css` ≈ 146 Ko, `style.refactor.css` ≈ 384 Ko, jQuery UI ≈ 520 Ko) : supprimer l'inutile, viser un budget de poids
- ⬜ Refaire par ordre de priorité : base + en-tête/pied de page → accueil → catégorie → **fiche produit** → panier → checkout → compte client → pages de contenu (FAQ, blog, contact, légales) → pages d'erreur
- ⬜ Refaire les **e-mails transactionnels** (`templates/emails/`) et la **facture PDF** (`templates/invoice/pdf.html.twig`) aux couleurs de Ziyada
- ⬜ Accessibilité (cible WCAG 2.1 AA : contrastes, focus, labels, navigation clavier) et **mobile d'abord**
- ⬜ Favicons et manifeste (`public/favicon/`) avec le nouveau logo
- ⬜ Remplacer les médias hérités (image, vidéo et sons du site Nidemiel) par ceux de Ziyada ; décider du maintien des sons de l'interface (`public/assets/audios/`)

## Phase 3 — Commande, paiement, livraison, juridique

**Stripe**
- ⬜ Créer le compte Stripe de Ziyada ; clés **live uniquement par variables d'environnement** ; webhook de production configuré (`/webhooks/stripe`)
- ⬜ Revoir le flux de paiement de bout en bout (PaymentIntent, 3-D Secure, échec, abandon, page d'attente) avec des tests en mode test Stripe
- ❓ Moyens de paiement à activer au lancement (carte, Apple Pay / Google Pay, autres)
- ⬜ Réviser remboursements (partiels et totaux), litiges et alertes de fraude (déjà gérés dans le webhook) ; tests de non-régression

**Colissimo**
- ⬜ Ouvrir ou confirmer le contrat Colissimo de Ziyada et obtenir les identifiants (voir `docs/colissimo-tracking.md`)
- ⬜ Définir les **modes et tarifs de livraison France métropolitaine** (domicile, point relais ; barème au poids ; seuil de livraison offerte `freeShippingThresholdCents`)
- ⬜ Tester de bout en bout : choix du point relais, génération d'étiquette, suivi automatique (cron toutes les 15 min), e-mails d'expédition et de livraison

**Commande et facturation**
- ⬜ Vérifier TVA, numérotation des factures, facture PDF et envoi par e-mail
- ⬜ Vérifier le cycle de stock (réservation de 30 min, libération par cron, décrément, restauration)
- ⬜ Tests automatisés des règles de prix, panier, stock et statuts (voir phase 6)

**Juridique et conformité** *(à faire valider par un professionnel ; voir `docs/PRODUIT-ET-THEME.md`)*
- ⬜ Mentions légales (champs `ownerName`, `siret`, `legalMentions` de `Setting`), CGV, politique de confidentialité, politique de retours et droit de rétractation, médiation de la consommation
- ⬜ Bannière de consentement aux cookies et déclaration des services tiers (Stripe, Google, reCAPTCHA, Sentry, statistiques)
- ⬜ Conformité des produits : statut réglementaire de chaque produit (compléments alimentaires, cosmétiques), étiquetage, allégations

## Phase 4 — SEO technique et contenu

Socle déjà présent hérité : `SeoResolver` (JSON-LD Product, Organization, WebSite, FAQPage, BlogPosting, fil d'Ariane), `IndexabilityPolicy`, sitemap et robots dynamiques. Voir `docs/seo-guide.md` et les audits.
- ⬜ Définir les **gabarits de title et de meta description** par type de page, adaptés à Ziyada
- ⬜ Données structurées : fiche produit complète (offre, disponibilité, avis, marque, GTIN s'il existe), politique de retour et détails de livraison (éligibilité aux résultats marchands Google)
- ⬜ URLs propres, canonicals, `robots.txt` et sitemap vérifiés après le renommage ; pages à `noindex` (panier, compte, recherche, checkout…)
- ⬜ Maillage interne (catégories ↔ produits ↔ blog ↔ FAQ) et fil d'Ariane
- ⬜ Stratégie de mots-clés et **calendrier éditorial du blog**
- ⬜ Google Search Console, Bing Webmaster Tools, Google Merchant Center (fiches gratuites)
- ⬜ Core Web Vitals mesurés et tenus (LCP < 2,5 s, INP < 200 ms, CLS < 0,1)

## Phase 5 — GEO (visibilité dans les IA génératives)

- ⬜ Politique des **robots d'IA** dans `robots.txt` (OAI-SearchBot, GPTBot, PerplexityBot, ClaudeBot, Google-Extended…) : décision documentée dans `docs/audit-seo-phase3-1-report.md`, à vérifier sur le site Ziyada
- ⬜ Publier un fichier **`/llms.txt`** (absent du dépôt à ce jour) décrivant le site, les produits phares et les pages clés
- ⬜ Contenu **« réponse d'abord »** : pages produit, FAQ, guides, comparatifs qui répondent directement aux questions que posent les utilisateurs
- ⬜ Pages d'**autorité et de confiance** : à propos, sourcing et fabrication, analyses de laboratoire, politique qualité, auteur identifié des articles (E-E-A-T)
- ⬜ Données structurées enrichies et **cohérence de l'entité Ziyada** (Organization, `sameAs` réseaux sociaux, mêmes nom, adresse et description partout)
- ⬜ Présence et mentions externes (annuaires, fiches Google, avis vérifiés, presse/blogs) pour être citable
- ⬜ **Suivi de la visibilité IA** : jeu de requêtes de test relancé régulièrement (ChatGPT, Gemini, Perplexity, Claude…) et consigné

## Phase 6 — Qualité, sécurité, performance

**Sécurité**
- ⬜ Mettre en place une **Content-Security-Policy** (aujourd'hui commentée dans le Caddyfile) après inventaire des domaines tiers (Stripe, Colissimo, Google…)
- ⬜ Retirer l'en-tête obsolète `X-XSS-Protection` (recommandation de l'audit de code)
- ⬜ Étendre le **rate limiting** : existant sur webhook Stripe, contact, login, recherche ; à ajouter notamment sur alertes retour en stock, inscription, réinitialisation de mot de passe, création de PaymentIntent
- ⬜ `composer audit` dans la CI + mises à jour de dépendances automatisées (Dependabot ou équivalent)
- ⬜ Relecture sécurité complète avant ouverture (droits d'accès, CSRF, uploads, sanitisation HTML, en-têtes, cookies)

**Qualité du code et tests**
- ⬜ Monter PHPStan **progressivement du niveau 5 vers 8** et réduire les `ignoreErrors`
- ⬜ Ajouter un formateur (PHP-CS-Fixer ou équivalent, absent aujourd'hui) vérifié en CI
- ⬜ Étendre les tests : existants sur panier, stock, webhook Stripe, SEO (indexabilité), e-mails, médias ; à ajouter sur calcul des totaux et TVA, checkout, remboursements, facturation, client Colissimo (appels simulés), SEO (`SeoResolver`)
- ⬜ Tests de bout en bout du parcours d'achat (panier → paiement test → confirmation)

**Performance**
- ⬜ Budget de performance (poids de page, requêtes SQL par page) et mesure Lighthouse automatisée
- ⬜ Revue des requêtes Doctrine (N+1, index, pagination) sur accueil, catégorie, fiche produit et panier
- ⬜ Cache HTTP et en-têtes de cache des assets vérifiés

## Phase 7 — Mise en production

Guide détaillé : `docs/mise-en-production.md`.
- ⬜ Serveur et Docker en production (hébergement à confirmer)
- ⬜ DNS `ziyada.fr`, HTTPS, redirection `www` → domaine canonique (`CANONICAL_HOST`, `TRUSTED_HOSTS`, `APP_BASE_URL`)
- ⬜ E-mail : boîte `contact@ziyada.fr`, SPF / DKIM / DMARC (`docs/email-spf-dkim-dmarc.md`), `MAILER_DSN`
- ⬜ Stripe live + webhook ; Colissimo production ; Sentry ; sauvegardes vers le stockage distant (**test de restauration** inclus) ; supervision (UptimeRobot, Netdata)
- ⬜ Pages légales publiées, tests avant ouverture (commande réelle de bout en bout), puis `SITE_INDEXABLE=true`
- ⬜ Soumission du sitemap (Search Console, Bing)

## Phase 8 — Post-lancement

- ⬜ Suivi du référencement (positions, pages indexées, requêtes) et de la visibilité IA
- ⬜ Suivi des erreurs (Sentry), des performances et des conversions
- ⬜ Publication régulière (blog, FAQ) et collecte d'avis clients
- ⬜ Revue de sécurité et de dépendances périodique

---

## 4. Questions ouvertes

1. **Hébergement de production** : même type de serveur que `my-commerce` (VPS Docker, voir `docs/mise-en-production.md`) ou autre ?
2. **Statistiques / analytics** : outil respectueux de la vie privée sans bannière (ex. Matomo, Plausible) ou Google Analytics avec consentement ?
3. **Traçabilité par lot** : garder `ProductLot` / « quality hold » (numéros de lot, DLUO) ?
4. **Variantes** de produit (contenances, nombre de gélules) au lancement ?
5. **Déodorants** : références exactes, formats, visuels (aucun visuel fourni pour l'instant).
6. **Moyens de paiement** à activer en plus de la carte.
7. **Niveau PHPStan cible** et adoption d'un formateur de code.
8. **Statut réglementaire** de chaque produit (compléments alimentaires / cosmétiques) : qui le valide ?

## Journal d'avancement

| Date | Fait |
|---|---|
| 2026-11-19 | Dépôt `ziyada` créé. Import de `my-commerce` sans historique. Rebranding Nidemiel → Ziyada / ziyada.fr (38 fichiers). Cadrage du périmètre. Première version de la documentation et des instructions. |
| 2026-11-19 | Méthode de travail documentée (`CLAUDE.md` § 5) : Aurélien décide et fusionne, le Projet Claude planifie, Claude Code code en local sur branche + PR. Phase 0 découpée en sous-phases 0.1 à 0.4. Question ouverte « workflow Git » tranchée et retirée. |
