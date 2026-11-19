# Produits, marque et thème — Ziyada

> Document de référence pour le catalogue, l'identité visuelle, le ton éditorial et la conformité des textes. **Version 1 (2026-11-19)**, établie à partir des visuels produits fournis. Les éléments marqués **(à valider)** sont des propositions ; le logo et la charte définitifs remplaceront les valeurs indicatives.

## 1. La marque

- **Nom** : Ziyada — **domaine** : `ziyada.fr`.
- **Univers** : produits bien-être naturels, avec une identité à la fois botanique et artisanale. Les étiquettes mettent en avant l'origine et le mode de fabrication (« saponification à froid », « pressée à froid », « d'Éthiopie », « montagnes de l'Altaï »).
- **Signature graphique** (relevée sur toutes les étiquettes) : le mot **ZIYADA** en capitales fines et espacées, surmonté d'un petit motif losange entre deux flèches, souligné d'un filet horizontal.
- **Cible** : particuliers (B2C), France métropolitaine, francophone.

## 2. Catalogue identifié

Relevé sur les 12 visuels fournis. Les intitulés sont ceux des étiquettes ; les prix, stocks, ingrédients et descriptions restent à renseigner (phase 1).

| Gamme | Produit (étiquette) | Format | Mention d'étiquette |
|---|---|---|---|
| Savons | Savon Nila & Ghassoul | pain de savon | « Éclaircissant visage et corps » · saponification à froid |
| Savons | Savon Nigelle & Charbon actif | pain de savon | « Purifiant visage et corps » · saponification à froid |
| Savons | Savon Café & Collagène | pain de savon | « Anti Âge visage et corps » · saponification à froid |
| Huiles | Huile de nigelle d'Éthiopie | flacon 100 ml | « 100 % naturelle — pressée à froid » |
| Résines | Shilajit pur des montagnes de l'Altaï | pot 50 g | — |
| Gélules | Gélules Costus indien | 60 gélules | — |
| Gélules | Gélules Spiruline | 60 gélules | — |
| Gélules | Gélules Shilajit | 60 gélules | — |
| Gélules | Gélules Moringa | 60 gélules | — |
| Capsules | Capsules à l'huile de nigelle | 60 capsules (flacon ambré) | — |
| Gummies | Gummies Collagène+ | 60 gummies | — |
| Gummies | Gummies Huile de nigelle | 60 gummies | — |
| Déodorants | *(références à fournir)* | — | — |

> ⚠️ Les mentions « éclaircissant » et « anti âge » sont des **allégations** : voir la section 7 avant de les reprendre sur le site.

### Arborescence de catégories proposée (à valider)

1. **Huiles** — huile de nigelle
2. **Gélules et capsules** — costus indien, spiruline, shilajit, moringa, capsules d'huile de nigelle
3. **Gummies** — collagène+, huile de nigelle
4. **Savons** — nila & ghassoul, nigelle & charbon actif, café & collagène
5. **Résines** — shilajit pur
6. **Déodorants**

Chaque catégorie aura une page avec une introduction courte et utile (SEO/GEO) et ses produits. Une catégorie ne doit pas être vide au lancement.

## 3. Identité visuelle (à valider avec le logo)

### Univers observé sur les packagings

- **Gamme « kraft » (savons, huile)** : étiquette crème/kraft, bleu nuit et or, fleurs de coton et petits motifs (flèches, attrape-rêves, mortier).
- **Gamme « noir et or » (gélules, capsules)** : étiquette noire, titres en or, filet blanc, motifs ethniques colorés.
- **Gummies** : étiquettes plus claires, sur fonds colorés (rose, beige).

Le site doit rester **sobre, chaleureux et naturel** : beaucoup d'espace, des fonds crème, peu de couleurs à la fois, les packshots au premier plan.

### Palette proposée (valeurs **indicatives**, à remplacer par la charte)

| Rôle | Couleur | Valeur indicative |
|---|---|---|
| Fond principal | Crème / ivoire | `#F6EFE3` |
| Fond de sections | Kraft clair | `#E9DCC6` |
| Couleur principale | Bleu nuit | `#1F2A5C` |
| Accent | Or | `#B58E3C` |
| Texte et contrastes forts | Noir chaud | `#1C1A17` |
| Succès / alerte / erreur | À définir selon le contraste | — |

Règles : **contraste suffisant** (WCAG AA : 4,5:1 pour le texte courant) ; l'or sert d'accent (filets, icônes, survols) et non de couleur de texte sur fond clair ; boutons principaux en bleu nuit.

### Typographie (à valider)

- Marque et titres : capitales fines et espacées pour le logotype ; un **serif élégant** pour les titres de sections.
- Texte courant : une **sans-serif lisible**.
- Contraintes techniques : **2 familles maximum**, fichiers `woff2` **hébergés sur le site** (performance et vie privée), `font-display: swap`, uniquement les graisses utilisées. Le dossier `public/assets/fonts/` pèse environ 7 Mo : à auditer et alléger (phase 2).

### Composants et gabarits (phase 2)

Variables CSS pour couleurs, typographies, espacements et rayons ; boutons, cartes produit, badges (nouveau, meilleure vente, rupture), formulaires, bandeau d'annonce, fil d'Ariane, avis (étoiles). Mobile d'abord. Les mêmes variables servent aux **e-mails** et à la **facture PDF**.

### Photographie

- Packshots existants : fond blanc (gélules), fond texturé gris-bleu avec éléments naturels (savons), fonds colorés (gummies). Garder une **cohérence** entre les fiches d'une même gamme.
- Format **carré**, **1200 × 1200 px minimum** ; exporter en haute qualité, le site génère les variantes WebP.
- Chaque image a un **texte alternatif descriptif** (produit, format, contexte), jamais du bourrage de mots-clés.
- Prévoir pour chaque produit : 1 packshot de face, 1 vue d'ambiance, 1 vue du contenu (gélules, savon, huile).

## 4. Ton éditorial

- **Clair, sobre, rassurant, honnête.** Phrases courtes, vocabulaire simple, vouvoiement.
- On décrit des **faits** : origine, méthode de fabrication, ingrédients, format, mode d'emploi, précautions. On évite les promesses.
- Pas de superlatifs non prouvés (« le meilleur », « miracle », « révolutionnaire »).

## 5. Gabarit d'une fiche produit (SEO + GEO)

Objectif : qu'un moteur ou une IA puisse extraire une **réponse directe** puis les détails.

1. **Titre** (`h1`) : nom du produit + format. Exemple : « Huile de nigelle d'Éthiopie — 100 ml ».
2. **Phrase de réponse** (première phrase de la description) : ce que c'est, de quoi c'est fait, comment, quel format. Exemple fondé sur l'étiquette : « L'huile de nigelle d'Éthiopie Ziyada est une huile 100 % naturelle, pressée à froid, en flacon de 100 ml. »
3. **Description factuelle** : origine, fabrication, particularités.
4. **Composition / ingrédients** (liste complète et exacte).
5. **Mode d'emploi** et **conservation**.
6. **Précautions d'emploi** (grossesse, enfants, allergies, avis médical selon le produit — formulation à valider).
7. **Informations pratiques** : contenance ou nombre d'unités, origine, labels réels, numéro de lot et date de durabilité (si géré).
8. **Questions fréquentes** du produit (balisage `FAQPage`).
9. **Avis clients** vérifiés (balisage `Review`), **produits associés**.

Title : « Nom du produit, format | Ziyada » (≈ 60 caractères). Meta description : 140–160 caractères, factuelle, sans allégation non validée.

## 6. Pages de contenu à prévoir

Accueil, catégories, **À propos** (histoire, valeurs, savoir-faire), **Sourcing et fabrication** (origine des matières, méthodes, contrôles, analyses), **FAQ générale** (commande, livraison, retours, conservation), **Livraison et retours**, **Contact**, **Blog** (guides et conseils d'usage factuels), pages légales (voir phase 3 du plan).

## 7. Allégations santé et conformité

> **Ce paragraphe n'est pas un avis juridique.** Il liste les points à vérifier et à faire valider (DGCCRF, avocat ou consultant en réglementation) **avant** la mise en vente et avant de publier les textes.

### Pourquoi c'est central

Les produits de bien-être sont encadrés : ce que l'on dit d'un produit (efficacité, bienfaits, résultats) est **réglementé**, et la même règle s'applique à l'étiquette, au site, aux réseaux sociaux et aux contenus de blog. Ces textes alimentent aussi le SEO et le GEO : un contenu exact, mesuré et sourcé est à la fois plus sûr et mieux repris par les IA.

### Points à vérifier

1. **Statut de chaque produit** : complément alimentaire (gélules, capsules, gummies, probablement shilajit et huile de nigelle selon l'usage annoncé), cosmétique (savons, déodorants, huile utilisée en soin de la peau ou des cheveux)… Le statut dépend du produit **et de l'usage annoncé**, et détermine toutes les règles qui suivent.
2. **Compléments alimentaires** : déclaration préalable auprès de la DGCCRF (téléservice dédié), ingrédients autorisés (plantes, substances, « nouveaux aliments »), mentions obligatoires, numéro de lot et date de durabilité minimale. Les allégations de santé sont limitées à celles autorisées au niveau européen (règlement (CE) n° 1924/2006) ; aucune allégation thérapeutique ou de guérison. **Vérifier le statut de chaque ingrédient** (notamment costus indien et shilajit).
3. **Cosmétiques** (règlement (CE) n° 1223/2009) : personne responsable désignée, évaluation de la sécurité, notification du produit, étiquetage (liste INCI, précautions, lot), allégations conformes aux critères communs européens (règlement (UE) n° 655/2013). Les allégations d'**éclaircissement** et d'**anti-âge** sont **sensibles** : à faire valider avant publication.
4. **Vente à distance** : mentions légales complètes, CGV, prix TTC, délais et frais de livraison, **droit de rétractation de 14 jours** (avec ses exceptions, par exemple certains produits d'hygiène descellés), garanties légales, médiation de la consommation.
5. **Données personnelles (RGPD)** : politique de confidentialité, consentement aux cookies et traceurs, registre des traitements, services tiers (Stripe, Google, reCAPTCHA, Sentry, statistiques).

### Règle d'écriture pour le site

| À éviter | À préférer |
|---|---|
| « Éclaircit la peau », « efface les rides » | Description des ingrédients, du procédé (« saponification à froid »), des conseils d'usage |
| « Soigne », « guérit », « traite » | Origine, composition, mode d'emploi |
| « Booste l'immunité », « fait maigrir » | Contenu d'un format, dosage par gélule, précautions |
| Témoignages affirmant un effet santé | Avis sur l'expérience d'achat, la texture, l'usage — modérés avant publication |
| Chiffres ou études non cités | Faits vérifiables et **sourcés** (analyse de laboratoire, certificat), avec la date |

En cas de doute : **ne pas publier l'allégation** et la soumettre pour validation (question ouverte n° 9 du plan).
