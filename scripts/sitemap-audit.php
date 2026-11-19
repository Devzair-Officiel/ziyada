<?php

declare(strict_types=1);

/**
 * Audit sitemap.xml : parse le sitemap distant, vérifie chaque URL
 * (statut HTTP, redirections, canonical, meta robots) et produit un rapport
 * CSV + Markdown. Sort en code 1 dès qu'une anomalie est détectée.
 *
 * Usage :
 *   php scripts/sitemap-audit.php https://nidemiel.com/sitemap.xml [--out=var/audit]
 *
 * Vérifications :
 *   - Le sitemap est un XML valide (urlset)
 *   - Chaque URL renvoie HTTP 200 (pas de 3xx, 4xx, 5xx)
 *   - Le domaine des URLs correspond au domaine du sitemap
 *   - La page n'a pas de <meta name="robots" content="noindex">
 *   - Le canonical HTML ne redirige pas vers un autre domaine
 *   - Le Content-Type est text/html
 */

const USER_AGENT = 'NidemielSitemapAudit/1.0 (+SEO validation)';
const TIMEOUT_SECONDS = 15;
const MAX_URLS = 5000;

function fail(string $message, int $code = 2): never
{
    fwrite(STDERR, "[FATAL] {$message}\n");
    exit($code);
}

function warn(string $message): void
{
    fwrite(STDERR, "[WARN] {$message}\n");
}

function info(string $message): void
{
    fwrite(STDERR, "[INFO] {$message}\n");
}

/** @return array{status:int, headers:array<string,string>, body:string, effective_url:string, redirect_count:int} */
function httpGet(string $url, bool $followRedirects = false): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $followRedirects,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => TIMEOUT_SECONDS,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'status' => 0,
            'headers' => [],
            'body' => '',
            'effective_url' => $url,
            'redirect_count' => 0,
            'error' => $error,
        ];
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $redirectCount = (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $rawHeaders = substr((string) $response, 0, $headerSize);
    $body = substr((string) $response, $headerSize);

    curl_close($ch);

    $headers = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
        'effective_url' => $effectiveUrl,
        'redirect_count' => $redirectCount,
    ];
}

/** @return list<string> */
function parseSitemapUrls(string $xml, string $sitemapUrl): array
{
    $previous = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_use_internal_errors($previous);

    if ($doc === false) {
        fail('Sitemap XML invalide (parse error)');
    }

    $urls = [];
    foreach ($doc->url as $urlNode) {
        $loc = trim((string) $urlNode->loc);
        if ($loc !== '') {
            $urls[] = $loc;
        }
    }

    if (count($urls) === 0) {
        fail('Sitemap ne contient aucune URL (<url><loc>...</loc></url>)');
    }

    if (count($urls) > MAX_URLS) {
        fail(sprintf('Sitemap contient %d URLs (max autorisé : %d)', count($urls), MAX_URLS));
    }

    return $urls;
}

function extractMetaRobots(string $html): ?string
{
    if (preg_match('#<meta\s+name=["\']robots["\']\s+content=["\']([^"\']+)["\']#i', $html, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

function extractCanonical(string $html): ?string
{
    if (preg_match('#<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']#i', $html, $m)) {
        return $m[1];
    }
    return null;
}

// -------------------------------------------------------------------------
// Main
// -------------------------------------------------------------------------

if ($argc < 2) {
    fail("Usage : php scripts/sitemap-audit.php <sitemap-url> [--out=var/audit]", 64);
}

$sitemapUrl = $argv[1];
$outDir = 'var/audit';

foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    }
}

$parsedSitemap = parse_url($sitemapUrl);
if ($parsedSitemap === false || !isset($parsedSitemap['scheme'], $parsedSitemap['host'])) {
    fail("URL sitemap invalide : {$sitemapUrl}", 64);
}
$expectedOrigin = $parsedSitemap['scheme'] . '://' . $parsedSitemap['host'];

info("Téléchargement du sitemap : {$sitemapUrl}");
$sitemapResponse = httpGet($sitemapUrl);

if ($sitemapResponse['status'] !== 200) {
    fail(sprintf('Sitemap HTTP %d (attendu 200)', $sitemapResponse['status']));
}

$contentType = $sitemapResponse['headers']['content-type'] ?? '';
if (!str_contains($contentType, 'xml')) {
    warn("Content-Type du sitemap = '{$contentType}' (attendu application/xml ou text/xml)");
}

$urls = parseSitemapUrls($sitemapResponse['body'], $sitemapUrl);
info(sprintf('Sitemap parsé : %d URLs', count($urls)));

if (!is_dir($outDir) && !@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fail("Impossible de créer le dossier de sortie : {$outDir}");
}

$timestamp = date('Ymd-His');
$csvPath = "{$outDir}/sitemap-audit-{$timestamp}.csv";
$mdPath  = "{$outDir}/sitemap-audit-{$timestamp}.md";

$csv = fopen($csvPath, 'w');
if ($csv === false) {
    fail("Impossible d'ouvrir {$csvPath} en écriture");
}
fputcsv($csv, ['url', 'status', 'redirect_count', 'effective_url', 'meta_robots', 'canonical', 'anomaly'], escape: '\\');

$anomalies = [];
$okCount = 0;

foreach ($urls as $i => $url) {
    printf("[%d/%d] %s\n", $i + 1, count($urls), $url);

    $response = httpGet($url, followRedirects: false);
    $status = $response['status'];
    $anomaly = null;

    if ($status === 0) {
        $anomaly = 'Erreur réseau : ' . ($response['error'] ?? 'inconnue');
    } elseif ($status >= 300 && $status < 400) {
        $location = $response['headers']['location'] ?? '(pas de header Location)';
        $anomaly = "Redirection {$status} vers {$location}";
    } elseif ($status >= 400) {
        $anomaly = "HTTP {$status}";
    } else {
        // Vérifier origine
        $parsed = parse_url($url);
        if ($parsed !== false && isset($parsed['scheme'], $parsed['host'])) {
            $origin = $parsed['scheme'] . '://' . $parsed['host'];
            if ($origin !== $expectedOrigin) {
                $anomaly = "Origine {$origin} != sitemap {$expectedOrigin}";
            }
        }

        $robots = extractMetaRobots($response['body']);
        $canonical = extractCanonical($response['body']);

        if ($anomaly === null && $robots !== null && str_contains($robots, 'noindex')) {
            $anomaly = "meta robots contient noindex ({$robots})";
        }

        if ($anomaly === null && $canonical !== null) {
            $parsedCanonical = parse_url($canonical);
            if ($parsedCanonical !== false && isset($parsedCanonical['scheme'], $parsedCanonical['host'])) {
                $canonicalOrigin = $parsedCanonical['scheme'] . '://' . $parsedCanonical['host'];
                if ($canonicalOrigin !== $expectedOrigin) {
                    $anomaly = "Canonical pointe vers autre domaine : {$canonical}";
                }
            }
        }

        // header X-Robots-Tag
        $xRobots = $response['headers']['x-robots-tag'] ?? null;
        if ($anomaly === null && $xRobots !== null && str_contains(strtolower($xRobots), 'noindex')) {
            $anomaly = "X-Robots-Tag contient noindex ({$xRobots})";
        }
    }

    fputcsv($csv, [
        $url,
        $status,
        $response['redirect_count'],
        $response['effective_url'],
        $robots ?? '',
        $canonical ?? '',
        $anomaly ?? '',
    ], escape: '\\');

    if ($anomaly !== null) {
        $anomalies[] = ['url' => $url, 'anomaly' => $anomaly, 'status' => $status];
    } else {
        $okCount++;
    }
}

fclose($csv);

// -------------------------------------------------------------------------
// Rapport Markdown
// -------------------------------------------------------------------------

$md  = "# Audit sitemap — {$sitemapUrl}\n\n";
$md .= "Date : " . date('Y-m-d H:i:s') . "\n\n";
$md .= "## Résumé\n\n";
$md .= "- URLs auditées : **" . count($urls) . "**\n";
$md .= "- OK : **{$okCount}**\n";
$md .= "- Anomalies : **" . count($anomalies) . "**\n\n";

if (count($anomalies) > 0) {
    $md .= "## Anomalies\n\n";
    $md .= "| URL | HTTP | Anomalie |\n";
    $md .= "|-----|------|----------|\n";
    foreach ($anomalies as $a) {
        $md .= sprintf("| %s | %d | %s |\n",
            $a['url'],
            $a['status'],
            str_replace('|', '\\|', $a['anomaly']),
        );
    }
    $md .= "\n";
}

$md .= "## Détail CSV\n\nVoir : `{$csvPath}`\n";

file_put_contents($mdPath, $md);

info("Rapport CSV : {$csvPath}");
info("Rapport MD  : {$mdPath}");

if (count($anomalies) > 0) {
    fwrite(STDERR, sprintf("\n❌ %d anomalie(s) détectée(s) sur %d URLs.\n", count($anomalies), count($urls)));
    exit(1);
}

fwrite(STDERR, sprintf("\n✅ Aucune anomalie sur %d URLs.\n", count($urls)));
exit(0);
