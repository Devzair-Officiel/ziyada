# Audit SEO / GEO — Phase 3 : validation pré-production

**Date** : 2026-07-13
**Environnement testé** : Docker Compose local basculé en `APP_ENV=prod`
avec `SITE_INDEXABLE=true` + `APP_BASE_URL=https://nidemiel.com`.
**Ingénieur** : audit assisté (Claude Opus 4.7) sur demande utilisateur.
**Portée** : blocage de sortie sur toutes les défauts SEO/GEO critiques
identifiés dans les phases 1 et 2, plus vérification frontale, sécurité
d'hôte, cohérence des prix et scripts d'audit continu.

---

## Verdict global

> **GO conditionnel** — le socle SEO/GEO est prêt pour la mise en ligne.
> Les 3 conditions listées en fin de rapport doivent être exécutées lors
> du déploiement (nettoyage `.env.dev.local`, réinjection du bloc
> canonical redirect côté Caddy en production, contrôle éditorial de la
> fiche Manuka MGO).

## A. Politique d'indexabilité

### A.1 IndexabilityPolicy — règles renforcées

`src/Seo/IndexabilityPolicy.php` applique désormais les invariants
suivants au boot :

- `SITE_INDEXABLE=false` → aucune contrainte, fallback sur la requête
  autorisé (utile en dev, preview, staging).
- `SITE_INDEXABLE=true` → `APP_BASE_URL` **obligatoire** et validé :
  - schéma **HTTPS** exclusivement,
  - refus explicite de `localhost`, `127.0.0.1`, IP privées RFC1918,
    hostnames en `.preview.`, `.staging.`, `.local`, `.test`,
  - trailing slash retiré normalisation stricte,
  - erreur au boot si vide.

Tests unitaires : `tests/Unit/Seo/IndexabilityPolicyTest.php` — 100 %
scénarios positifs et négatifs (HTTPS OK, HTTP KO, IP privée KO,
localhost KO, staging.subdomain KO, empty KO).

### A.2 Résolution de l'URL de base

`SeoResolver::toAbsoluteIfNeeded()` et `SeoResolver::forHome()` utilisent
`IndexabilityPolicy::getBaseUrl()`, jamais `Request::getSchemeAndHttpHost()`
directement. Un fallback sur la requête reste actif uniquement quand
`APP_BASE_URL` est vide (dev/preview).

### A.3 CanonicalRouterContextListener (nouveau)

Fichier : [src/EventListener/CanonicalRouterContextListener.php](../src/EventListener/CanonicalRouterContextListener.php).

Listener `kernel.request` priorité 20 (juste après `RouterListener`)
qui force `scheme` + `host` du contexte du routeur à
`APP_BASE_URL`. Ainsi toute route générée en `ABSOLUTE_URL`
(canonicals, sitemap, JSON-LD, OG, twig `absolute_url()`) utilise le
domaine canonique et jamais l'en-tête `Host` de la requête entrante,
même si un proxy relayait un `Host` inattendu.

## B. Protection contre l'injection de Host

### B.1 `framework.trusted_hosts`

`config/packages/framework.yaml` :

```yaml
trusted_hosts: '%env(default::string:TRUSTED_HOSTS)%'
```

Prend une regex Symfony native (une seule chaîne, alternatives via `|`).
Test réel :

```bash
TRUSTED_HOSTS='^(nidemiel\.com|www\.nidemiel\.com|localhost)$'
curl -H "Host: evil.example" https://localhost/         # → 400
curl -H "Host: nidemiel.com.evil" https://localhost/    # → 400
curl -H "Host: prep.nidemiel.com" https://localhost/    # → 400
curl -H "Host: www.nidemiel.com" https://localhost/     # → 200
curl -H "Host: nidemiel.com" https://localhost/         # → 200
```

Ancrages `^…$` corrects, aucun contournement possible via suffixe/préfixe.

### B.2 Défense en profondeur (Caddy)

Le `Caddyfile` gère les `@nonCanonicalHost` de redirection **côté prod
uniquement** (bloc retiré du fichier dev suite à un bug de matcher CEL —
voir condition #2 en fin de rapport).

## C. Cohérence des prix

Audit des usages historiques de `regularPrice`, `soldePrice`,
`isOnSale`, et `getEffectivePrice` :

| Emplacement                          | Avant                                | Après                                 |
| ------------------------------------ | ------------------------------------ | ------------------------------------- |
| `CartService::addProduct`            | Ternaire manuel                      | `getEffectivePrice()`                 |
| `CompareService::compare`            | Ternaire manuel                      | `getEffectivePrice()`                 |
| `ProductController::getProduct` JSON | Ternaire manuel                      | `getEffectivePrice()`                 |
| `WishlistService::getPayload`        | `isOnSale = soldePrice !== null` (bug) | Champs complets + `getEffectivePrice` |
| `templates/checkout/index.html.twig` | `soldePrice ?? regularPrice`         | `item.product.displayPrice`           |

Source unique de vérité : `Product::getEffectivePrice()` qui applique
`isOnSale() ? soldePrice : regularPrice`. Aucun autre code chargé de
"choisir" un prix — Stripe, panier, commandes, emails et JSON-LD
consomment tous cette méthode ou une valeur déjà calculée.

Suite de tests : 89 tests, 179 assertions, tous verts. `CartServiceTest`
a été mis à jour pour mocker `getEffectivePrice()`.

## D. Scripts d'audit continu

### D.1 `scripts/sitemap-audit.php`

Script PHP CLI qui :

- parse le sitemap XML retourné par une URL absolue,
- pour chaque `<loc>`, vérifie HTTP 200 (redirects → anomalie), scan
  `meta robots noindex`, `X-Robots-Tag: noindex`, canonical vers un
  autre domaine, contamination `Host` header,
- produit `var/audit/sitemap-audit-{timestamp}.csv` et `.md`,
- exit code 1 dès qu'une anomalie est trouvée.

Usage : `php scripts/sitemap-audit.php https://nidemiel.com/sitemap.xml`.

### D.2 `scripts/seo-smoke-test.sh`

Script Bash qui :

- vérifie que le serveur répond,
- inspecte `robots.txt` (règles OAI-SearchBot, ligne `Sitemap:`),
- vérifie `meta robots` sur `/` et cohérence avec `SITE_INDEXABLE`,
- prouve la résistance à l'injection de Host header
  (via `Host: attacker.example`),
- vérifie que `sitemap.xml` est un urlset XML valide et non pollué.

Exit 1 dès qu'une assertion échoue. Exécution locale finale :
**8 vérifications / 0 échec**.

## E. Frontal, contrôleurs, robots

### E.1 Contrôleurs vérifiés en HTTP réel

Container en `APP_ENV=prod` :

| Route             | Statut | Notes                                        |
| ----------------- | :----: | -------------------------------------------- |
| `/`               |  200   | Meta robots `index,follow`                   |
| `/miels/{slug}`   |  200   | Ligne existante ; 404 réel si absente        |
| `/comparateur`    |  200   | Anciennement en 500 (phase 2) — corrigé      |
| `/grossistes`     |  200   | Rendu OK, contenu éditorial à enrichir       |
| `/carnet`         |  200   | Accès admin correct                          |
| `/contact`        |  200   | Formulaire présent, reCAPTCHA v3 actif       |
| `/sitemap.xml`    |  200   | urlset valide, 24 URLs, toutes en HTTPS      |
| `/robots.txt`     |  200   | Voir E.2                                     |

### E.2 `robots.txt`

En mode `SITE_INDEXABLE=true` :

```
User-agent: *
Allow: /
Disallow: /admin /account /login /register /logout /reset-password
Disallow: /cart /checkout /stripe/
Disallow: /compare /wishlist /verify/ /connect/
Disallow: /api/ /product/get/ /product/search /webhooks/
Sitemap: https://nidemiel.com/sitemap.xml
```

- **Aucun bot IA n'est bloqué explicitement** : `OAI-SearchBot`,
  `GPTBot`, `PerplexityBot`, `Google-Extended`, `ChatGPT-User` tombent
  sous `User-agent: *` avec `Allow: /` — comportement voulu pour la
  visibilité GEO (LLM answer engines).
- La ligne `Sitemap:` utilise l'URL canonique (jamais le Host de la
  requête).

En mode `SITE_INDEXABLE=false` : contenu réduit à
`User-agent: *` + `Disallow: /`. Vérifié par le smoke test.

### E.3 Blocage éditorial identifié — Manuka MGO

La fiche Manuka MGO utilise à ce jour le label français « Miel de
Manuka » sans mention explicite d'origine ni de certification MGO
vérifiée. Ne pas modifier tant que l'équipe éditoriale n'a pas fourni
un texte source. **Flag éditorial**, pas un blocage technique.

## F. Sécurité et environnement de test

- Container `my-commerce-php-1` reconstruit en dev (bind-mount Caddyfile
  restauré après un crash lié à un inode stale du fichier).
- Symfony 8.0.2 + PHP 8.4.20 + FrankenPHP 1.12.2 + Caddy 2.11.2.
- Cache prod chauffé, autoload optimisé (`composer.json` déclare
  `preferred-install: dist`).
- Aucun secret n'a été committé pendant l'audit. `.env.prod.local` créé
  puis annoté comme non-versionné.

## G. Cycle de vie produit / SEO

Nouveau document dédié : [product-lifecycle-seo.md](product-lifecycle-seo.md).

Résumé :

- `stock = 0` → JSON-LD `OutOfStock`, sitemap conservé (rupture ≠ retrait).
- `isAvailable = false` → sitemap exclu, page HTTP 200 conservée.
- `isSeoNoindex = true` → `<meta noindex>` + sitemap exclu.
- Suppression DB → 404 réel (soft-404 impossible : le contrôleur
  retourne `Response::HTTP_NOT_FOUND`).
- Renommage / fusion → redirect 301 à ajouter manuellement dans le
  `Caddyfile` (pas de mécanisme automatique, non requis pour lancement).

---

## Conditions de mise en production

1. **Nettoyer `.env.dev.local`** — retirer le bloc temporaire
   `###> SEO test prod ###` avant de repartir en mode dev. À faire
   immédiatement après lecture de ce rapport.
2. **Restaurer le bloc `@nonCanonicalHost`** dans le `Caddyfile` **côté
   VPS de production uniquement**, sous forme d'un site block séparé
   (`www.nidemiel.com { redir https://nidemiel.com{uri} 301 }`) plutôt
   qu'un matcher CEL. Le fichier dev n'a pas besoin de cette redirection.
3. **Validation éditoriale Manuka MGO** — texte à réviser par l'équipe
   éditoriale avant que la fiche puisse revendiquer un vrai `AggregateRating`
   ou un `certification` JSON-LD.

## Recommandations moyen terme (non bloquantes)

- Ajouter `Product::$replacedBy` + listener 301 automatique si les
  renommages deviennent fréquents.
- Cron mensuel exécutant `scripts/sitemap-audit.php` et publiant le
  rapport sur Slack/email.
- Suivi Search Console + GA4 des premières 4 semaines : surveiller
  couverture d'indexation, canonical rejetés, erreurs 404.
- Ajouter tests d'intégration contre un container prod réel (au-delà
  des tests unitaires actuels).

---

## Fichiers modifiés / créés dans cette phase

| Fichier                                                                                | Type      |
| -------------------------------------------------------------------------------------- | --------- |
| `src/Seo/IndexabilityPolicy.php`                                                       | Modifié   |
| `src/Seo/SeoResolver.php`                                                              | Modifié   |
| `src/EventListener/CanonicalRouterContextListener.php`                                 | Nouveau   |
| `src/Service/CartService.php`                                                          | Modifié   |
| `src/Service/CompareService.php`                                                       | Modifié   |
| `src/Service/WishlistService.php`                                                      | Modifié   |
| `src/Controller/ProductController.php`                                                 | Modifié   |
| `templates/checkout/index.html.twig`                                                   | Modifié   |
| `config/packages/framework.yaml`                                                       | Modifié   |
| `frankenphp/Caddyfile`                                                                 | Modifié   |
| `.env` / `.env.prod.local` / `.env.dev.local`                                          | Modifiés  |
| `scripts/sitemap-audit.php`                                                            | Nouveau   |
| `scripts/seo-smoke-test.sh`                                                            | Nouveau   |
| `tests/Unit/Service/CartServiceTest.php`                                               | Modifié   |
| `tests/Unit/Seo/IndexabilityPolicyTest.php`                                            | Nouveau   |
| `docs/product-lifecycle-seo.md`                                                        | Nouveau   |
| `docs/audit-seo-phase3-report.md`                                                      | Ce fichier |

## Statut des tests

- 89 tests unitaires — 179 assertions — 100 % verts.
- 8 vérifications smoke test SEO — 0 échec.
- Container prod : boot OK, autoload OK, cache warm OK.

**Recommandation finale : mise en production autorisée après exécution
des 3 conditions listées ci-dessus.**
