#!/usr/bin/env php
<?php
/**
 * Import the Open Cookie Database CSV into oci_cookies_global.
 *
 * Usage: php bin/import-cookie-database.php [path-to-csv]
 * Default CSV: legacy/open-cookie-database.csv
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$csvFile = $argv[1] ?? __DIR__ . '/../legacy/open-cookie-database.csv';

if (!file_exists($csvFile)) {
    fwrite(STDERR, "CSV file not found: {$csvFile}\n");
    exit(1);
}

// Connect to database
$dsn = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: 'mysql://oci:oci@mariadb:3306/oci?charset=utf8mb4';
$parsed = parse_url($dsn);
parse_str($parsed['query'] ?? '', $query);

$host = $parsed['host'] ?? 'mariadb';
$port = $parsed['port'] ?? 3306;
$dbname = ltrim($parsed['path'] ?? '/oci', '/');
$user = $parsed['user'] ?? 'oci';
$pass = $parsed['pass'] ?? 'oci';
$charset = $query['charset'] ?? 'utf8mb4';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Category name → oci_cookie_categories.id mapping
$categoryMap = [];
$stmt = $pdo->query('SELECT id, slug FROM oci_cookie_categories');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $categoryMap[strtolower($row['slug'])] = (int) $row['id'];
}

// Also map some legacy category names
$categoryAliases = [
    'necessary' => $categoryMap['necessary'] ?? null,
    'functional' => $categoryMap['functional'] ?? null,
    'preferences' => $categoryMap['preferences'] ?? null,
    'analytics' => $categoryMap['analytics'] ?? null,
    'performance' => $categoryMap['performance'] ?? null,
    'marketing' => $categoryMap['marketing'] ?? null,
    'unclassified' => $categoryMap['unclassified'] ?? null,
    'targeting' => $categoryMap['marketing'] ?? null,
    'advertisement' => $categoryMap['marketing'] ?? null,
    'statistics' => $categoryMap['analytics'] ?? null,
    'neccessory' => $categoryMap['necessary'] ?? null, // legacy typo
];

function resolveCategoryId(string $categoryName, array $aliases): ?int
{
    $key = strtolower(trim($categoryName));
    return $aliases[$key] ?? null;
}

// Prepare upsert statement
$insertStmt = $pdo->prepare('
    INSERT INTO oci_cookies_global
        (cookie_id, platform, category_id, cookie_name, domain, description, expiry_duration, data_controller, privacy_url, wildcard_match, created_at, updated_at)
    VALUES
        (:cookie_id, :platform, :category_id, :cookie_name, :domain, :description, :expiry_duration, :data_controller, :privacy_url, :wildcard_match, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        platform = VALUES(platform),
        category_id = VALUES(category_id),
        domain = VALUES(domain),
        description = VALUES(description),
        expiry_duration = VALUES(expiry_duration),
        data_controller = VALUES(data_controller),
        privacy_url = VALUES(privacy_url),
        wildcard_match = VALUES(wildcard_match),
        updated_at = NOW()
');

// Add unique index on cookie_id if not exists (for upsert)
try {
    $pdo->exec('ALTER TABLE oci_cookies_global ADD UNIQUE INDEX idx_cookie_id (cookie_id)');
    echo "Added unique index on cookie_id\n";
} catch (PDOException $e) {
    // Index already exists, ignore
    if (strpos($e->getMessage(), 'Duplicate key name') === false && strpos($e->getMessage(), 'Duplicate entry') === false) {
        // Maybe it's a different error, but for ALTER it's usually "Duplicate key name"
    }
}

// Parse and import CSV
$fp = fopen($csvFile, 'r');
$header = fgetcsv($fp, 4096, ',');
// CSV columns: ID, Platform, Category, Cookie / Data Key name, Domain, Description, Retention period, Data Controller, User Privacy & GDPR Rights Portals, Wildcard match

$imported = 0;
$skipped = 0;

while (($data = fgetcsv($fp, 4096, ',')) !== false) {
    if (empty($data) || count($data) < 4) {
        $skipped++;
        continue;
    }

    $cookieId = trim($data[0] ?? '');
    $platform = trim($data[1] ?? '');
    $category = trim($data[2] ?? '');
    $cookieName = trim($data[3] ?? '');
    $domain = trim($data[4] ?? '');
    $description = trim($data[5] ?? '');
    $expiryDuration = trim($data[6] ?? '');
    $dataController = trim($data[7] ?? '');
    $privacyUrl = trim($data[8] ?? '');
    $wildcardMatch = (int) trim($data[9] ?? '0');

    if ($cookieName === '') {
        $skipped++;
        continue;
    }

    // Clean domain (legacy has "domain (3rd party)" patterns)
    $domain = preg_replace('/\s*\(.*\)\s*/', '', $domain);
    $domain = trim($domain, ' ,');

    $categoryId = resolveCategoryId($category, $categoryAliases);

    $insertStmt->execute([
        ':cookie_id' => $cookieId ?: null,
        ':platform' => $platform ?: null,
        ':category_id' => $categoryId,
        ':cookie_name' => $cookieName,
        ':domain' => $domain ?: null,
        ':description' => $description ?: null,
        ':expiry_duration' => $expiryDuration ?: null,
        ':data_controller' => $dataController ?: null,
        ':privacy_url' => $privacyUrl ?: null,
        ':wildcard_match' => $wildcardMatch,
    ]);

    $imported++;
}

fclose($fp);

// Now add Conzent's own cookies as necessary
$conzentCookies = [
    [
        'cookie_id' => 'conzent-conzentconsent',
        'platform' => 'Conzent',
        'category_id' => $categoryMap['necessary'],
        'cookie_name' => 'conzentConsent',
        'domain' => '',
        'description' => 'Stores whether the user has given consent. Set by the Conzent consent management platform.',
        'expiry_duration' => '365 days',
        'data_controller' => 'Conzent',
        'privacy_url' => 'https://conzent.net/privacy-policy/',
        'wildcard_match' => 0,
    ],
    [
        'cookie_id' => 'conzent-conzentconsentprefs',
        'platform' => 'Conzent',
        'category_id' => $categoryMap['necessary'],
        'cookie_name' => 'conzentConsentPrefs',
        'domain' => '',
        'description' => 'Stores the user\'s consent preferences per cookie category. Set by the Conzent consent management platform.',
        'expiry_duration' => '365 days',
        'data_controller' => 'Conzent',
        'privacy_url' => 'https://conzent.net/privacy-policy/',
        'wildcard_match' => 0,
    ],
    [
        'cookie_id' => 'conzent-conzent_id',
        'platform' => 'Conzent',
        'category_id' => $categoryMap['necessary'],
        'cookie_name' => 'conzent_id',
        'domain' => '',
        'description' => 'Unique session identifier for consent tracking. Used to link consent actions to a specific visitor session.',
        'expiry_duration' => '365 days',
        'data_controller' => 'Conzent',
        'privacy_url' => 'https://conzent.net/privacy-policy/',
        'wildcard_match' => 0,
    ],
    [
        'cookie_id' => 'conzent-euconsent',
        'platform' => 'Conzent',
        'category_id' => $categoryMap['necessary'],
        'cookie_name' => 'euconsent',
        'domain' => '',
        'description' => 'IAB TCF v2.2 consent string. Stores the user\'s vendor and purpose consent choices in IAB Transparency & Consent Framework format.',
        'expiry_duration' => '365 days',
        'data_controller' => 'Conzent',
        'privacy_url' => 'https://conzent.net/privacy-policy/',
        'wildcard_match' => 0,
    ],
    [
        'cookie_id' => 'conzent-lastreneweddate',
        'platform' => 'Conzent',
        'category_id' => $categoryMap['necessary'],
        'cookie_name' => 'lastRenewedDate',
        'domain' => '',
        'description' => 'Timestamp of the last consent renewal. Used to determine when to re-prompt for consent.',
        'expiry_duration' => '365 days',
        'data_controller' => 'Conzent',
        'privacy_url' => 'https://conzent.net/privacy-policy/',
        'wildcard_match' => 0,
    ],
];

foreach ($conzentCookies as $cookie) {
    $insertStmt->execute([
        ':cookie_id' => $cookie['cookie_id'],
        ':platform' => $cookie['platform'],
        ':category_id' => $cookie['category_id'],
        ':cookie_name' => $cookie['cookie_name'],
        ':domain' => $cookie['domain'] ?: null,
        ':description' => $cookie['description'],
        ':expiry_duration' => $cookie['expiry_duration'],
        ':data_controller' => $cookie['data_controller'],
        ':privacy_url' => $cookie['privacy_url'],
        ':wildcard_match' => $cookie['wildcard_match'],
    ]);
    $imported++;
}

echo "Done! Imported: {$imported}, Skipped: {$skipped}\n";

// Verify
$count = $pdo->query('SELECT COUNT(*) FROM oci_cookies_global')->fetchColumn();
echo "Total cookies in oci_cookies_global: {$count}\n";

// Show category breakdown
$catStmt = $pdo->query('
    SELECT cc.slug, COUNT(cg.id) as cnt
    FROM oci_cookies_global cg
    LEFT JOIN oci_cookie_categories cc ON cc.id = cg.category_id
    GROUP BY cg.category_id
    ORDER BY cnt DESC
');
echo "\nBy category:\n";
while ($row = $catStmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  " . ($row['slug'] ?? 'NULL') . ": " . $row['cnt'] . "\n";
}
