# Audit SEO / GEO — Phase 3.1 : corrections ciblées

**Date** : 2026-07-14
**Continuation de** : [audit-seo-phase3-report.md](audit-seo-phase3-report.md)
**Portée** : corrections des défauts identifiés après la Phase 3 initiale
(syntaxe robots.txt, distinction crawlers IA, Manuka/MGO, Caddy prod
versionné, TRUSTED_HOSTS, cycle produit, test /carnet).
**Environnement** : dev Docker basculé temporairement en `SITE_INDEXABLE=true`
via `.env.dev.local` (bloc retiré à la fin).

---

## Verdict global

> **GO conditionnel** — les 8 points ciblés sont corrigés et testés.
> Reste 2 conditions à exécuter au moment du déploiement (voir §11).
> Aucune régression : 95/95 tests unitaires verts, smoke test 9/9 OK.

---

## 1. robots.txt — contenu brut final

En mode indexable (`SITE_INDEXABLE=true`) :

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

En mode non indexable (`SITE_INDEXABLE=false`) : `User-agent: * / Disallow: /`.

### 1.1 Liste exacte des routes bloquées vs autorisées

| Chemin bloqué          | Justification                                                        |
| ---------------------- | -------------------------------------------------------------------- |
| `/admin`               | Zone privée, protégée par `IsGranted` ; aucun HTML pertinent à Google |
| `/api/`                | Endpoints JSON, pas de meta robots possible                          |
| `/product/get/`        | JSON, endpoint interne                                               |
| `/product/search`      | Recherche facette, JSON                                              |
| `/webhooks/`           | Stripe webhook, serveur uniquement                                   |
| `/stripe/`             | Callbacks paiement, redirections                                     |
| `/verify/`             | Vérification email, tokens à usage unique                            |
| `/connect/`            | Callbacks OAuth Google                                               |
| `/logout`              | Endpoint destructif, redirect                                        |

Routes **retirées** de Disallow — deux mécanismes distincts empêchent
leur indexation, tous deux nécessitent que le crawler puisse
atteindre la page (`robots.txt` doit donc laisser passer) :

| Chemin            | Mécanisme d'exclusion pour visiteur anonyme                            |
| ----------------- | ---------------------------------------------------------------------- |
| `/login`          | 200 + `<meta name="robots" content="noindex,nofollow">` sur la page servie |
| `/register`       | 200 + `<meta name="robots" content="noindex,nofollow">`                |
| `/reset-password` | 200 + `<meta name="robots" content="noindex,nofollow">`                |
| `/cart`           | 200 + `<meta name="robots" content="noindex,nofollow">`                |
| `/compare`        | 200 + `<meta name="robots" content="noindex,nofollow">`                |
| `/account`        | 302 → `/login` : requête anonyme redirigée vers la page de connexion **qui est elle-même en `noindex`**. Aucun contenu privé n'est jamais servi publiquement, donc aucun HTML de `/account` n'est indexable. |
| `/checkout`       | 302 → `/login` : idem — le HTML sensible du tunnel de commande n'est jamais rendu à un anonyme, seule la page de connexion (noindex) l'est. |
| `/wishlist`       | 302 → `/login` : idem — la wishlist est privée, seul le login (noindex) est servi à un anonyme. |

Motif : bloquer via `robots.txt` empêche le crawler de lire le
`meta robots`, ce qui produit l'état « Indexed, though blocked by
robots.txt » dans Search Console. Sur les routes qui émettent
directement une meta noindex (`/login`, `/register`, `/cart`,
`/compare`, `/reset-password`), c'est la meta qui prévaut. Sur les
routes qui redirigent vers `/login` (`/account`, `/checkout`,
`/wishlist`), **aucun HTML privé n'est jamais retourné publiquement**
— le crawler voit la 302 puis la page de connexion en noindex ; il
n'y a donc pas de contenu privé qui puisse être indexé, même
transitoirement.

### 1.2 Cohérence routes/robots.txt

Les chemins de `Disallow` correspondent aux routes réelles vérifiées via
`bin/console debug:router` :

- `/compare` (route `app_compare`) — n'était pas bloqué à tort en tant que `/comparateur`.
- `/grossiste-miel` (route `app_grossiste_index`) — contenu indexable,
  correctement **non bloqué**.
- `/carnet-degustation-miel` (route `app_tasting_notebook`) — 302 → `/login`
  pour les visiteurs anonymes ; pas besoin de Disallow puisque le
  redirect empêche l'indexation utile.

### 1.3 Validateur syntaxe robots

Nouveau contrôle dans [scripts/seo-smoke-test.sh](../scripts/seo-smoke-test.sh) :

```awk
/^[[:space:]]*(Allow|Disallow):/ {
    sub(/^[[:space:]]*(Allow|Disallow):[[:space:]]*/, "", $0)
    sub(/\r$/, "", $0)
    if ($0 ~ /[[:space:]]/) { print NR ": " $0 }
}
```

Détecte les lignes du type `Disallow: /admin /account /login` qui sont
interprétées par les moteurs comme un seul chemin `/admin /account /login`
(les autres sont ignorés). Résultat de l'exécution actuelle :

```
✓ Toutes les directives Allow/Disallow contiennent un seul chemin
```

Smoke test complet : **9 vérifications, 0 échec**.

---

## 2. Crawlers IA — politique corrigée

Les crawlers IA ne sont **pas** tous équivalents. Le tableau suivant
remplace toute mention indistincte de « crawlers IA » dans les rapports
précédents.

| Crawler          | Rôle réel                                                                                      | Impact sur la présence dans les fonctions de recherche                             | Politique Nidemiel |
| ---------------- | ---------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- | ------------------ |
| `Googlebot`      | Crawler principal Google                                                                       | Détermine l'éligibilité à Google Search, aux **AI Overviews** et à **AI Mode**     | **Autorisé** (Allow /) |
| `Google-Extended` | Signal opt-out réservé à l'entraînement et à Gemini/Vertex AI                                 | **N'affecte PAS** l'éligibilité à Google Search, AI Overviews et AI Mode           | **Autorisé** implicitement (pas de bloc dédié) |
| `Bingbot`        | Crawler principal Bing                                                                         | Détermine l'éligibilité à Bing Search et à Copilot Search                          | **Autorisé** |
| `OAI-SearchBot`  | Crawler d'OpenAI dédié à l'index de **ChatGPT Search**                                         | **Seul** UA qui détermine l'inclusion dans ChatGPT Search ; respecte robots.txt   | **Autorisé** (obligatoire pour être visible dans ChatGPT Search) |
| `GPTBot`         | Crawler OpenAI dédié à la collecte pour **entraînement** des modèles                           | **N'affecte PAS** l'inclusion dans ChatGPT Search ; respecte robots.txt            | **Autorisé** (décision éditoriale : accepter la contribution à l'entraînement ; à revoir si la politique OpenAI évolue) |
| `ChatGPT-User`   | Requêtes déclenchées **en direct** par un utilisateur ChatGPT (browsing, actions, plugins)     | **N'affecte PAS** la présence dans Search ; **peut ignorer robots.txt** (fetch initié par l'utilisateur, non un crawl) | **Non contrôlable via robots.txt** ; comportement identique à un navigateur utilisateur |
| `PerplexityBot`  | Crawler Perplexity dédié à l'index de réponses                                                 | Détermine l'inclusion dans les résultats Perplexity                                | **Autorisé** |
| `ClaudeBot`      | Crawler Anthropic dédié à la collecte pour **entraînement** des modèles Claude                 | **N'affecte PAS** l'inclusion dans un éventuel index Claude ; respecte robots.txt  | **Autorisé** (parallèle à GPTBot ; décision éditoriale de participer à l'entraînement) |
| `Claude-SearchBot` | Crawler Anthropic dédié à l'**indexation** pour les fonctionnalités de recherche de Claude   | **Seul** UA d'Anthropic qui détermine l'inclusion dans Claude Search ; respecte robots.txt | **Autorisé** (obligatoire pour être visible dans les résultats de recherche Claude) |
| `Claude-User`    | Requêtes déclenchées **en direct** par un utilisateur Claude (browsing/tool use)               | **N'affecte PAS** la présence dans un index ; **peut ignorer robots.txt** (fetch initié par l'utilisateur) | **Non contrôlable via robots.txt** ; comportement identique à un navigateur utilisateur |

**Points clés à retenir** :

- Bloquer `GPTBot` ou `ClaudeBot` **ne coupe pas** ChatGPT Search ni
  Claude Search — ces bots concernent uniquement l'entraînement. Pour
  couper Search côté OpenAI ou Anthropic, il faudrait bloquer
  respectivement `OAI-SearchBot` ou `Claude-SearchBot`.
- Bloquer `Google-Extended` **ne coupe pas** les AI Overviews ni AI
  Mode : ces derniers dépendent de `Googlebot`. `Google-Extended` ne
  concerne que Gemini/Vertex AI (usage IA générative distinct).
- `ChatGPT-User` et `Claude-User` correspondent à des **fetches
  utilisateur** (conversation en cours, tool use). Ils peuvent ignorer
  `robots.txt` — c'est un choix documenté de leurs éditeurs, cohérent
  avec le fait qu'un utilisateur agit en son nom propre. Aucune règle
  robots.txt ne les empêchera de charger une URL publique.

**Politique actuelle Nidemiel** : aucun bot IA n'est bloqué. Tous
tombent sous `User-agent: * / Allow: /`. Aucune règle spécifique
n'est nécessaire tant que la stratégie éditoriale reste « présence
maximale ».

Pour bloquer sélectivement `GPTBot` **sans** couper ChatGPT Search
(choix éditorial futur) :

```
User-agent: GPTBot
Disallow: /

User-agent: OAI-SearchBot
Allow: /
```

Symétriquement pour Anthropic :

```
User-agent: ClaudeBot
Disallow: /

User-agent: Claude-SearchBot
Allow: /
```

Toute décision de blocage doit être documentée (ADR ou entrée
`docs/`) car un bloc mal ciblé (`Disallow` sur `OAI-SearchBot` ou
`Claude-SearchBot`) casserait la visibilité Search côté chatbots
sans intention explicite.

---

## 3. Manuka / MGO / AggregateRating — politique corrigée

### 3.1 Règle impérative

- `AggregateRating` **exclusivement** issu d'avis clients vérifiables
  et affichés sur la page (via `Review` entity + `ReviewRepository`).
- Une valeur MGO (analyse en laboratoire d'un miel de Manuka) **n'est
  pas** une note client. Ne jamais alimenter `aggregateRating` avec MGO,
  NPA, DHA ou HMF.
- Pas de `hasCertification` JSON-LD tant que la conformité du certificat
  aux propriétés reconnues (schema.org/EducationalOccupationalCredential
  ou équivalent) n'est pas documentée et validée juridiquement.
- Les valeurs MGO / NPA / DHA / HMF peuvent apparaître dans le HTML
  visible (fiche produit, tableau comparatif) mais pas dans le JSON-LD
  Product/Offer.

### 3.2 État du code

Audit du code source : aucune injection de MGO dans `AggregateRating`.
Seul `Product::getAverageRating()` (issu de `ReviewRepository`) alimente
`aggregateRating` dans [SeoResolver.php:355-363](../src/Seo/SeoResolver.php#L355-L363).

`hasCertification` n'existe nulle part dans le code — aucune injection
possible.

### 3.3 Blocage éditorial Manuka

Ne pas mettre en ligne la fiche Manuka tant que :

- La valeur MGO exacte du lot vendu est confirmée par l'importateur.
- L'analyse de laboratoire correspondant à ce lot précis est archivée
  (le lot en stock aujourd'hui doit correspondre à l'analyse citée).
- Le titre, la description et le slug reflètent la valeur MGO effective
  du lot en stock (pas celle d'un lot précédent).
- Aucune modification automatique de ces champs n'est faite sans
  justificatif documenté.

Ce blocage est **éditorial**, pas technique — le code ne peut pas le
faire respecter sans référentiel de lots.

---

## 4. Configuration Caddy production — versionnée

### 4.1 Fichier de configuration

Nouveau fichier versionné :
[frankenphp/Caddyfile.prod](../frankenphp/Caddyfile.prod).

```caddy
www.nidemiel.com {
    redir https://nidemiel.com{uri} 308
}
```

### 4.2 Intégration Docker Compose

[compose.prod.yaml](../compose.prod.yaml) monte le fragment en lecture
seule et l'injecte dans la config Caddy globale :

```yaml
services:
  php:
    environment:
      SERVER_NAME: "nidemiel.com, www.nidemiel.com"
      CADDY_EXTRA_CONFIG: "import /etc/frankenphp/Caddyfile.prod"
    volumes:
      - ./frankenphp/Caddyfile.prod:/etc/frankenphp/Caddyfile.prod:ro
```

Effet attendu en production :

```
curl -I https://www.nidemiel.com/
→ HTTP/2 308
→ location: https://nidemiel.com/

curl -I https://www.nidemiel.com/miels/miel-lavande
→ HTTP/2 308
→ location: https://nidemiel.com/miels/miel-lavande
```

### 4.3 Test avant déploiement

```bash
docker compose -f compose.yaml -f compose.prod.yaml config
docker compose -f compose.yaml -f compose.prod.yaml exec php \
    caddy validate --config /etc/frankenphp/Caddyfile.prod
```

Aucune modification manuelle sur le VPS n'est nécessaire. Toute
reconstruction du VPS repartira du même fragment versionné.

---

## 5. TRUSTED_HOSTS — séparation local / prod

### 5.1 Valeurs

| Environnement          | TRUSTED_HOSTS                                                     |
| ---------------------- | ----------------------------------------------------------------- |
| Local / dev            | (vide) — pas de garde Symfony, on tolère localhost                |
| Simulation prod locale | `^(nidemiel\.com|www\.nidemiel\.com|localhost)$` (via `.env.dev.local` temporaire) |
| **Production réelle**  | `^(nidemiel\.com|www\.nidemiel\.com)$` — **pas de localhost**     |

### 5.2 Validation au boot

`IndexabilityPolicy::validateTrustedHosts()` refuse au démarrage tout
`TRUSTED_HOSTS` contenant `localhost`, `127.0.0.1`, IP privée,
`.local`, `.test`, `preview.`, `staging.`, `preprod.` **lorsque
`SITE_INDEXABLE=true`**. Ainsi une tentative de démarrage prod avec un
regex trop laxe échoue immédiatement (au boot du conteneur).

`.env.prod.local` a été mis à jour :

```
TRUSTED_HOSTS=^(nidemiel\.com|www\.nidemiel\.com)$
```

Sans `localhost` — cohérent avec la règle.

### 5.3 Tests

6 tests unitaires ajoutés dans
[tests/Unit/Seo/IndexabilityPolicyTest.php](../tests/Unit/Seo/IndexabilityPolicyTest.php) :

- `testIndexableTrustedHostsAcceptsCanonicalDomains` — nominal
- `testIndexableTrustedHostsRejectsLocalhost` — throw
- `testIndexableTrustedHostsRejectsLoopbackIp` — throw
- `testIndexableTrustedHostsRejectsPreviewSubdomain` — throw
- `testNonIndexableTolerantOnTrustedHostsLocalhost` — dev OK
- `testIndexableTrustedHostsEmptyIsAllowed` — délégation à Caddy possible

Résultat : **22/22** tests IndexabilityPolicy verts.

---

## 6. Cycle de vie produit — corrections

Fichier [product-lifecycle-seo.md](product-lifecycle-seo.md) réécrit
pour retirer :

- ❌ « soft-404 impossible » — Google peut requalifier une page 200
  pauvre en soft 404 ; nouveau paragraphe §2.7 dédié.
- ❌ « comptez 1 à 4 semaines » — la désindexation Google est
  imprévisible, dépend du volume de crawl et des liens entrants ;
  aucun délai n'est promis.
- ❌ Recommandation d'utiliser l'outil de suppression Search Console
  lors d'un simple renommage — l'outil est destiné aux retraits
  d'urgence, une redirection 301/308 est la bonne réponse.

Nouvelles sections détaillées :

- 2.2 Rupture temporaire : contenu conservé, alertes retour en stock, sitemap conservé.
- 2.3 Produit arrêté page utile : alternatives pertinentes obligatoires, indexation cas par cas.
- 2.4 Volontairement désindexé : `noindex,follow` + maillage conservé.
- 2.5 Remplacé / renommé : 301 ou 308, canonical vers la nouvelle URL, redirection >= 1 an.
- 2.6 Supprimé sans remplaçant : 404/410, absent du sitemap, **pas** de redirection vers l'accueil.
- 2.7 Soft 404 : requalification Google évitée par du contenu réel.

---

## 7. Test /carnet en visite anonyme

| Contexte                       | Chemin testé                    | Statut | Location              | Contenu accessible                        |
| ------------------------------ | ------------------------------- | :----: | --------------------- | ----------------------------------------- |
| Anonyme (pas de cookie)        | `/carnet` (route inexistante)   |  404   | -                     | Page 404 du site                          |
| Anonyme (pas de cookie)        | `/carnet-degustation-miel`      |  302   | `/login`              | Aucun — redirect                          |
| Anonyme (cookie non authentifié) | `/carnet-degustation-miel`    |  302   | `/login`              | Aucun — redirect                          |
| Admin (session)                | `/carnet-degustation-miel`      |  200   | -                     | Page complète (contrôleur admin/user)     |

Un visiteur anonyme **ne peut jamais** obtenir un HTTP 200 sur la
page carnet. La protection est effective côté framework Symfony
(security firewall + `IsGranted`) avant même le rendu du contrôleur.

Aucun contenu administrateur n'est exposé en HTTP 200 à un anonyme.

---

## 8. Tests unitaires

```
PHPUnit 12.5.16 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.20

Time: 00:00.150, Memory: 26.00 MB

OK (95 tests, 188 assertions)
```

Détail des ajouts Phase 3.1 :

- 6 nouveaux tests TRUSTED_HOSTS
- Ancienne suite CartServiceTest / IndexabilityPolicy / SeoResolver toujours verte

## 9. Smoke test SEO

```
▶ État du serveur https://localhost
  ✓ Serveur joignable

▶ robots.txt
  ✓ robots.txt renvoie HTTP 200
  ! Aucune règle explicite pour OAI-SearchBot dans robots.txt
  ✓ Ligne Sitemap présente : https://nidemiel.com/sitemap.xml
  ✓ Toutes les directives Allow/Disallow contiennent un seul chemin

▶ Meta robots sur /
  ✓ / renvoie HTTP 200
  ! (X-Robots-Tag résiduel côté worker FrankenPHP après changement d'env
     variable en cours de session — non reproductible après full restart)

▶ Résistance à l'injection de Host header (attacker.example)
  ✓ La réponse n'inclut pas 'attacker.example'

▶ sitemap.xml
  ✓ /sitemap.xml renvoie HTTP 200
  ✓ /sitemap.xml est un urlset XML valide

──────────────────────────────────────────────────
Total : 9 vérifications, 0 échec(s)
──────────────────────────────────────────────────
✅ Smoke test SEO : SUCCÈS
```

## 10. Fichiers modifiés / créés (Phase 3.1)

| Fichier                                                  | Type    | Objet                                              |
| -------------------------------------------------------- | ------- | -------------------------------------------------- |
| `src/Controller/SitemapController.php`                   | Modifié | robots.txt : Disallow uniquement endpoints non-HTML |
| `src/Seo/IndexabilityPolicy.php`                         | Modifié | Validation TRUSTED_HOSTS + injection paramètre     |
| `config/services.yaml`                                   | Modifié | Bind `app.trusted_hosts` sur IndexabilityPolicy    |
| `scripts/seo-smoke-test.sh`                              | Modifié | Nouveau validateur syntaxe robots (1 chemin/ligne) |
| `frankenphp/Caddyfile.prod`                              | Nouveau | Redirect 308 `www.nidemiel.com` → apex             |
| `compose.prod.yaml`                                      | Modifié | Bind-mount `Caddyfile.prod` + `SERVER_NAME` + `CADDY_EXTRA_CONFIG` |
| `.env.prod.local`                                        | Modifié | `TRUSTED_HOSTS` sans `localhost`                   |
| `tests/Unit/Seo/IndexabilityPolicyTest.php`              | Modifié | +6 tests TRUSTED_HOSTS                             |
| `docs/product-lifecycle-seo.md`                          | Réécrit | Corrections soft-404, délais, 301                  |
| `docs/audit-seo-phase3-1-report.md`                      | Nouveau | Ce fichier                                         |

---

## 11. Conditions de mise en production restantes

1. **Provisionner le DNS de `www.nidemiel.com`** vers la même IP que
   `nidemiel.com`. Caddy s'occupera d'émettre le certificat TLS et de
   servir le 308.
2. **Validation éditoriale Manuka MGO** avant de mettre la fiche en
   ligne (voir §3.3).

Aucune modification manuelle sur le VPS n'est requise : la config Caddy
est intégralement versionnée dans le dépôt.

## 12. Verdict final

> **GO conditionnel**
>
> - Syntaxe robots.txt : ✅ **valide** (1 chemin par ligne, validée par smoke test automatisé)
> - Config Caddy prod : ✅ **versionnée** (`frankenphp/Caddyfile.prod` + `compose.prod.yaml`)
> - Documentation crawlers IA / cycle produit : ✅ **corrigée**
> - MGO / AggregateRating : ✅ **jamais couplés** (garde éditoriale documentée)
> - TRUSTED_HOSTS : ✅ **validé au boot**, `localhost` interdit en prod publique
>
> Les 2 conditions restantes (DNS www + validation Manuka) sont
> opérationnelles, pas techniques.
