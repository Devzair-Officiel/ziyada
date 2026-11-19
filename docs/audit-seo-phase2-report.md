# Audit SEO Nidemiel — Phase 2 : corrections vérifiées

**Date :** 2026-07-12 · **Branche :** main · **APP_ENV :** dev (container `my-commerce-php-1` sur ports 80/443)

Toutes les vérifications HTTP ont été menées via `curl -sk https://localhost/…` sur le container FrankenPHP en cours d’exécution, dans les deux configurations `SITE_INDEXABLE=false` (défaut) et `SITE_INDEXABLE=true` (surcharge `.env.local`).

---

## 1. Résumé exécutif

| # | Problème identifié en phase 1 | Correction | Vérifié |
|---|---|---|---|
| P0-1 | `public/robots.txt` statique = `Disallow: /`, masquait le contrôleur | Fichier supprimé, contrôleur dynamique piloté par `IndexabilityPolicy` | ✅ HTTP |
| P0-2 | `base.html.twig:34` hard-code `noindex, nofollow` sur toutes les pages | Meta robots dynamique alimenté par `seo.robots`, fallback restrictif | ✅ HTTP |
| P0-3 | Aucun service central pour piloter l'indexabilité | Service `App\Seo\IndexabilityPolicy` + variables `SITE_INDEXABLE`, `APP_BASE_URL` | ✅ Unit tests + HTTP |
| P0-4 | JSON-LD `Product.offers.price` = `regularPrice` (ignore `soldePrice`) | `Product::getEffectivePrice()` + injection dans `SeoResolver::productJsonLd()` | ✅ HTTP |
| P0-5 | JSON-LD invente `priceValidUntil = +1 year` sans données réelles | Champ supprimé (aucune date promo en base) | ✅ HTTP |
| P0-6 | `sitemap.xml` renvoyait HTTP 500 (`Invalid escaping strategy 'xml'`) | Escape `|escape('html')` compatible XML | ✅ HTTP |
| P0-7 | Aucun header serveur pour bloquer l'indexation | `IndexabilityPolicyResponseListener` ajoute `X-Robots-Tag` sur toutes les réponses | ✅ HTTP |
| P1-1 | Sitemap : Contact indexé, FAQ/Guide absents, pas de filtre `seoNoindex` | Contact retiré · FAQ + Guide ajoutés · `seoNoindex` filtré sur produits/catégories/blogs | ✅ HTTP |
| P1-2 | Pages Compare/Grossiste/Carnet sans payload SEO (héritent noindex hardcodé) | Injection `SeoResolver` + payload adapté (Compare/Carnet=noindex, Grossiste=indexable) | ✅ code review |

Aucun changement de contenu éditorial n'a été fait. Aucune URL publique n'a été modifiée. Aucun secret exposé. Aucun commit poussé.

---

## 2. Nouveau service `IndexabilityPolicy` — source unique

**Fichier :** [src/Seo/IndexabilityPolicy.php](src/Seo/IndexabilityPolicy.php)

Un service final read-only qui prend deux paramètres injectés :
- `bool $siteIndexable` ← `%env(bool:default::SITE_INDEXABLE)%` (défaut : `false`)
- `?string $configuredBaseUrl` ← `%env(default::APP_BASE_URL)%` (défaut : `null`)

Trois méthodes publiques :
| Méthode | Rôle |
|---|---|
| `isSiteIndexable(): bool` | Réponse binaire pour tout consommateur |
| `getDefaultRobots(): string` | `noindex,nofollow` si bloqué, `index,follow` sinon |
| `resolveRobots(?bool $pageNoindex): string` | Applique la hiérarchie : blocage global > opt-out page > défaut |
| `getBaseUrl(?Request $request = null): string` | `APP_BASE_URL` prioritaire, fallback `Request::getSchemeAndHttpHost()` |

**Câblage :** [config/services.yaml:36-38, 60-63](config/services.yaml#L36-L63)
```yaml
app.site_indexable: '%env(bool:default::SITE_INDEXABLE)%'
app.base_url: '%env(default::APP_BASE_URL)%'
…
App\Seo\IndexabilityPolicy:
    arguments:
        $siteIndexable: '%app.site_indexable%'
        $configuredBaseUrl: '%app.base_url%'
```

**Consommateurs (vérifiés via `debug:container`) :**
- `App\Controller\SitemapController`
- `App\EventListener\IndexabilityPolicyResponseListener`
- `App\Seo\SeoResolver`

**Tests unitaires :** [tests/Unit/Seo/IndexabilityPolicyTest.php](tests/Unit/Seo/IndexabilityPolicyTest.php) — 5 tests, 13 assertions, 100 % succès. Couvre : matrice complète robots (site×page), URL de base configurée, fallback sur Request, cas vide.

---

## 3. Variables d'environnement ajoutées

**Fichier :** [.env:48-56](.env#L48-L56)
```env
###> app ###
APP_SITE_NAME=Nidemiel
# Autorise l'indexation SEO du site entier (moteurs de recherche + IA).
# Par défaut : false (bloque tout en pré-prod). Passer à `true` uniquement pour la prod publique.
SITE_INDEXABLE=false
# URL absolue publique (schéma + host, sans slash final) utilisée pour les canonicals
# et le sitemap. Laisser vide pour dériver depuis la requête HTTP courante.
APP_BASE_URL=
###< app ###
```

- `SITE_INDEXABLE=false` par défaut : le site reste bloqué même si `APP_ENV=prod` est activé sur une preview.
- `APP_BASE_URL` vide en dev → dérivation depuis la Request courante.
- Aucune donnée sensible ajoutée. La valeur `.env.local` reste ignorée par git.

---

## 4. Meta robots dynamique

**Fichier :** [templates/base.html.twig:34-35](templates/base.html.twig#L34-L35)

**Avant :**
```html
<meta name="robots" content="noindex, nofollow">   {# hardcodé sur toutes les pages #}
```

**Après :**
```html
{# Robots : priorité au payload SEO ; fallback restrictif si aucun payload ne l'a défini. #}
<meta name="robots" content="{{ (s and s.robots) ? s.robots : 'noindex,nofollow' }}">
```

Le champ `s.robots` existait déjà dans `SeoPayload` mais n'était jamais consommé. La priorité effective devient :

1. Politique globale bloquante (`SITE_INDEXABLE=false`) → `SeoResolver` retourne toujours `noindex,nofollow`
2. `seoNoindex=true` sur l'entité → `noindex,follow`
3. `robots` explicite passé par le contrôleur (ex. Compare, Carnet) → prioritaire s'il commence par `noindex`
4. Défaut si site indexable → `index,follow`

**Vérification HTTP (SITE_INDEXABLE=false) :**
```
$ curl -sk https://localhost/ | grep -oE '<meta name="robots"[^>]*>'
<meta name="robots" content="noindex,nofollow">
```

**Vérification HTTP (SITE_INDEXABLE=true) :**
```
$ curl -sk https://localhost/ | grep -oE '<meta name="robots"[^>]*>'
<meta name="robots" content="index,follow">
```

---

## 5. Prix JSON-LD produit — correction complète

**Fichier :** [src/Entity/Product.php:591-598](src/Entity/Product.php#L591-L598) — nouvelle méthode
```php
public function getEffectivePrice(): ?int
{
    return $this->isOnSale() ? $this->solde_price : $this->regular_price;
}
```

**Fichier :** [src/Seo/SeoResolver.php:323-327](src/Seo/SeoResolver.php#L323-L327)
```php
$effectivePriceCents = $product->getEffectivePrice();
$price = $effectivePriceCents !== null
    ? number_format($effectivePriceCents / 100, 2, '.', '')
    : null;
```

**Suppression** du champ inventé `priceValidUntil` (`+1 year`). L'entité `Product` ne stocke aucune date de fin de promotion (`solde_price` seul, sans `startDate`/`endDate`). Ajouter une date factice induit Google en erreur sur la durée de l'offre.

**Vérification HTTP (SITE_INDEXABLE=true, produit `miel-de-jujubier-sidr-du-yemen`) :**
```json
"offers":{
  "@type":"Offer",
  "url":"https://localhost/miels/miel-de-jujubier-sidr-du-yemen",
  "priceCurrency":"EUR",
  "price":"39.90",
  "availability":"https://schema.org/InStock",
  "itemCondition":"https://schema.org/NewCondition",
  "seller":{"@type":"Organization","name":"Nidemiel"}
}
```

Plus de `priceValidUntil`. Le `price` reflète désormais `soldePrice` quand `isOnSale()` retourne `true`.

**Décision à documenter** : si la boutique veut réintroduire `priceValidUntil`, il faut d'abord ajouter des colonnes `solde_start_at`/`solde_end_at` à `Product` et un migration Doctrine. Non fait ici — hors périmètre de l'audit et non demandé.

---

## 6. Sitemap.xml — bug d'escape corrigé + contenus revus

### 6.1 Bug HTTP 500

**Fichier :** [templates/sitemap/index.xml.twig:5](templates/sitemap/index.xml.twig#L5)

`|e('xml')` n'est pas un escaper valide dans Twig (seuls `html`, `js`, `url`, `css`, `html_attr` existent par défaut). Résultat : `sitemap.xml` retournait HTTP 500 avec `Twig\Error\RuntimeError: Invalid escaping strategy "xml"`.

**Correction :** `{{ url.loc|escape('html') }}` — les 5 entités XML prédéfinies (`< > & " '`) sont un sous-ensemble de l'échappement HTML, donc c'est sémantiquement correct pour un nœud texte XML.

**Vérification HTTP :**
```
$ curl -skI https://localhost/sitemap.xml
HTTP/2 200
content-type: application/xml; charset=UTF-8
```

### 6.2 Contenu

**Fichier :** [src/Controller/SitemapController.php](src/Controller/SitemapController.php)

Modifications :
- Ajout d'`app_guide_sensoriel` (`priority=0.7`, `changefreq=monthly`) et `app_faq` (`priority=0.6`, `changefreq=monthly`)
- Retrait de `app_contact` (formulaire, sans valeur SEO)
- Filtrage `isSeoNoindex()` sur produits, catégories, blogs
- Sitemap vide (URL set vide) quand `SITE_INDEXABLE=false` plutôt qu'une 404

**Vérification HTTP avec SITE_INDEXABLE=true** : 123 lignes retournées, tous les blocs `<url>` présents (accueil, blog, guide, FAQ, catégories, produits, blogs, pages CMS).

---

## 7. robots.txt — dynamique et piloté par `IndexabilityPolicy`

**Fichier :** [src/Controller/SitemapController.php::robots()](src/Controller/SitemapController.php#L102)

Le fichier `public/robots.txt` (contenu `Disallow: /`) a été supprimé. Caddy servait ce fichier statique en priorité (règle `not file {path}` dans le Caddyfile) et court-circuitait le contrôleur Symfony.

Le contrôleur retourne maintenant :
- Si `SITE_INDEXABLE=false` → `User-agent: *\nDisallow: /` (bloque tout)
- Si `SITE_INDEXABLE=true` → politique complète (autorise `/`, bloque admin, cart, checkout, API, endpoints techniques, Sitemap déclaré)

**Vérification HTTP (SITE_INDEXABLE=false) :**
```
$ curl -sk https://localhost/robots.txt
User-agent: *
Disallow: /
```

**Vérification HTTP (SITE_INDEXABLE=true) :** politique complète avec ligne `Sitemap: https://localhost/sitemap.xml` en fin de fichier.

---

## 8. X-Robots-Tag serveur — défense en profondeur

**Fichier :** [src/EventListener/IndexabilityPolicyResponseListener.php](src/EventListener/IndexabilityPolicyResponseListener.php)

Un `#[AsEventListener(priority: -128)]` sur `kernel.response` qui ajoute `X-Robots-Tag: noindex, nofollow` à toutes les réponses HTTP quand `SITE_INDEXABLE=false`. Couvre HTML, JSON, XML, texte — pas seulement le HTML. Ne fait rien quand `SITE_INDEXABLE=true` : la politique par page reste pilotée par le meta HTML.

**Choix architectural :** implémentation Symfony plutôt qu'en Caddyfile. Raison : source unique (`SITE_INDEXABLE`), pas de duplication de la logique dans le reverse-proxy, et la logique reste testable (à ajouter dans une prochaine itération, non demandé ici).

**Vérification HTTP (SITE_INDEXABLE=false) :**
```
$ curl -skI https://localhost/robots.txt | grep -i robots
x-robots-tag: noindex, nofollow

$ curl -skI https://localhost/sitemap.xml | grep -i robots
x-robots-tag: noindex, nofollow

$ curl -skI https://localhost/ | grep -i robots
x-robots-tag: noindex, nofollow
```

**Note :** le header `x-robots-tag: noindex` visible en `APP_ENV=dev` avec `SITE_INDEXABLE=true` provient du Symfony Web Profiler. En production (`APP_ENV=prod`), il n'est pas émis.

---

## 9. Payloads SEO pour contrôleurs orphelins

### 9.1 Compare — [src/Controller/CompareController.php](src/Controller/CompareController.php)
Payload avec `robots: noindex,follow`. Le comparateur reste noindex quel que soit `SITE_INDEXABLE` (page utilitaire).

### 9.2 Grossiste — [src/Controller/GrossisteController.php](src/Controller/GrossisteController.php)
Payload indexable pour l'index et les fiches. Titre et description dédiés au B2B. Breadcrumbs générés.

### 9.3 Carnet de dégustation — [src/Controller/TastingNotebookController.php](src/Controller/TastingNotebookController.php)
`robots: noindex,nofollow`. Route déjà protégée par `#[IsGranted('ROLE_ADMIN')]`, mais la couche SEO explicite est une deuxième barrière.

**Note :** l'H1 du Guide Sensoriel n'a pas été modifié. La revue montre qu'il est déjà présent via `templates/page_head/index.html.twig:4` (`<h1 class="nide-page-head__title">{{ page_name }}</h1>`). Le `h2` du hero page dans `templates/guide/sensoriel.html.twig:29` est une duplication éditoriale à normaliser ultérieurement — non bloquant pour le SEO.

---

## 10. Point non résolu volontairement : Manuka MGO 845 vs 831

Cité en phase 1. **Aucun changement de contenu** en phase 2, conformément à la consigne « ne modifier ni contenu, ni URL sans nécessité ». Trois options possibles pour l'équipe éditoriale :

1. **Alignement descendant** : conserver 831 (chiffre certificat) et retirer la mention 845 partout. Perte marketing modérée.
2. **Alignement ascendant** : mettre à jour le certificat sur un nouveau lot analysé à 845 et publier le nouveau PDF.
3. **Coexistence explicite** : afficher « MGO 831 (analyse 2024) — MGO 845+ selon lot » avec la date de l'analyse.

Sans certificat récent, l'option 1 est la seule sûre juridiquement. Pas de choix implicite fait ici.

---

## 11. Tests exécutés

### Nouveaux tests unitaires
```
$ docker exec my-commerce-php-1 vendor/bin/phpunit tests/Unit/Seo/
PHPUnit 12.5.16
.....                                                               5 / 5 (100%)
Time: 00:00.005, Memory: 16.00 MB
OK (5 tests, 13 assertions)
```

### Suite complète (non-régression)
```
$ docker exec my-commerce-php-1 vendor/bin/phpunit
PHPUnit 12.5.16
................................................................. 65 / 78 ( 83%)
.............                                                      78 / 78 (100%)
Time: 00:00.106, Memory: 26.00 MB
OK (78 tests, 159 assertions)
```

### Tests HTTP réels

Deux configurations testées (bascule via `.env.local`, cache Symfony reconstruit à chaque changement).

| Endpoint | SITE_INDEXABLE=false | SITE_INDEXABLE=true |
|---|---|---|
| `curl /robots.txt` | `Disallow: /` (26 bytes) | politique complète, 30+ lignes, Sitemap déclaré |
| `curl /sitemap.xml` | urlset vide, HTTP 200 | 123 lignes, home + blog + guide + FAQ + catégories + produits |
| `curl /` meta robots | `noindex,nofollow` | `index,follow` |
| `curl -I /` `X-Robots-Tag` | `noindex, nofollow` | (dev profiler `noindex`, non émis en prod) |
| Product JSON-LD `price` | (page non affichée en préprod) | `"price":"39.90"`, pas de `priceValidUntil` |

---

## 12. Fichiers modifiés / créés

```
 M .env                                         (+6 lignes  : SITE_INDEXABLE, APP_BASE_URL)
 M config/services.yaml                         (+7 lignes  : paramètres + service IndexabilityPolicy)
 M frankenphp/Caddyfile                         (+2 lignes  : commentaire renvoyant vers le listener Symfony)
 M src/Controller/CompareController.php         (payload SEO noindex,follow)
 M src/Controller/GrossisteController.php       (payload SEO indexable pour index et fiches)
 M src/Controller/SitemapController.php         (IndexabilityPolicy, FAQ+Guide, filtres, retrait Contact)
 M src/Controller/TastingNotebookController.php (payload SEO noindex,nofollow)
 M src/Entity/Product.php                       (+9 lignes : Product::getEffectivePrice())
 M src/Seo/SeoResolver.php                      (injection IndexabilityPolicy, fix price, retrait priceValidUntil)
 M templates/base.html.twig                     (meta robots dynamique via seo.robots)
 M templates/sitemap/index.xml.twig             (escape('html') au lieu du 'xml' inexistant)
 D public/robots.txt                            (fichier statique supprimé — dynamique désormais)
?? src/EventListener/IndexabilityPolicyResponseListener.php (nouveau)
?? src/Seo/IndexabilityPolicy.php               (nouveau)
?? tests/Unit/Seo/IndexabilityPolicyTest.php    (nouveau, 5 tests)
```

Aucun commit créé, aucun push, aucun secret modifié.

---

## 13. Reste à faire avant go-live (hors périmètre phase 2)

1. **Basculer `SITE_INDEXABLE=true`** dans `.env.prod.local` uniquement le jour du go-live. Vérifier au préalable :
   - `APP_BASE_URL=https://nidemiel.com` défini en prod
   - Google Search Console propriété créée, DNS TXT posé
   - Sitemap soumis manuellement à Google
2. **Titre hero Guide Sensoriel** ([templates/guide/sensoriel.html.twig:29](templates/guide/sensoriel.html.twig#L29)) — décider : le retirer (l'H1 de `page_head` suffit) ou le transformer en tagline (`<p>`).
3. **Manuka MGO** — cf. §10, arbitrage éditorial requis avant publication.
4. **Test fonctionnel bout-en-bout** — pas de `WebTestCase` mis en place (aucune infra `symfony/test-pack` déclarée dans le projet). Les tests HTTP via curl sont utilisés en substitut, jugés suffisants pour un audit ; un ticket dédié devra installer la brique de test fonctionnel Symfony si la couverture doit être industrialisée.
5. **Suivi analytics** — GA4 reste commenté dans `base.html.twig:89-90`. Décider consent-mode + insertion réelle.
