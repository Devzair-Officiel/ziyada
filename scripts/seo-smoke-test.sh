#!/usr/bin/env bash
#
# Smoke test SEO — Gate de déploiement strict.
#
# Ce script est un GATE : il refuse de démarrer sans intention explicite
# (variables d'environnement obligatoires) et échoue DUR (exit 1) sur toute
# incohérence entre la configuration attendue et ce que renvoie l'app.
#
# Il ne connaît pas la valeur de SITE_INDEXABLE dans l'app — c'est l'opérateur
# qui déclare ce qu'il ATTEND, et le script valide que la surface HTTP est
# cohérente avec cette attente.
#
# Variables d'environnement OBLIGATOIRES :
#   EXPECTED_INDEXABLE  : "true" ou "false" (strict, aucune autre valeur).
#   EXPECTED_CANONICAL  : URL absolue attendue pour les canonicals et JSON-LD
#                         (ex. https://nidemiel.com). Requise même en mode
#                         non-indexable pour vérifier qu'aucune URL ne fuit.
#
# Variables optionnelles :
#   BASE_URL     : URL à tester (défaut https://localhost).
#   FAKE_HOST    : Host injecté pour tester la résistance (défaut attacker.example).
#   PRODUCT_PATH : chemin d'une fiche produit à auditer (défaut : /miels).
#   HOST_HEADER  : Host à envoyer sur toutes les requêtes (utile en local pour
#                  bypasser TRUSTED_HOSTS sans désactiver le validateur).
#
# Usage :
#   EXPECTED_INDEXABLE=true \
#   EXPECTED_CANONICAL=https://nidemiel.com \
#   BASE_URL=https://localhost \
#   HOST_HEADER=nidemiel.com \
#   scripts/seo-smoke-test.sh

set -uo pipefail

# ─────────────────────────────────────────────────────────────────────────────
# Validation des variables obligatoires — refuse de démarrer sinon.
# ─────────────────────────────────────────────────────────────────────────────

if [ -z "${EXPECTED_INDEXABLE:-}" ]; then
    printf 'ERREUR: variable EXPECTED_INDEXABLE non définie. Valeurs autorisées : "true" ou "false".\n' >&2
    exit 2
fi

case "$EXPECTED_INDEXABLE" in
    true|false) ;;
    *)
        printf 'ERREUR: EXPECTED_INDEXABLE="%s" invalide. Valeurs autorisées : "true" ou "false".\n' "$EXPECTED_INDEXABLE" >&2
        exit 2
        ;;
esac

if [ -z "${EXPECTED_CANONICAL:-}" ]; then
    printf 'ERREUR: variable EXPECTED_CANONICAL non définie (ex. https://nidemiel.com).\n' >&2
    exit 2
fi

case "$EXPECTED_CANONICAL" in
    https://*) ;;
    *)
        printf 'ERREUR: EXPECTED_CANONICAL="%s" doit commencer par "https://".\n' "$EXPECTED_CANONICAL" >&2
        exit 2
        ;;
esac

# Retirer un éventuel slash final pour la comparaison.
EXPECTED_CANONICAL="${EXPECTED_CANONICAL%/}"

BASE_URL="${BASE_URL:-https://localhost}"
FAKE_HOST="${FAKE_HOST:-attacker.example}"
PRODUCT_PATH="${PRODUCT_PATH:-/miels}"
HOST_HEADER="${HOST_HEADER:-}"

# ─────────────────────────────────────────────────────────────────────────────
# Sortie couleur + compteurs
# ─────────────────────────────────────────────────────────────────────────────

RED=$'\033[0;31m'
GREEN=$'\033[0;32m'
YELLOW=$'\033[0;33m'
BLUE=$'\033[0;34m'
NC=$'\033[0m'

FAILURES=0
CHECKS=0

pass() {
    CHECKS=$((CHECKS + 1))
    printf '  %s✓%s %s\n' "$GREEN" "$NC" "$1"
}

fail() {
    CHECKS=$((CHECKS + 1))
    FAILURES=$((FAILURES + 1))
    printf '  %s✗%s %s\n' "$RED" "$NC" "$1"
}

info() {
    printf '  %si%s %s\n' "$BLUE" "$NC" "$1"
}

header() {
    printf '\n%s▶ %s%s\n' "$YELLOW" "$1" "$NC"
}

# ─────────────────────────────────────────────────────────────────────────────
# Fichiers temporaires (headers/body séparés) + cleanup automatique.
# ─────────────────────────────────────────────────────────────────────────────

TMPDIR="$(mktemp -d -t seo-smoke.XXXXXX)"
trap 'rm -rf "$TMPDIR"' EXIT INT TERM

# fetch <slot> <url> [extra curl args...]
# Écrit headers dans "$TMPDIR/<slot>.headers" et body dans "$TMPDIR/<slot>.body".
# Retourne le code HTTP sur stdout.
fetch() {
    local slot="$1"
    local url="$2"
    shift 2
    local headers_file="$TMPDIR/${slot}.headers"
    local body_file="$TMPDIR/${slot}.body"

    local host_args=()
    if [ -n "$HOST_HEADER" ]; then
        host_args=(-H "Host: ${HOST_HEADER}")
    fi

    curl -k -s -o "$body_file" -D "$headers_file" \
        -w '%{http_code}' \
        "${host_args[@]}" \
        "$@" \
        "$url"
}

http_status() {
    local slot="$1"
    awk 'NR==1 { for (i=1;i<=NF;i++) if ($i ~ /^[0-9][0-9][0-9]$/) { print $i; exit } }' "$TMPDIR/${slot}.headers"
}

body_file() {
    printf '%s/%s.body' "$TMPDIR" "$1"
}

headers_file() {
    printf '%s/%s.headers' "$TMPDIR" "$1"
}

# ─────────────────────────────────────────────────────────────────────────────
# Contexte
# ─────────────────────────────────────────────────────────────────────────────

printf '%s══════════════════════════════════════════════════%s\n' "$BLUE" "$NC"
printf 'SEO smoke test — gate de déploiement\n'
printf '  BASE_URL           = %s\n' "$BASE_URL"
printf '  EXPECTED_INDEXABLE = %s\n' "$EXPECTED_INDEXABLE"
printf '  EXPECTED_CANONICAL = %s\n' "$EXPECTED_CANONICAL"
printf '  HOST_HEADER        = %s\n' "${HOST_HEADER:-(none)}"
printf '  PRODUCT_PATH       = %s\n' "$PRODUCT_PATH"
printf '%s══════════════════════════════════════════════════%s\n' "$BLUE" "$NC"

# ─────────────────────────────────────────────────────────────────────────────
# Étape 1 : serveur joignable
# ─────────────────────────────────────────────────────────────────────────────

header "État du serveur ${BASE_URL}"

home_status=$(fetch home "${BASE_URL}/")
if printf '%s' "$home_status" | grep -qE '^(200|301|302|308)$'; then
    pass "Serveur joignable (HTTP $home_status)"
else
    fail "Serveur non joignable sur ${BASE_URL}/ (HTTP $home_status)"
    printf '%s%s❌ ARRÊT : impossible de continuer sans réponse serveur%s\n' "\n" "$RED" "$NC"
    exit 1
fi

# ─────────────────────────────────────────────────────────────────────────────
# Étape 2 : robots.txt
# ─────────────────────────────────────────────────────────────────────────────

header "robots.txt"

robots_status=$(fetch robots "${BASE_URL}/robots.txt")

if [ "$robots_status" = "200" ]; then
    pass "robots.txt renvoie HTTP 200"
else
    fail "robots.txt renvoie HTTP $robots_status (attendu 200)"
fi

robots_body="$(body_file robots)"

# Mode-spécifique : SITE_INDEXABLE=false doit produire "Disallow: /" pour User-agent: *
if [ "$EXPECTED_INDEXABLE" = "false" ]; then
    if awk '
        /^[[:space:]]*User-agent:[[:space:]]*\*/ { in_star=1; next }
        /^[[:space:]]*User-agent:/ { in_star=0; next }
        in_star && /^[[:space:]]*Disallow:[[:space:]]*\/[[:space:]]*(#|$)/ { found=1 }
        END { exit(found ? 0 : 1) }
    ' "$robots_body"; then
        pass "User-agent: * / Disallow: / présent (blocage global cohérent)"
    else
        fail "Mode non-indexable : User-agent: * doit contenir Disallow: /"
    fi
else
    # SITE_INDEXABLE=true : refuser un Disallow: / pour User-agent: *
    if awk '
        /^[[:space:]]*User-agent:[[:space:]]*\*/ { in_star=1; next }
        /^[[:space:]]*User-agent:/ { in_star=0; next }
        in_star && /^[[:space:]]*Disallow:[[:space:]]*\/[[:space:]]*(#|$)/ { found=1 }
        END { exit(found ? 0 : 1) }
    ' "$robots_body"; then
        fail "Mode indexable : User-agent: * / Disallow: / présent — bloque toute l'indexation"
    else
        pass "User-agent: * n'a pas de Disallow: / (indexation autorisée)"
    fi

    # En mode indexable, une ligne Sitemap est OBLIGATOIRE.
    if grep -qE '^[[:space:]]*Sitemap:[[:space:]]+' "$robots_body"; then
        sitemap_line=$(grep -m1 -E '^[[:space:]]*Sitemap:' "$robots_body" | tr -d '\r')
        pass "Ligne Sitemap présente : ${sitemap_line}"

        sitemap_url=$(printf '%s' "$sitemap_line" | awk '{print $2}')
        case "$sitemap_url" in
            "$EXPECTED_CANONICAL"/*)
                pass "URL Sitemap sur le domaine canonique attendu"
                ;;
            *)
                fail "URL Sitemap '$sitemap_url' n'est pas sur '$EXPECTED_CANONICAL'"
                ;;
        esac
    else
        fail "Mode indexable : ligne 'Sitemap: <url>' manquante dans robots.txt"
    fi
fi

# Validation syntaxique commune : une seule cible par ligne Allow/Disallow,
# en ignorant les commentaires "# ..." sur la ligne.
syntax_errors=$(awk '
    {
        line = $0
        sub(/[[:space:]]*#.*/, "", line)   # strip inline comments
        sub(/\r$/, "", line)
    }
    line ~ /^[[:space:]]*(Allow|Disallow):/ {
        path = line
        sub(/^[[:space:]]*(Allow|Disallow):[[:space:]]*/, "", path)
        sub(/[[:space:]]+$/, "", path)
        # Un espace résiduel = plusieurs chemins ou path avec espace non échappé
        if (path ~ /[[:space:]]/) {
            print NR ": " line
        }
    }
' "$robots_body")

if [ -z "$syntax_errors" ]; then
    pass "Toutes les directives Allow/Disallow contiennent un seul chemin"
else
    fail "Syntaxe robots.txt invalide (plusieurs chemins par ligne) :"
    printf '%s\n' "$syntax_errors" | while IFS= read -r line; do
        printf '        → %s\n' "$line"
    done
fi

# OAI-SearchBot ne doit jamais être bloqué (recommandation OpenAI 2025).
if grep -qE '^[[:space:]]*User-agent:[[:space:]]*OAI-SearchBot' "$robots_body"; then
    if awk '
        /^[[:space:]]*User-agent:[[:space:]]*OAI-SearchBot/ { in_bot=1; next }
        /^[[:space:]]*User-agent:/ { in_bot=0; next }
        in_bot && /^[[:space:]]*Disallow:[[:space:]]*\/[[:space:]]*(#|$)/ { found=1 }
        END { exit(found ? 0 : 1) }
    ' "$robots_body"; then
        fail "OAI-SearchBot bloqué (Disallow: /) — ChatGPT Search coupé"
    else
        pass "OAI-SearchBot n'est pas bloqué globalement"
    fi
else
    info "Pas de bloc dédié OAI-SearchBot (comportement par défaut = autorisé)"
fi

# ─────────────────────────────────────────────────────────────────────────────
# Étape 3 : / — meta robots, X-Robots-Tag, canonical, og:url, JSON-LD
# ─────────────────────────────────────────────────────────────────────────────

header "Home : meta robots et URLs canoniques"

home_status=$(fetch home "${BASE_URL}/")
if [ "$home_status" = "200" ]; then
    pass "/ renvoie HTTP 200"
else
    fail "/ renvoie HTTP $home_status (attendu 200)"
fi

home_body="$(body_file home)"
home_headers="$(headers_file home)"

# meta robots doit correspondre au mode attendu.
meta_robots=$(grep -oiE 'meta[[:space:]]+name="robots"[[:space:]]+content="[^"]+"' "$home_body" | head -1)
if [ -z "$meta_robots" ]; then
    fail "Aucune balise <meta name=\"robots\"> détectée sur /"
else
    meta_content=$(printf '%s' "$meta_robots" | sed -E 's/.*content="([^"]+)".*/\1/')
    if [ "$EXPECTED_INDEXABLE" = "false" ]; then
        if printf '%s' "$meta_content" | grep -qi 'noindex'; then
            pass "meta robots = '${meta_content}' (contient noindex, cohérent)"
        else
            fail "meta robots = '${meta_content}' — attendu 'noindex' en mode non-indexable"
        fi
    else
        if printf '%s' "$meta_content" | grep -qi 'noindex'; then
            fail "meta robots = '${meta_content}' — contient noindex alors qu'on est en mode indexable"
        else
            pass "meta robots = '${meta_content}' (indexation cohérente)"
        fi
    fi
fi

# X-Robots-Tag résiduel : interdit en mode indexable s'il contient noindex.
xrobots=$(grep -i '^x-robots-tag:' "$home_headers" | tr -d '\r' | head -1)
if [ -n "$xrobots" ]; then
    if [ "$EXPECTED_INDEXABLE" = "true" ] && printf '%s' "$xrobots" | grep -qi 'noindex'; then
        fail "Header résiduel : ${xrobots} — bloque l'indexation en mode indexable"
    else
        info "Header ${xrobots}"
    fi
fi

# Extraction canonical / og:url — présents + valeurs cohérentes.
canonical=$(grep -oiE '<link[[:space:]]+rel="canonical"[[:space:]]+href="[^"]+"' "$home_body" | head -1 | sed -E 's/.*href="([^"]+)".*/\1/')
og_url=$(grep -oiE '<meta[[:space:]]+property="og:url"[[:space:]]+content="[^"]+"' "$home_body" | head -1 | sed -E 's/.*content="([^"]+)".*/\1/')

if [ -z "$canonical" ]; then
    fail "Aucune balise <link rel=\"canonical\"> détectée sur /"
else
    case "$canonical" in
        "$EXPECTED_CANONICAL"|"$EXPECTED_CANONICAL"/*)
            pass "Canonical sur / = ${canonical}"
            ;;
        *)
            fail "Canonical sur / = '${canonical}' — attendu débute par '${EXPECTED_CANONICAL}'"
            ;;
    esac
fi

if [ -z "$og_url" ]; then
    fail "Aucune balise og:url détectée sur /"
else
    case "$og_url" in
        "$EXPECTED_CANONICAL"|"$EXPECTED_CANONICAL"/*)
            pass "og:url sur / = ${og_url}"
            ;;
        *)
            fail "og:url sur / = '${og_url}' — attendu débute par '${EXPECTED_CANONICAL}'"
            ;;
    esac
fi

# JSON-LD : toutes les URLs (http(s)) doivent commencer par EXPECTED_CANONICAL
# ou provenir d'un domaine tiers explicitement autorisé (schemas.org).
jsonld_urls_file="$TMPDIR/home.jsonld_urls"
awk '
    /<script[^>]*type="application\/ld\+json"/ { grab=1; sub(/.*<script[^>]*>/, ""); }
    grab {
        block = block $0 "\n"
        if (/<\/script>/) {
            sub(/<\/script>.*/, "", block)
            print block
            block = ""
            grab = 0
        }
    }
' "$home_body" \
    | grep -oE 'https?://[^"[:space:]<>]+' \
    | sort -u > "$jsonld_urls_file" 2>/dev/null || true

if [ ! -s "$jsonld_urls_file" ]; then
    info "Aucun bloc JSON-LD détecté sur /"
else
    bad_jsonld=0
    while IFS= read -r url; do
        case "$url" in
            "$EXPECTED_CANONICAL"|"$EXPECTED_CANONICAL"/*) ;;
            https://schema.org*|http://schema.org*) ;;
            https://www.w3.org*|http://www.w3.org*) ;;
            *)
                fail "JSON-LD sur / contient une URL hors domaine : ${url}"
                bad_jsonld=$((bad_jsonld + 1))
                ;;
        esac
    done < "$jsonld_urls_file"
    if [ "$bad_jsonld" -eq 0 ]; then
        pass "Toutes les URLs JSON-LD sur / sont sur ${EXPECTED_CANONICAL} (ou schema.org)"
    fi
fi

# ─────────────────────────────────────────────────────────────────────────────
# Étape 4 : fiche produit — mêmes contrôles URL
# ─────────────────────────────────────────────────────────────────────────────

header "Fiche produit ${PRODUCT_PATH} : canonicals & JSON-LD"

# Suivre les redirections pour tomber sur une vraie fiche (on veut auditer la
# page finale, pas la redirection de courtoisie).
product_status=$(fetch product "${BASE_URL}${PRODUCT_PATH}" -L)

if [ "$product_status" = "200" ]; then
    pass "${PRODUCT_PATH} (avec suivi de redirections) renvoie HTTP 200"

    product_body="$(body_file product)"

    p_canonical=$(grep -oiE '<link[[:space:]]+rel="canonical"[[:space:]]+href="[^"]+"' "$product_body" | head -1 | sed -E 's/.*href="([^"]+)".*/\1/')
    p_ogurl=$(grep -oiE '<meta[[:space:]]+property="og:url"[[:space:]]+content="[^"]+"' "$product_body" | head -1 | sed -E 's/.*content="([^"]+)".*/\1/')

    if [ -z "$p_canonical" ]; then
        fail "Aucun canonical sur ${PRODUCT_PATH}"
    else
        case "$p_canonical" in
            "$EXPECTED_CANONICAL"|"$EXPECTED_CANONICAL"/*)
                pass "Canonical produit = ${p_canonical}"
                ;;
            *)
                fail "Canonical produit = '${p_canonical}' — attendu débute par '${EXPECTED_CANONICAL}'"
                ;;
        esac
    fi

    if [ -z "$p_ogurl" ]; then
        fail "Aucun og:url sur ${PRODUCT_PATH}"
    else
        case "$p_ogurl" in
            "$EXPECTED_CANONICAL"|"$EXPECTED_CANONICAL"/*)
                pass "og:url produit = ${p_ogurl}"
                ;;
            *)
                fail "og:url produit = '${p_ogurl}' — attendu débute par '${EXPECTED_CANONICAL}'"
                ;;
        esac
    fi

    p_jsonld_urls_file="$TMPDIR/product.jsonld_urls"
    awk '
        /<script[^>]*type="application\/ld\+json"/ { grab=1; sub(/.*<script[^>]*>/, ""); }
        grab {
            block = block $0 "\n"
            if (/<\/script>/) {
                sub(/<\/script>.*/, "", block)
                print block
                block = ""
                grab = 0
            }
        }
    ' "$product_body" \
        | grep -oE 'https?://[^"[:space:]<>]+' \
        | sort -u > "$p_jsonld_urls_file" 2>/dev/null || true

    if [ ! -s "$p_jsonld_urls_file" ]; then
        info "Aucun bloc JSON-LD détecté sur ${PRODUCT_PATH}"
    else
        p_bad=0
        while IFS= read -r url; do
            case "$url" in
                "$EXPECTED_CANONICAL"|"$EXPECTED_CANONICAL"/*) ;;
                https://schema.org*|http://schema.org*) ;;
                https://www.w3.org*|http://www.w3.org*) ;;
                *)
                    fail "JSON-LD produit contient une URL hors domaine : ${url}"
                    p_bad=$((p_bad + 1))
                    ;;
            esac
        done < "$p_jsonld_urls_file"
        if [ "$p_bad" -eq 0 ]; then
            pass "Toutes les URLs JSON-LD produit sont sur ${EXPECTED_CANONICAL} (ou schema.org)"
        fi
    fi
elif [ "$product_status" = "404" ] || [ "$product_status" = "301" ] || [ "$product_status" = "302" ]; then
    info "${PRODUCT_PATH} renvoie HTTP ${product_status} (audit fiche skipé — catalogue peut être vide)"
else
    fail "${PRODUCT_PATH} renvoie HTTP $product_status (inattendu)"
fi

# ─────────────────────────────────────────────────────────────────────────────
# Étape 5 : résistance à l'injection de Host header
# ─────────────────────────────────────────────────────────────────────────────

header "Résistance à l'injection de Host header (${FAKE_HOST})"

# On force la substitution du Host côté HTTP tout en gardant SNI/certif intacts.
# Note : on n'utilise pas fetch() car on veut REMPLACER (pas ajouter) le Host.
spoof_headers="$TMPDIR/spoof.headers"
spoof_body="$TMPDIR/spoof.body"
spoof_status=$(curl -k -s -o "$spoof_body" -D "$spoof_headers" -w '%{http_code}' \
    -H "Host: ${FAKE_HOST}" \
    "${BASE_URL}/")

if [ "$spoof_status" = "421" ] || [ "$spoof_status" = "400" ]; then
    pass "Host header rejeté avec HTTP ${spoof_status} (trusted_hosts ou SNI actif)"
elif [ "$EXPECTED_INDEXABLE" = "true" ]; then
    # En prod : les URLs canoniques doivent rester sur EXPECTED_CANONICAL.
    if grep -qF "$FAKE_HOST" "$spoof_body"; then
        fail "Réponse spoofée contient '${FAKE_HOST}' — canonical / JSON-LD compromis"
    else
        pass "Réponse spoofée n'inclut jamais '${FAKE_HOST}'"
    fi

    spoof_canonical=$(grep -oiE '<link[[:space:]]+rel="canonical"[[:space:]]+href="[^"]+"' "$spoof_body" | head -1 | sed -E 's/.*href="([^"]+)".*/\1/')
    if [ -n "$spoof_canonical" ]; then
        case "$spoof_canonical" in
            "$EXPECTED_CANONICAL"|"$EXPECTED_CANONICAL"/*)
                pass "Canonical résiste au spoof : ${spoof_canonical}"
                ;;
            *)
                fail "Canonical sous spoof = '${spoof_canonical}' — attendu débute par '${EXPECTED_CANONICAL}'"
                ;;
        esac
    fi
else
    # En dev/preview, on tolère un rendu partiel mais on refuse quand même
    # une pollution du HTML par le FAKE_HOST.
    if grep -qF "$FAKE_HOST" "$spoof_body"; then
        fail "Réponse spoofée contient '${FAKE_HOST}' — Host header pollue le HTML"
    else
        pass "Réponse spoofée n'inclut pas '${FAKE_HOST}'"
    fi
fi

# ─────────────────────────────────────────────────────────────────────────────
# Étape 6 : sitemap.xml
# ─────────────────────────────────────────────────────────────────────────────

header "sitemap.xml"

sitemap_status=$(fetch sitemap "${BASE_URL}/sitemap.xml")
sitemap_body="$(body_file sitemap)"

if [ "$EXPECTED_INDEXABLE" = "true" ]; then
    if [ "$sitemap_status" = "200" ]; then
        pass "/sitemap.xml renvoie HTTP 200"

        if grep -q '<urlset' "$sitemap_body"; then
            pass "/sitemap.xml est un urlset XML valide"
        else
            fail "/sitemap.xml ne contient pas <urlset>"
        fi

        if grep -qF "$FAKE_HOST" "$sitemap_body"; then
            fail "/sitemap.xml contient le Host fictif '${FAKE_HOST}'"
        else
            pass "/sitemap.xml ne contient pas '${FAKE_HOST}'"
        fi

        # Toutes les <loc> doivent être sur EXPECTED_CANONICAL.
        bad_loc=$(grep -oE '<loc>[^<]+</loc>' "$sitemap_body" \
            | sed -E 's|<loc>([^<]+)</loc>|\1|' \
            | grep -vE "^${EXPECTED_CANONICAL//./\\.}(/|$)" | head -5 || true)
        if [ -n "$bad_loc" ]; then
            fail "sitemap.xml contient des URLs hors '${EXPECTED_CANONICAL}' :"
            printf '%s\n' "$bad_loc" | while IFS= read -r u; do
                printf '        → %s\n' "$u"
            done
        else
            pass "Toutes les <loc> du sitemap sont sur ${EXPECTED_CANONICAL}"
        fi
    else
        fail "Mode indexable : /sitemap.xml doit renvoyer 200 (reçu $sitemap_status)"
    fi
else
    # SITE_INDEXABLE=false : le sitemap peut être 404 (comportement idéal),
    # 200 avec un fichier vide, ou renvoyer une liste minime. On refuse par contre
    # tout leak de host fictif si le body existe.
    if [ "$sitemap_status" = "404" ]; then
        pass "/sitemap.xml renvoie 404 (cohérent avec SITE_INDEXABLE=false)"
    elif [ "$sitemap_status" = "200" ]; then
        info "/sitemap.xml existe en mode non-indexable (HTTP 200) — vérification anti-leak"
        if grep -qF "$FAKE_HOST" "$sitemap_body"; then
            fail "/sitemap.xml contient le Host fictif '${FAKE_HOST}'"
        else
            pass "/sitemap.xml ne contient pas '${FAKE_HOST}'"
        fi
    else
        fail "/sitemap.xml renvoie HTTP $sitemap_status (inattendu)"
    fi
fi

# ─────────────────────────────────────────────────────────────────────────────
# Résumé et verdict
# ─────────────────────────────────────────────────────────────────────────────

printf '\n──────────────────────────────────────────────────\n'
printf 'Total : %d vérifications, %d échec(s)\n' "$CHECKS" "$FAILURES"
printf '──────────────────────────────────────────────────\n'

if [ "$FAILURES" -gt 0 ]; then
    printf '%s❌ Smoke test SEO : ÉCHEC (mode attendu : %s)%s\n' "$RED" "$EXPECTED_INDEXABLE" "$NC"
    exit 1
fi

printf '%s✅ Smoke test SEO : SUCCÈS (mode attendu : %s)%s\n' "$GREEN" "$EXPECTED_INDEXABLE" "$NC"
exit 0
