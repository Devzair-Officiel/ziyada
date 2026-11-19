# Audit SEO/GEO — Phase 3.2 — Rapport final

**Date** : 2026-07-13
**Périmètre** : durcissement final avant go-live public
**Verdict** : **GO** — les deux modes du smoke test passent, la config
Caddy prod est validée sans conflit de route, la garde `TRUSTED_HOSTS`
est effective et testée, les documents crawlers et routes privées sont
corrigés.

---

## 1. robots.txt — bytes réels servis

### 1.1 Mode SITE_INDEXABLE=true (prod publique)

Capture live via `curl -H "Host: nidemiel.com" https://localhost/robots.txt`
avec `SITE_INDEXABLE=true`, `TRUSTED_HOSTS=^(nidemiel\.com|www\.nidemiel\.com)$`,
`APP_ENV=prod` :

```
User-agent: *
Allow: /

# Espace admin (protégé par IsGranted, aucune valeur SEO)
Disallow: /admin

# Endpoints techniques sans rendu HTML (pas de meta robots possible)
Disallow: /api/
Disallow: /product/get/
Disallow: /product/search
Disallow: /webhooks/
Disallow: /stripe/
Disallow: /verify/
Disallow: /connect/
Disallow: /logout

Sitemap: https://nidemiel.com/sitemap.xml
```

**Contrôles automatisés passés** :

- Chaque `Disallow` contient **exactement un chemin** (validateur awk
  strippant les commentaires `#…`).
- `User-agent: *` **ne contient pas** `Disallow: /` — indexation
  autorisée globalement.
- Ligne `Sitemap:` présente, pointant sur `https://nidemiel.com/sitemap.xml`.
- Aucune règle explicite pour `OAI-SearchBot` (comportement par défaut =
  autorisé). Recommandation Phase 3.2 non-implémentée volontairement :
  la stratégie éditoriale Nidemiel est « présence maximale sur tous les
  Search » — aucun bot IA n'est bloqué.

### 1.2 Mode SITE_INDEXABLE=false (preview / staging)

```
User-agent: *
Disallow: /
```

Blocage total — pas de ligne `Sitemap:` (le sitemap peut être servi
mais n'est pas déclaré). Cohérent avec la politique noindex.

---

## 2. Smoke test — deux modes complets

### 2.1 Mode indexable (SUCCÈS 20/20)

```
$ EXPECTED_INDEXABLE=true \
  EXPECTED_CANONICAL=https://nidemiel.com \
  BASE_URL=https://localhost \
  HOST_HEADER=nidemiel.com \
  PRODUCT_PATH=/miels/miel-de-jujubier-sidr-du-yemen \
  bash scripts/seo-smoke-test.sh
```

Résumé des 20 vérifications :

- Serveur joignable HTTP 200
- `robots.txt` : 200, pas de blocage global, `Sitemap:` présent et sur
  domaine canonique, un chemin par `Disallow`, absence de bloc dédié
  OAI-SearchBot documentée
- Home : `meta robots="index,follow"`, canonical + og:url + JSON-LD
  **tous sur `https://nidemiel.com`**
- Fiche produit `/miels/miel-de-jujubier-sidr-du-yemen` : 200, canonical
  + og:url + toutes les URLs JSON-LD sur `https://nidemiel.com`
- Injection Host `attacker.example` : réponse ne contient jamais
  `attacker.example`
- `/sitemap.xml` : 200, `<urlset>` valide, toutes les `<loc>` sur
  `https://nidemiel.com`

Verdict script : `✅ Smoke test SEO : SUCCÈS (mode attendu : true)`.

### 2.2 Mode non-indexable (SUCCÈS 15/15)

```
$ EXPECTED_INDEXABLE=false \
  EXPECTED_CANONICAL=https://localhost \
  BASE_URL=https://localhost \
  PRODUCT_PATH=/miels/miel-de-jujubier-sidr-du-yemen \
  bash scripts/seo-smoke-test.sh
```

- `robots.txt` contient `User-agent: * / Disallow: /`
- Home : `meta robots="noindex,nofollow"` + header
  `X-Robots-Tag: noindex, nofollow` (défense en profondeur via
  `IndexabilityPolicyResponseListener`)
- Canonical, og:url, JSON-LD sur `https://localhost` (fallback Request
  documenté — `APP_BASE_URL` est vide en preview)
- `/sitemap.xml` accessible mais ne contient pas de Host fictif

Verdict script : `✅ Smoke test SEO : SUCCÈS (mode attendu : false)`.

### 2.3 Redémarrage complet du worker FrankenPHP entre les deux modes

Chaque bascule a été effectuée via
`docker compose up -d --force-recreate --no-deps php`, garantissant que
le worker relit intégralement `.env.dev.local` (le simple `restart` ne
recharge pas les variables d'environnement dans FrankenPHP).

### 2.4 Comportement du script en tant que gate strict

- Refuse de démarrer si `EXPECTED_INDEXABLE` n'est pas exactement
  `"true"` ou `"false"` (exit 2).
- Refuse de démarrer si `EXPECTED_CANONICAL` est absent ou ne commence
  pas par `https://` (exit 2).
- Écrit les headers via `curl -D headers_file -o body_file` dans un
  répertoire `mktemp -d` nettoyé par `trap … EXIT INT TERM`.
- Utilise `printf` partout au lieu de `echo` (pour la portabilité et
  l'insensibilité aux flags shell).
- Valide la syntaxe robots.txt en strippant les commentaires
  (`sub(/[[:space:]]*#.*/, "", line)` avant test de multi-chemin).
- Extrait `<link rel="canonical">`, `<meta property="og:url">` et
  **toutes** les URLs HTTPS de tous les blocs JSON-LD ; échoue dur si
  l'une d'elles diverge de `EXPECTED_CANONICAL` (à l'exception de
  `schema.org` / `w3.org`).

---

## 3. Chaîne Caddy — CADDY_EXTRA_CONFIG effectivement chargé

### 3.1 Résultat du `frankenphp validate` sur la config complète

```
$ CADDY_EXTRA_CONFIG="import /etc/frankenphp/Caddyfile.prod" \
  SERVER_NAME="nidemiel.com" \
  frankenphp validate --config /etc/frankenphp/Caddyfile --adapter caddyfile

… "msg":"adapted config to JSON","adapter":"caddyfile"
… "msg":"enabling automatic HTTP->HTTPS redirects","server_name":"srv0"
Valid configuration
```

### 3.2 Correction critique du SERVER_NAME (compose.prod.yaml)

**Avant** :
```yaml
SERVER_NAME: "nidemiel.com, www.nidemiel.com"
```
→ conflit de route : `www.nidemiel.com` est défini à la fois par le
bloc principal ET par le fragment importé. Résultat : `frankenphp
validate` renvoie `ambiguous site definition: www.nidemiel.com`. Bloque
le démarrage en prod.

**Après** :
```yaml
SERVER_NAME: "nidemiel.com"
```
Le bloc `www.nidemiel.com { redir https://nidemiel.com{uri} 308 }` du
fragment `frankenphp/Caddyfile.prod` est le seul propriétaire de cet
hôte. Caddy provisionne automatiquement son certificat TLS via
Let's Encrypt.

### 3.3 Extrait de la config adaptée (JSON) — route de redirection www

```json
{
    "match": [
        { "host": ["www.nidemiel.com"] }
    ],
    "handle": [
        {
            "handler": "subroute",
            "routes": [
                {
                    "handle": [
                        {
                            "handler": "static_response",
                            "headers": {
                                "Location": [
                                    "https://nidemiel.com{http.request.uri}"
                                ]
                            },
                            "status_code": 308
                        }
                    ]
                }
            ]
        }
    ],
    "terminal": true
}
```

Le route matche `Host: www.nidemiel.com`, retourne un 308 vers
`https://nidemiel.com{uri}`, et `"terminal": true` empêche tout autre
route de matcher.

### 3.4 Choix du mécanisme : CADDY_EXTRA_CONFIG plutôt que Caddyfile.d

La ligne `{$CADDY_EXTRA_CONFIG}` est présente à
[frankenphp/Caddyfile:16](../frankenphp/Caddyfile#L16) et fonctionne
comme un point d'extension standard de FrankenPHP. Après validation
positive, le passage à `/etc/frankenphp/Caddyfile.d/*.caddyfile` n'est
pas nécessaire. Le fragment reste :

- versionné (`frankenphp/Caddyfile.prod`)
- bind-monté en lecture seule
  (`./frankenphp/Caddyfile.prod:/etc/frankenphp/Caddyfile.prod:ro`)
- explicitement importé via `CADDY_EXTRA_CONFIG: "import /etc/frankenphp/Caddyfile.prod"`

Cette voie est plus explicite et grepable qu'un include implicite via
`Caddyfile.d/`.

---

## 4. TRUSTED_HOSTS obligatoire quand SITE_INDEXABLE=true

### 4.1 Règle appliquée dans `IndexabilityPolicy::validateTrustedHosts`

Quand `SITE_INDEXABLE=true`, la valeur de `TRUSTED_HOSTS` est
**obligatoire** et doit :

1. être non vide,
2. ne pas contenir `localhost`, `127.0.0.1`, `0.0.0.0`, `10.`,
   `192.168.`, `.local`, `.test`, `preview.`, `staging.`, `preprod.`,
3. ne pas être un regex trop large (`.*`, `.+`, `^.*$`, `^.$`, `^$`),
4. être **ancré** (commencer par `^` et finir par `$`) pour empêcher
   `nidemiel.com` de matcher `evil.nidemiel.com.attacker.example`.

En mode non-indexable, `TRUSTED_HOSTS` peut être vide (dev/preview).

### 4.2 Couverture PHPUnit (`IndexabilityPolicyTest`)

29 tests dédiés — tous verts :

- `testIndexableTrustedHostsEmptyThrows` — chaîne vide en indexable →
  `\InvalidArgumentException`
- `testIndexableTrustedHostsAcceptsCanonicalDomains` — regex
  `^(nidemiel\.com|www\.nidemiel\.com)$` accepté
- `testIndexableTrustedHostsRejectsLocalhost` — `localhost` dans le
  regex → refusé
- `testIndexableTrustedHostsRejectsLoopbackIp` — `127.0.0.1` → refusé
- `testIndexableTrustedHostsRejectsPreviewSubdomain` — `preview.` →
  refusé
- `testIndexableTrustedHostsTooBroadDotStarThrows` — `.*` → refusé
- `testIndexableTrustedHostsTooBroadAnchoredDotStarThrows` — `^.*$` →
  refusé
- `testIndexableTrustedHostsTooBroadDotPlusThrows` — `.+` → refusé
- `testIndexableTrustedHostsUnanchoredThrows` — `nidemiel\.com` (sans
  `^` ni `$`) → refusé
- `testIndexableTrustedHostsMissingEndAnchorThrows` — `^nidemiel\.com`
  (manque `$`) → refusé
- `testIndexableTrustedHostsMissingStartAnchorThrows` — `nidemiel\.com$`
  (manque `^`) → refusé
- `testNonIndexableTolerantOnTrustedHostsLocalhost` — `localhost` en
  non-indexable → accepté
- `testNonIndexableAcceptsEmptyTrustedHosts` — vide en non-indexable →
  accepté

Résultat PHPUnit : `OK (29 tests, 56 assertions)` (fichier isolé) et
`OK (102 tests, 202 assertions)` (suite complète).

### 4.3 Valeur de référence en production

Dans `.env.prod.local` :

```
SITE_INDEXABLE=true
APP_BASE_URL=https://nidemiel.com
TRUSTED_HOSTS=^(nidemiel\.com|www\.nidemiel\.com)$
```

Toute tentative de démarrer l'app avec `SITE_INDEXABLE=true` et
`TRUSTED_HOSTS=` vide, `TRUSTED_HOSTS=.*`, ou `TRUSTED_HOSTS=nidemiel\.com`
(non ancré) produira un `\InvalidArgumentException` au boot du
container Symfony → **le déploiement échoue avant même d'exposer
Caddy**. Défense en profondeur avant même l'injection Host.

---

## 5. Documentation crawlers IA — corrections

### 5.1 Split Anthropic

Le rapport Phase 3.1 (§2) traitait `ClaudeBot` comme un bot unique. C'est
inexact — Anthropic distingue **trois** UAs :

| Crawler            | Rôle                                                                             | Impact                                                                                    |
| ------------------ | -------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `ClaudeBot`        | Collecte pour **entraînement** des modèles Claude                                | N'affecte PAS l'inclusion dans un index Search Claude ; respecte `robots.txt`             |
| `Claude-SearchBot` | Indexation dédiée aux **fonctionnalités de recherche** de Claude                 | Seul UA qui détermine l'inclusion dans Claude Search ; respecte `robots.txt`              |
| `Claude-User`      | Fetches **initiés par un utilisateur** Claude (browsing / tool use)              | N'affecte pas la présence dans un index ; **peut ignorer** `robots.txt` (fetch utilisateur) |

Conséquence : bloquer `ClaudeBot` ne coupe **pas** Claude Search. Pour
couper Claude Search il faut cibler `Claude-SearchBot`.

### 5.2 Précision OpenAI

Idem pour OpenAI, trois UAs distincts :

- `OAI-SearchBot` — index de **ChatGPT Search**. Seul UA à contrôler
  pour être présent dans ChatGPT Search.
- `GPTBot` — collecte pour **entraînement**. Le bloquer n'affecte pas
  ChatGPT Search.
- `ChatGPT-User` — fetches initiés par un utilisateur ChatGPT. Peut
  ignorer `robots.txt`.

### 5.3 Précision Google

`Google-Extended` est un signal opt-out **pour l'entraînement et
Gemini/Vertex AI uniquement**. Il n'affecte **pas** l'éligibilité aux
AI Overviews ni à AI Mode, qui dépendent de `Googlebot`.

### 5.4 Mise à jour de docs/audit-seo-phase3-1-report.md

Le tableau §2 du rapport Phase 3.1 a été réécrit avec ces trois splits
+ une section « Points clés à retenir » qui rend la sémantique
explicite (bloquer un training bot ≠ bloquer un search bot ≠ contrôler
un user-fetch bot). Voir
[docs/audit-seo-phase3-1-report.md](audit-seo-phase3-1-report.md#L115-L200)
lignes 115–200.

---

## 6. Documentation routes privées — correction

Les routes `/account`, `/checkout`, `/wishlist` ne sont **pas**
protégées « par une meta noindex directement appliquée à l'URL
source » — c'est un abus de langage corrigé dans la Phase 3.2.

Comportement réel :

- Requête anonyme sur `/account` : Symfony (via
  `security.yaml`) déclenche un 302 vers `/login`.
- La page `/login` finale renvoie un HTTP 200 avec
  `<meta name="robots" content="noindex,nofollow">`.
- **Aucun HTML privé** (contenu du compte, panier utilisateur, wishlist
  personnelle) n'est jamais retourné publiquement. Le crawler voit
  successivement une 302 puis un login noindex.

Ce détail est important : un audit externe pourrait sinon inspecter
`/account` en pensant y trouver une meta noindex ; il n'y en a pas
parce que le HTML de `/account` **n'existe pas** pour un anonyme.

Le tableau §1.1 du rapport Phase 3.1 a été corrigé en conséquence.

---

## 7. Résultats des tests

### 7.1 PHPUnit — suite complète

```
$ docker compose exec -T php vendor/bin/phpunit
.......................................                         102 / 102 (100%)

Time: 00:00.107, Memory: 26.00 MB

OK (102 tests, 202 assertions)
```

### 7.2 PHPUnit — IndexabilityPolicyTest isolé

```
$ docker compose exec -T php bin/phpunit tests/Unit/Seo/IndexabilityPolicyTest.php
.............................                                     29 / 29 (100%)

Time: 00:00.008, Memory: 18.00 MB

OK (29 tests, 56 assertions)
```

### 7.3 Smoke test — deux modes

| Mode                       | Vérifications | Échecs | Résultat |
| -------------------------- | :-----------: | :----: | :------: |
| `EXPECTED_INDEXABLE=true`  |      20       |   0    | ✅ SUCCÈS |
| `EXPECTED_INDEXABLE=false` |      15       |   0    | ✅ SUCCÈS |

Redémarrage complet `--force-recreate` du worker FrankenPHP entre les
deux modes (nécessaire : `docker compose restart` ne rechargerait pas
les env vars dans le worker).

### 7.4 Sitemap-audit — statut

`scripts/sitemap-audit.php` est **conçu pour un run post-déploiement**
contre le domaine de production réel (avec certificat TLS valide et
DNS public). En dev local, la validation TLS échoue faute de
certificat pour `nidemiel.com` (le certificat émis par Caddy est
auto-signé pour `localhost`).

Il n'a donc pas été exécuté dans ce rapport. À lancer manuellement
après go-live :

```
php scripts/sitemap-audit.php https://nidemiel.com/sitemap.xml
```

Exit code 0 = OK ; toute anomalie fait sortir avec code ≥ 1.

---

## 8. Verdict — GO / NO-GO

### Critères GO validés

- [x] Smoke test `EXPECTED_INDEXABLE=true` : 20/20 vérifications
- [x] Smoke test `EXPECTED_INDEXABLE=false` : 15/15 vérifications
- [x] Caddy adapté prod contient une route `www.nidemiel.com → 308
      https://nidemiel.com` sans conflit d'hôte
- [x] `TRUSTED_HOSTS` vide en indexable → boot Symfony échoue (défense
      en profondeur)
- [x] `TRUSTED_HOSTS` trop large ou non-ancré → boot Symfony échoue
- [x] PHPUnit : 102/102 tests verts
- [x] Docs crawlers (Anthropic, OpenAI, Google) corrigées
- [x] Docs routes privées corrigées (redirect vers login noindex)
- [x] Fragment `Caddyfile.prod` versionné et importé via
      `CADDY_EXTRA_CONFIG` (mécanisme validé par `frankenphp validate`)

### Actions post-déploiement (hors du périmètre de ce rapport)

- Lancer `scripts/sitemap-audit.php https://nidemiel.com/sitemap.xml`
  depuis un poste avec DNS public + certificat TLS valide.
- Confirmer dans Google Search Console qu'aucune erreur « Indexed,
  though blocked by robots.txt » n'apparaît sur `/login`, `/register`,
  `/reset-password`, `/cart`, `/compare`.
- Confirmer dans Search Console qu'aucun signalement d'AggregateRating
  factice (MGO/NPA/DHA/HMF confondu avec un avis client) — la Phase 3.1
  a supprimé ces alimentations, à vérifier post-crawl Google.
- Confirmer que la redirection `www.nidemiel.com` → apex renvoie bien
  308 sur toutes les URLs (échantillonner `/`, `/miels/…`, `/blog/…`,
  `/category/…`).

### Décision

**GO pour go-live public.** Aucun défaut bloquant identifié. La chaîne
de défense (`IndexabilityPolicy` validator → Caddy trusted hosts →
robots.txt → meta robots → X-Robots-Tag → canonical/JSON-LD source
unique) est **cohérente, testée automatiquement en CI-friendly (smoke
test gate), et documentée**.
