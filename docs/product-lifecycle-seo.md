# Cycle de vie d'un produit — implications SEO

Ce document décrit les états possibles d'un produit dans Ziyada, les
répercussions sur l'expérience client et sur le SEO (sitemap, JSON-LD,
`meta robots`, statut HTTP), ainsi que les procédures recommandées côté
administrateur.

Il complète [seo-guide.md](seo-guide.md) et sert de référence pour l'audit
final Phase 3.

---

## 1. Vue d'ensemble

Un produit passe par plusieurs états au fil de sa vie commerciale :

| État                         | `isAvailable` | `stock` | `isSeoNoindex` | Ligne en base ? |
| ---------------------------- | :-----------: | :-----: | :------------: | :-------------: |
| En vente, en stock           |     true      |   > 0   |     false      |       oui       |
| Rupture temporaire           |     true      |    0    |     false      |       oui       |
| Produit arrêté, page utile   |     false     |    -    |     false      |       oui       |
| Volontairement désindexé     |     false     |    -    |      true      |       oui       |
| Remplacé / renommé           |       -       |    -    |       -        |       non       |
| Supprimé sans remplaçant     |       -       |    -    |       -        |       non       |

Les colonnes déterminent le comportement des composants SEO :

- **Sitemap** — inclut uniquement les produits dont `isAvailable = true`
  et `isSeoNoindex = false` (voir
  [SitemapController.php:79](../src/Controller/SitemapController.php#L79)).
- **`meta robots`** — passe à `noindex,follow` quand `isSeoNoindex = true`
  (via `IndexabilityPolicy::resolveRobots`).
- **JSON-LD `availability`** — `InStock` si `isAvailable && stock > 0`,
  sinon `OutOfStock` (voir
  [SeoResolver.php:329](../src/Seo/SeoResolver.php#L329)).
- **Route front `app_product_by_slug`** — répond 200 tant que la ligne
  existe en base ; 404 réel si `findOneBySlugWithRelations()` renvoie
  `null` (voir [ProductController.php:51-55](../src/Controller/ProductController.php#L51-L55)).

---

## 2. Détail par état

### 2.1 En vente, en stock — nominal

- **Fiche produit** : HTTP 200, bouton « Ajouter au panier » actif.
- **JSON-LD** : `availability: InStock`, `price` avec `getEffectivePrice()`.
- **Sitemap** : inclus, `<priority>0.9</priority>`, `<changefreq>weekly</changefreq>`.
- **`meta robots`** : `index,follow`.

### 2.2 Rupture temporaire (`stock = 0`, `isAvailable = true`)

- **HTTP 200**.
- **`meta robots`** : `index,follow`.
- **JSON-LD** : `availability: OutOfStock`. Ne pas retirer `offers.price`,
  Google exige un prix pour les rich results.
- **Sitemap** : **conservé**. Une rupture temporaire n'entraîne pas de
  retrait du sitemap ([recommandation Google](https://developers.google.com/search/docs/appearance/structured-data/product?hl=fr#out-of-stock)).
- **Contenu complet conservé** (description, images, avis, alternatives) :
  une page trop pauvre peut être requalifiée en soft 404 par Google.
- Bouton d'achat remplacé par un formulaire d'alerte de retour en stock
  (`StockAlertRepository`), pas par un simple message d'indisponibilité.

### 2.3 Produit arrêté mais page encore utile (`isAvailable = false`)

- **HTTP 200**.
- **`meta robots`** : `index,follow` par défaut ; indexation décidée au
  cas par cas selon la valeur éditoriale résiduelle de la page.
- **JSON-LD** : `availability: OutOfStock`.
- **Sitemap** : **exclu** (`findBy(['isAvailable' => true])` dans
  `SitemapController`).
- **Contenu complet conservé** + message clair d'arrêt.
- **Alternatives pertinentes** en bloc : produits liés, catégorie parente,
  guide sensoriel correspondant.
- Une page 200 pauvre (message d'erreur unique, contenu quasi-vide,
  aucune alternative) risque d'être classifiée soft 404 par Google.

### 2.4 Volontairement désindexé mais encore accessible (`isSeoNoindex = true`)

- **HTTP 200**.
- **`meta robots`** : `noindex,follow` — Google peut suivre les liens
  internes (utile si la page pointe encore vers du contenu indexable).
- **Sitemap** : exclu (double condition dans
  [SitemapController.php:80](../src/Controller/SitemapController.php#L80)).
- **Contenu et liens internes conservés** si la page reste publique
  (SEO interne, maillage).

### 2.5 Produit remplacé ou renommé

- **Redirection permanente 301 ou 308** de l'ancienne URL vers la
  nouvelle. Le 308 préserve la méthode HTTP mais 301 est plus largement
  supporté ; les deux sont équivalents pour Google.
- La nouvelle URL a un canonical qui pointe vers **elle-même**.
- Tous les liens internes (menus, blog, guide sensoriel, catégories)
  mis à jour pour pointer directement vers la nouvelle URL — la
  redirection est un filet de sécurité, pas un raccourci pour éviter
  de mettre à jour le maillage.
- L'ancienne URL est **absente du sitemap**.
- **Ne pas utiliser** l'outil de suppression Search Console : il est
  destiné aux retraits d'urgence, pas aux changements d'URL, et masque
  le contenu même si la redirection est correctement mise en place.
- **Redirection conservée** au minimum un an, idéalement en permanence
  tant que des liens externes ou index anciens pointent vers l'ancienne
  URL.

Ziyada n'a pas de mécanisme automatique de redirection sur
renommage. Procédure manuelle :

1. Créer la nouvelle fiche (nouveau slug).
2. Ajouter la redirection dans le bloc de site de
   [frankenphp/Caddyfile](../frankenphp/Caddyfile) (à côté de la redirection www) :

   ```caddy
   redir /miels/ancien-slug /miels/nouveau-slug 308
   ```

3. Mettre à jour tous les liens internes.
4. Redéployer.

À terme : envisager une table `UrlRedirect { source, destination, code }`
gérée depuis l'admin si les renommages deviennent fréquents.

### 2.6 Produit supprimé sans remplaçant

- **HTTP 404** (par défaut dans Ziyada via
  [ProductController.php:54](../src/Controller/ProductController.php#L54))
  **ou HTTP 410** si l'on veut accélérer la désindexation. Actuellement
  seul 404 est implémenté.
- Absent du sitemap.
- **Aucune redirection vers l'accueil, la catégorie ou un produit sans
  rapport** : Google traite les redirections non pertinentes comme des
  soft 404 et pénalise le domaine émetteur.

### 2.7 Soft 404 — attention

Google peut requalifier une page HTTP 200 en soft 404 si son contenu
ressemble à une erreur : message générique unique, absence d'alternatives,
contenu très pauvre. Cette requalification bloque l'indexation. Pour
l'éviter, garder du contenu réel sur toute page 200 même en état
d'indisponibilité (2.2, 2.3, 2.4).

---

## 3. Matrice de décision côté admin

Quand tu veux :

| Objectif                                       | Action                                                |
| ---------------------------------------------- | ----------------------------------------------------- |
| Rupture temporaire, garder SEO                 | `stock = 0`, laisser `isAvailable = true`             |
| Arrêter le produit mais garder l'URL utile     | `isAvailable = false` + contenu + alternatives        |
| Désindexer sans supprimer la page              | `isSeoNoindex = true`                                 |
| Renommer / remplacer                           | Nouveau slug + `redir` 308 dans `Caddyfile`           |
| Supprimer définitivement                       | DELETE en base (via l'admin EasyAdmin)                |

---

## 4. Vérifications automatisées

Deux scripts couvrent le contrôle continu de cette matrice :

- [scripts/sitemap-audit.php](../scripts/sitemap-audit.php) — parcourt le
  sitemap, vérifie qu'aucun URL ne renvoie 404/301 vers un autre domaine,
  qu'aucun `noindex` n'y traîne, qu'aucun canonical ne pointe ailleurs.
  Exit non nul si anomalie.
- [scripts/seo-smoke-test.sh](../scripts/seo-smoke-test.sh) — vérifie
  cohérence entre `SITE_INDEXABLE`, `robots.txt` (dont validation
  syntaxique stricte : un chemin par ligne `Allow/Disallow`), `meta robots`,
  et résistance à l'injection de Host header.

À lancer :

- après tout gros nettoyage catalogue,
- dans un cron mensuel de contrôle SEO,
- avant chaque déploiement en production.
