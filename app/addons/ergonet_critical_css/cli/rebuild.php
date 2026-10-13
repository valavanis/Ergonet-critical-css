<?php
declare(strict_types=1);

use Tygh\Registry;

/**
 * Ξαναχτίζει το critical CSS ΜΙΑΣ βιτρίνας όταν αλλάξει το CSS του θέματός της.
 *
 * Μεταφορά του /usr/local/sbin/qt-critical-css του qualitytoners.gr μέσα στο addon, ώστε
 * να τρέχει από τον χρονοπρογραμματιστή του ergonet_core ανά βιτρίνα (cli/job_rebuild.php).
 * Η λογική είναι η ίδια:
 *
 *  - ΣΚΑΝΔΑΛΗ ΤΟ ΠΕΡΙΕΧΟΜΕΝΟ, ΟΧΙ ΤΟ ΟΝΟΜΑ. Το standalone.<hash><timestamp>.css αλλάζει
 *    όνομα σε κάθε καθάρισμα cache· συγκρίνεται το md5 του ΠΕΡΙΕΧΟΜΕΝΟΥ του bundle, χωρίς
 *    τα ?timestamp των url(). Στο qualitytoners η σύγκριση ονόματος άφησε δύο αλλαγές
 *    στυλ απαρατήρητες (02/09/2026).
 *  - ΧΤΙΣΙΜΟ ΣΕ ΠΡΟΣΩΡΙΝΟ, ποτέ πάνω από το ζωντανό· αποτυχία = το παλιό μένει.
 *  - Μετά την εγκατάσταση σβήνεται το FPC: οι cached σελίδες κουβαλούν το ΠΑΛΙΟ inline CSS.
 *
 * ΕΝΕΡΓΟΠΟΙΗΣΗ ΑΝΑ ΘΕΜΑ (--enable): μια βιτρίνα χωρίς φάκελο στο var/ergonet_critical_css/
 * παραλείπεται αθόρυβα. Έτσι η βιτρίνα THI (dev.thi.gr, δεν λύνεται στο DNS σήμερα) δεν
 * γράφει αποτυχία κάθε ώρα, και κανένα θέμα δεν αποκτά inline CSS χωρίς να το αποφασίσει κανείς.
 *
 * Κόστος: ένα αίτημα σελίδας + ένα κατέβασμα bundle την ώρα (~700 KB για το ergonet_theme)·
 * το χτίσιμο (~50 s, 7 τύποι σελίδας × 2 αναλύσεις) μόνο όταν αλλάξει το CSS.
 *
 * Χρήση: php rebuild.php --docroot=… --storefront=N [--enable] [--force] [--check] [--dry-run]
 * Έξοδος: 0 εντάξει/τίποτα να γίνει · 1 απέτυχε (το ζωντανό δεν πειράχτηκε) · 2 λάθος κλήση
 */

const ERG_CRIT_NODE        = '/usr/local/bin/node';
const ERG_CRIT_PLAYWRIGHT  = '/opt/ergonet-cookie-scanner/node_modules/playwright';
const ERG_CRIT_BROWSERS    = '/opt/ergonet-cookie-scanner/browsers';
// Το χτίσιμο μετρήθηκε 52 s στο ergonet.store· δεκαπλάσιο περιθώριο πριν θεωρηθεί κολλημένο,
// και κάτω από το timeout 900 s της εργασίας ώστε να καθαρίσουμε εμείς, όχι ο scheduler.
const ERG_CRIT_GEN_TIMEOUT = 600;
// Μια νέα ένωση κάτω από το 70% της ζωντανής σημαίνει σχεδόν πάντα ότι μια σελίδα φόρτωσε
// μισή (στο qualitytoners έλειψαν έτσι 187 κανόνες ταμείου). Το --force το παρακάμπτει.
const ERG_CRIT_MIN_RATIO   = 0.7;
const ERG_CRIT_KEEP_BACKUPS = 5;
const ERG_CRIT_UA = 'Mozilla/5.0 (Linux; Android 12; Pixel 5) AppleWebKit/537.36 Chrome/141 Mobile Safari/537.36';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
// Ως root τα αρχεία θα έβγαιναν αδιάβαστα από το php-fpm του slot.
if (function_exists('posix_getuid') && posix_getuid() === 0) {
    fwrite(STDERR, "critical-css: αρνούμαι να τρέξω ως root\n");
    exit(2);
}

/** @var array<string,string|bool> $erg_args */
$erg_args = [];
foreach (array_slice((array) ($_SERVER['argv'] ?? []), 1) as $erg_arg) {
    $erg_arg = (string) $erg_arg;
    if (preg_match('~^--(docroot|storefront)=(.*)$~', $erg_arg, $erg_m) === 1) {
        $erg_args[$erg_m[1]] = $erg_m[2];
    } elseif (in_array($erg_arg, ['--enable', '--force', '--check', '--dry-run'], true)) {
        $erg_args[substr($erg_arg, 2)] = true;
    } else {
        // Άγνωστη σημαία απορρίπτεται: ένα τυπογραφικό σε ορισμό εργασίας αλλιώς θα
        // έκανε σιωπηλά την προεπιλογή για πάντα.
        fwrite(STDERR, "critical-css: άγνωστη παράμετρος {$erg_arg}\n");
        exit(2);
    }
}

$erg_docroot = rtrim((string) ($erg_args['docroot'] ?? dirname(__DIR__, 4)), '/');
$erg_sf = (int) ($erg_args['storefront'] ?? 0);
if (!is_file($erg_docroot . '/init.php')) {
    fwrite(STDERR, "critical-css: δεν βρέθηκε init.php στο {$erg_docroot}\n");
    exit(2);
}
if ($erg_sf <= 0) {
    // Χωρίς ergonet_core (qualitytoners) την ίδια δουλειά κάνει το /usr/local/sbin/qt-critical-css.
    fwrite(STDERR, "critical-css: χρειάζεται --storefront=N (εγκαταστάσεις με ergonet_core)\n");
    exit(2);
}

chdir($erg_docroot);
define('AREA', 'A');
define('ACCOUNT_TYPE', 'admin');
require $erg_docroot . '/init.php';

if (!function_exists('fn_ergonet_core_use_storefront')) {
    fwrite(STDERR, "critical-css: το --storefront θέλει εγκατεστημένο το ergonet_core\n");
    exit(2);
}
fn_ergonet_core_use_storefront($erg_sf, ['ergonet_critical_css', 'ergonet_rocket_cache']);
// Ανενεργό addon: έξοδος 0. Δεν είναι βλάβη, είναι ρύθμιση.
if (Registry::get('addons.ergonet_critical_css.status') !== 'A') {
    exit(0);
}

/** Μία γραμμή στο stdout και στο ημερολόγιο του core. @param array<string,mixed> $data */
function erg_crit_log(string $level, string $message, array $data = []): void
{
    echo '[' . $level . '] ' . $message . ($data !== [] ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '') . "\n";
    if (function_exists('fn_ergonet_core_log')) {
        fn_ergonet_core_log($level, 'critical_css', $message, $data, 'ergonet_critical_css');
    }
}

/** @return array{code:int, body:string, url:string} */
function erg_crit_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => ERG_CRIT_UA,
    ]);
    $body = curl_exec($ch);
    $out = [
        'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => is_string($body) ? $body : '',
        'url'  => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
    ];
    curl_close($ch);
    return $out;
}

/** Αναδρομικό σβήσιμο ΜΟΝΟ κάτω από τον δικό μας φάκελο. */
function erg_crit_rmdir(string $dir, string $root): void
{
    $real = realpath($dir);
    $real_root = realpath($root);
    if ($real === false || $real_root === false || strpos($real, $real_root . '/') !== 0) {
        return;
    }
    foreach (array_diff((array) scandir($real), ['.', '..']) as $name) {
        $f = $real . '/' . (string) $name;
        is_dir($f) && !is_link($f) ? erg_crit_rmdir($f, $root) : @unlink($f);
    }
    @rmdir($real);
}

$erg_theme = (string) db_get_field('SELECT theme_name FROM ?:storefronts WHERE storefront_id = ?i', $erg_sf);
if (preg_match('~^[a-z0-9_-]{1,64}$~', $erg_theme) !== 1) {
    erg_crit_log('error', 'άκυρο ή κενό θέμα βιτρίνας', ['storefront_id' => $erg_sf, 'theme' => $erg_theme]);
    exit(1);
}
$erg_origin = rtrim(fn_ergonet_core_storefront_origin($erg_sf), '/');
if ($erg_origin === '') {
    erg_crit_log('error', 'δεν προσδιορίζεται το origin της βιτρίνας', ['storefront_id' => $erg_sf]);
    exit(1);
}

$erg_root = Registry::get('config.dir.var') . 'ergonet_critical_css';
$erg_dir  = $erg_root . '/' . $erg_theme;
if (!is_dir($erg_dir)) {
    if (empty($erg_args['enable'])) {
        echo "θέμα {$erg_theme} (βιτρίνα {$erg_sf}): δεν έχει ενεργοποιηθεί critical CSS — παραλείπεται\n";
        exit(0);
    }
    if (!empty($erg_args['dry-run'])) {
        echo "dry-run: θα δημιουργούνταν ο {$erg_dir}\n";
    } elseif (!mkdir($erg_dir, 0755, true) && !is_dir($erg_dir)) {
        erg_crit_log('error', 'δεν δημιουργήθηκε ο φάκελος του θέματος', ['dir' => $erg_dir]);
        exit(1);
    }
    $erg_args['force'] = true;
}

// Κλείδωμα ανά θέμα: δύο βιτρίνες με το ίδιο θέμα, ή η ωριαία εργασία πάνω σε χειροκίνητο
// τρέξιμο, θα έγραφαν ταυτόχρονα στον ίδιο φάκελο.
$erg_lock = is_dir($erg_dir) ? fopen($erg_dir . '/.lock', 'c') : false;
if ($erg_lock !== false && !flock($erg_lock, LOCK_EX | LOCK_NB)) {
    echo "θέμα {$erg_theme}: τρέχει ήδη άλλο χτίσιμο\n";
    exit(0);
}

// ── Ποιο bundle σερβίρεται τώρα ──────────────────────────────────────────────
$erg_home = erg_crit_get($erg_origin . '/');
if ($erg_home['code'] !== 200
    || preg_match('~href="([^"]*/standalone\.[^"]+\.css[^"]*)"~i', $erg_home['body'], $erg_m) !== 1) {
    erg_crit_log('error', 'δεν βρέθηκε το bundle του θέματος στην αρχική', ['origin' => $erg_origin, 'http' => $erg_home['code']]);
    exit(1);
}
$erg_bundle_url = html_entity_decode($erg_m[1], ENT_QUOTES, 'UTF-8');
$erg_bundle = erg_crit_get($erg_bundle_url);
if ($erg_bundle['code'] !== 200 || $erg_bundle['body'] === '') {
    erg_crit_log('error', 'το bundle δεν κατέβηκε', ['url' => $erg_bundle_url, 'http' => $erg_bundle['code']]);
    exit(1);
}
// ΧΩΡΙΣ ΤΑ ?timestamp: η CS-Cart ξαναπαράγει το bundle σε κάθε καθάρισμα cache και αλλάζει
// μόνο το `?<10 ψηφία>` στα url() των γραμματοσειρών/εικόνων. Μετρημένο 13/10/2026 στο
// ergonet.store: δύο αναπαραγωγές σε 20 λεπτά. Με ωμό md5 κάθε τέτοια θα έφερνε χτίσιμο
// 50 s ΚΑΙ ολικό σβήσιμο του FPC — ενώ οι κανόνες ήταν ίδιοι.
$erg_hash = md5((string) preg_replace('~\?\d{10}\b~', '', $erg_bundle['body']));
$erg_hash_file = $erg_dir . '/critical.hash';
$erg_old_hash = is_readable($erg_hash_file) ? trim((string) file_get_contents($erg_hash_file)) : '';

if (!empty($erg_args['check'])) {
    echo "θέμα {$erg_theme}: bundle {$erg_hash} · critical " . ($erg_old_hash !== '' ? $erg_old_hash : '(κανένα)')
        . ' · ' . ($erg_hash === $erg_old_hash ? 'ΣΥΓΧΡΟΝΙΣΜΕΝΟ' : 'ΠΑΛΙΩΜΕΝΟ') . "\n";
    exit(0);
}
if ($erg_hash === $erg_old_hash && empty($erg_args['force'])) {
    // Ίδιοι κανόνες, πιθανώς άλλα ?timestamp. Το critical CSS κρατά τα url() του bundle από
    // το οποίο χτίστηκε, και από αυτά βγαίνουν και τα <link rel=preload>. Αν μείνουν παλιά,
    // ο browser κατεβάζει κάθε γραμματοσειρά ΔΥΟ φορές (μία από το inline/preload, μία από
    // το bundle) — ακριβώς ό,τι το preload υπάρχει για να αποφύγει. Τα συγχρονίζουμε
    // χωρίς χτίσιμο: αντικατάσταση διαδρομή-προς-διαδρομή, O(μέγεθος αρχείων).
    $erg_ts = [];
    if (preg_match_all('~url\(\s*["\']?([^"\')?\s]+)\?(\d{10})~', $erg_bundle['body'], $erg_mm, PREG_SET_ORDER) > 0) {
        foreach ($erg_mm as $erg_x) {
            $erg_ts[$erg_x[1]] = $erg_x[2];
        }
    }
    $erg_changed = 0;
    foreach (array_map('strval', (array) glob($erg_dir . '/critical*.css')) as $f) {
        $css = (string) file_get_contents($f);
        $new = (string) preg_replace_callback(
            '~(url\(\s*["\']?)([^"\')?\s]+)\?(\d{10})~',
            static function (array $m) use ($erg_ts): string {
                return $m[1] . $m[2] . '?' . ($erg_ts[$m[2]] ?? $m[3]);
            },
            $css
        );
        if ($new !== $css) {
            if (!empty($erg_args['dry-run'])) {
                echo 'dry-run: θα ενημερώνονταν τα ?timestamp στο ' . basename($f) . "\n";
            } else {
                file_put_contents($f . '.tmp', $new);
                rename($f . '.tmp', $f);
            }
            $erg_changed++;
        }
    }
    if ($erg_changed > 0 && empty($erg_args['dry-run'])) {
        // Η CS-Cart έχει ήδη σβήσει το FPC όταν ξαναπαρήγαγε το bundle· όσες σελίδες
        // ξαναγέμισαν στο μεσοδιάστημα κουβαλούν τα παλιά url(), οπότε σβήνονται ξανά.
        if (Registry::get('addons.ergonet_rocket_cache.status') === 'A') {
            require_once Registry::get('config.dir.addons') . 'ergonet_rocket_cache/func.php';
            if (function_exists('fn_ergonet_rocket_cache_clear_storage')) {
                fn_ergonet_rocket_cache_clear_storage('all');
            }
        }
        erg_crit_log('info', 'ενημερώθηκαν τα ?timestamp του critical CSS (ίδιοι κανόνες)', [
            'theme' => $erg_theme, 'files' => $erg_changed,
        ]);
    }
    exit(0);
}

// ── Δείγματα σελίδων από τη βάση της βιτρίνας ────────────────────────────────
// Το πιο δημοφιλές διαθέσιμο προϊόν και η κύρια κατηγορία του: σελίδες που υπάρχουν
// σίγουρα και μοιάζουν με αυτές που βλέπουν οι επισκέπτες. Το fn_url() σε CLI δίνει
// πάντα τον host της προεπιλεγμένης βιτρίνας, οπότε κρατάμε μόνο τη διαδρομή του.
$erg_rel = static function (string $dispatch) use ($erg_origin): string {
    $u = (string) fn_url($dispatch, 'C', 'https');
    $path = (string) parse_url($u, PHP_URL_PATH);
    $query = (string) parse_url($u, PHP_URL_QUERY);
    return $erg_origin . ($path !== '' ? $path : '/') . ($query !== '' ? '?' . $query : '');
};
$erg_candidates = db_get_array(
    'SELECT p.product_id, pc.category_id FROM ?:products p'
    . ' INNER JOIN ?:product_popularity pp ON pp.product_id = p.product_id'
    . ' INNER JOIN ?:products_categories pc ON pc.product_id = p.product_id AND pc.link_type = ?s'
    . ' INNER JOIN ?:categories c ON c.category_id = pc.category_id AND c.status = ?s'
    . ' WHERE p.status = ?s AND p.amount > 0'
    . ' ORDER BY pp.total DESC LIMIT 10',
    'M', 'A', 'A'
);
$erg_product = '';
$erg_category = '';
foreach ($erg_candidates as $erg_c) {
    $p = $erg_rel('products.view?product_id=' . (int) $erg_c['product_id']);
    $c = $erg_rel('categories.view?category_id=' . (int) $erg_c['category_id']);
    // Μόνο σελίδες που απαντούν 200 ΣΤΟΝ ΙΔΙΟ host: ένα προϊόν άλλης εταιρείας θα
    // ανακατεύθυνε, και θα μετρούσαμε τη σελίδα λάθους.
    $rp = erg_crit_get($p);
    $rc = erg_crit_get($c);
    if ($rp['code'] === 200 && $rc['code'] === 200
        && parse_url($rp['url'], PHP_URL_HOST) === parse_url($erg_origin, PHP_URL_HOST)) {
        $erg_product = $rp['url'];
        $erg_category = $rc['url'];
        break;
    }
}
if ($erg_product === '') {
    erg_crit_log('error', 'κανένα δείγμα προϊόντος δεν απάντησε 200', ['origin' => $erg_origin]);
    exit(1);
}

// ── Χτίσιμο σε προσωρινό ─────────────────────────────────────────────────────
$erg_tmp = $erg_root . '/.tmp-' . $erg_theme . '-' . getmypid();
erg_crit_rmdir($erg_tmp, $erg_root);
if (!mkdir($erg_tmp, 0755, true) && !is_dir($erg_tmp)) {
    erg_crit_log('error', 'δεν δημιουργήθηκε προσωρινός φάκελος', ['dir' => $erg_tmp]);
    exit(1);
}
$erg_cmd = [ERG_CRIT_NODE, dirname(__DIR__) . '/tools/generate_critical.js', $erg_tmp, $erg_origin];
$erg_env = [
    'PATH'                     => '/usr/local/bin:/usr/bin:/bin',
    // Ο χρήστης του slot έχει HOME=/nonexistent· ο Chromium θέλει γράψιμο για το προφίλ του.
    'HOME'                     => $erg_tmp,
    'PLAYWRIGHT_BROWSERS_PATH' => ERG_CRIT_BROWSERS,
    'QT_PLAYWRIGHT_PATH'       => ERG_CRIT_PLAYWRIGHT,
    'QT_CRIT_PRODUCT'          => $erg_product,
    'QT_CRIT_CATEGORY'         => $erg_category,
];
$erg_started = microtime(true);
$erg_proc = proc_open($erg_cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $erg_pipes, $erg_tmp, $erg_env);
if (!is_resource($erg_proc)) {
    erg_crit_log('error', 'ο generator δεν ξεκίνησε', ['cmd' => $erg_cmd[0]]);
    erg_crit_rmdir($erg_tmp, $erg_root);
    exit(1);
}
stream_set_blocking($erg_pipes[1], false);
stream_set_blocking($erg_pipes[2], false);
$erg_out = '';
$erg_code = -1;
while (true) {
    $erg_out .= (string) stream_get_contents($erg_pipes[1]) . (string) stream_get_contents($erg_pipes[2]);
    $st = proc_get_status($erg_proc);
    if (!$st['running']) {
        $erg_code = (int) $st['exitcode'];
        break;
    }
    if (microtime(true) - $erg_started > ERG_CRIT_GEN_TIMEOUT) {
        proc_terminate($erg_proc, 9);
        $erg_out .= "\nΔΙΑΚΟΠΗ: πάνω από " . ERG_CRIT_GEN_TIMEOUT . ' s';
        break;
    }
    usleep(200000);
}
$erg_out .= (string) stream_get_contents($erg_pipes[1]) . (string) stream_get_contents($erg_pipes[2]);
fclose($erg_pipes[1]);
fclose($erg_pipes[2]);
proc_close($erg_proc);

$erg_new = $erg_tmp . '/critical.css';
$erg_new_size = is_file($erg_new) ? (int) filesize($erg_new) : 0;
$erg_old_size = is_file($erg_dir . '/critical.css') ? (int) filesize($erg_dir . '/critical.css') : 0;
$erg_tail = implode("\n", array_slice(explode("\n", trim($erg_out)), -12));
if ($erg_code !== 0 || $erg_new_size === 0) {
    erg_crit_log('error', 'το χτίσιμο απέτυχε — το ζωντανό critical CSS δεν πειράχτηκε', [
        'theme' => $erg_theme, 'exit' => $erg_code, 'output' => $erg_tail,
    ]);
    erg_crit_rmdir($erg_tmp, $erg_root);
    exit(1);
}
if ($erg_old_size > 0 && $erg_new_size < $erg_old_size * ERG_CRIT_MIN_RATIO && empty($erg_args['force'])) {
    erg_crit_log('error', 'η νέα ένωση είναι ύποπτα μικρή — δεν εγκαθίσταται (--force για παράκαμψη)', [
        'theme' => $erg_theme, 'new' => $erg_new_size, 'old' => $erg_old_size,
    ]);
    erg_crit_rmdir($erg_tmp, $erg_root);
    exit(1);
}

$erg_files = array_map('strval', (array) glob($erg_tmp . '/critical*.css'));
$erg_sizes = [];
foreach ($erg_files as $f) {
    $erg_sizes[basename($f)] = (int) filesize($f);
}
if (!empty($erg_args['dry-run'])) {
    echo $erg_tail . "\n";
    echo "dry-run: θέμα {$erg_theme}, προϊόν {$erg_product}, κατηγορία {$erg_category}\n";
    echo 'dry-run: θα εγκαθίσταντο ' . json_encode($erg_sizes, JSON_UNESCAPED_SLASHES) . " (ζωντανό: {$erg_old_size} B)\n";
    erg_crit_rmdir($erg_tmp, $erg_root);
    exit(0);
}

// ── Εγκατάσταση με εφεδρικό ──────────────────────────────────────────────────
$erg_bak = '';
$erg_live = array_map('strval', (array) glob($erg_dir . '/critical*.css'));
if ($erg_live !== []) {
    $erg_bak = $erg_root . '/.backup/' . $erg_theme . '-' . gmdate('Ymd\THis\Z');
    if (!is_dir($erg_bak)) {
        mkdir($erg_bak, 0755, true);
    }
    foreach (array_merge($erg_live, [$erg_hash_file]) as $f) {
        if (is_file($f)) {
            copy($f, $erg_bak . '/' . basename($f));
        }
    }
    // Κρατάμε τα τελευταία N εφεδρικά ανά θέμα· τα παλαιότερα δεν επαναφέρει κανείς.
    $erg_all_bak = array_map('strval', (array) glob($erg_root . '/.backup/' . $erg_theme . '-*', GLOB_ONLYDIR));
    sort($erg_all_bak);
    foreach (array_slice($erg_all_bak, 0, max(0, count($erg_all_bak) - ERG_CRIT_KEEP_BACKUPS)) as $old) {
        erg_crit_rmdir($old, $erg_root);
    }
}
// Πρώτα σβήνονται τα ΠΑΛΙΑ ανά-τύπο: ένας τύπος που δεν γράφτηκε τώρα δεν πρέπει να μείνει
// με ξένο hash — το func.php θα πέσει σωστά στην ένωση.
foreach ($erg_live as $f) {
    @unlink($f);
}
foreach ($erg_files as $f) {
    // rename μέσα στο ίδιο filesystem: κάθε αρχείο εμφανίζεται ολόκληρο ή καθόλου.
    rename($f, $erg_dir . '/' . basename($f));
}
file_put_contents($erg_hash_file, $erg_hash);
erg_crit_rmdir($erg_tmp, $erg_root);

// ── Οι cached σελίδες κουβαλούν το ΠΑΛΙΟ inline CSS ──────────────────────────
// Ολικό σβήσιμο: ο φάκελος του FPC είναι κοινός για όλους τους hosts, και η αλλαγή αφορά
// κάθε σελίδα του θέματος. Συμβαίνει μόνο όταν αλλάξει το CSS, όχι κάθε ώρα. Η
// fn_ergonet_rocket_cache_clear_storage() γράφει μόνη της το audit και καθαρίζει τον
// καθρέφτη του nginx· ο warmer τις ξαναγεμίζει στον επόμενο γύρο του.
$erg_purged = -1;
if (Registry::get('addons.ergonet_rocket_cache.status') === 'A') {
    require_once Registry::get('config.dir.addons') . 'ergonet_rocket_cache/func.php';
    if (function_exists('fn_ergonet_rocket_cache_clear_storage')) {
        $erg_purged = fn_ergonet_rocket_cache_clear_storage('all');
    }
}

erg_crit_log('info', 'το critical CSS ξαναχτίστηκε', [
    'theme'   => $erg_theme,
    'bundle'  => ($erg_old_hash !== '' ? $erg_old_hash : 'κανένα') . ' → ' . $erg_hash,
    'files'   => $erg_sizes,
    'seconds' => round(microtime(true) - $erg_started, 1),
    'fpc_purged' => $erg_purged,
    'backup'  => $erg_bak !== '' ? basename($erg_bak) : null,
    'product' => $erg_product,
]);
exit(0);
