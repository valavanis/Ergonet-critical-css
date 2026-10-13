<?php
declare(strict_types=1);

use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');

/**
 * Critical CSS: το πάνω μέρος inline, το υπόλοιπο bundle χωρίς φραγή απόδοσης.
 *
 * ΤΟ ΜΕΓΕΘΟΣ ΤΟΥ ΠΡΟΒΛΗΜΑΤΟΣ (μετρημένο 02/09/2026, σελίδα προϊόντος, CPU 4× +
 * Slow 4G, διάμεσος από 4 τρεξίματα ανά σκέλος):
 *
 *   CSS render-blocking   FCP 1.292 ms
 *   CSS μη-blocking       FCP   204 ms
 *   ΧΩΡΙΣ καθόλου CSS     FCP   208 ms
 *
 * Η ταύτιση των δύο τελευταίων είναι η απόδειξη ότι το stylesheet είναι ο ΜΟΝΑΔΙΚΟΣ
 * φραγμός: 528 KB ασυμπίεστο (84 KB brotli — η συμπίεση είναι ήδη βέλτιστη, το κόστος
 * είναι το parse), από τα οποία το 87% δεν χρησιμοποιείται στη σελίδα που το κατεβάζει.
 *
 * ΓΙΑΤΙ OUTPUT FILTER ΚΑΙ ΟΧΙ TEMPLATE OVERRIDE: το `<link>` το παράγει το Smarty block
 * `{styles}` μέσα στο common/styles.tpl. Override στο παιδικό θέμα θα σήμαινε αντιγραφή
 * ΟΛΟΥ του περιεχομένου του γονέα — και κάθε stylesheet που προσθέτει μελλοντική
 * αναβάθμιση του CS-Cart θα έλειπε σιωπηλά από το αντίγραφο.
 *
 * ΓΙΑΤΙ ΟΧΙ INLINE SCRIPT: ένα `<script>` μετά από `<link rel=stylesheet>` ΔΕΝ εκτελείται
 * μέχρι να φορτώσει το stylesheet — ο browser το κρατά, γιατί το script μπορεί να ρωτήσει
 * υπολογισμένα στυλ. Δηλαδή αδιέξοδο: ο κώδικας που θα ξεμπλόκαρε το CSS περιμένει το CSS.
 * Η αλλαγή ΠΡΕΠΕΙ να γίνει στο HTML πριν φύγει.
 *
 * ΓΙΑΤΙ ΟΧΙ ob_start(): το ergonet_rocket_cache κρατά ήδη output buffer από το
 * before_dispatch. Δεύτερο buffer πάνω του είναι εύθραυστο και εξαρτάται από σειρά
 * φόρτωσης addon. Το output filter του Smarty τρέχει μέσα στο render, πριν από κάθε
 * buffer, οπότε το αποτέλεσμα μπαίνει σωστά και στο FPC.
 */

/**
 * Hook: dispatch_before_display
 *
 * Καταχωρεί output filter. Δεν αγγίζει HTML εδώ — σε αυτό το σημείο δεν υπάρχει ακόμη.
 */
function fn_ergonet_critical_css_dispatch_before_display(): void
{
    if (AREA !== 'C') {
        return;
    }
    if (Registry::get('addons.ergonet_critical_css.enabled') !== 'Y') {
        return;
    }
    // Οι AJAX απαντήσεις δεν έχουν <head>· ένα φίλτρο εκεί είναι καθαρή σπατάλη.
    if (defined('AJAX_REQUEST')) {
        return;
    }

    $view = Tygh::$app['view'];
    if (!is_object($view) || !method_exists($view, 'registerFilter')) {
        return;
    }
    // Ονοματισμένο, ώστε διπλή καταχώρηση σε πολλαπλά display() να μη διπλασιάσει το CSS.
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    fn_ergonet_critical_css_add_output_filter($view, 'fn_ergonet_critical_css_rewrite');
}

/**
 * Καταχωρεί output filter και στη Smarty 4 (CS-Cart 4.18) και στη Smarty 5 (CS-Cart 4.21).
 *
 * ΓΙΑΤΙ ΟΧΙ ΣΚΕΤΟ registerFilter(): στην 4.21 το Tygh\SmartyEngine\Core::registerFilter() είναι
 * ΚΕΝΟ — βγάζει μόνο E_USER_DEPRECATED και επιστρέφει. Κανένα σφάλμα, κανένα αποτέλεσμα:
 * μετρήθηκε 13/10/2026 στο ergonet.store, το hook έτρεχε, το φίλτρο «καταχωρούνταν» και η
 * σελίδα έβγαινε ανέγγιχτη. Η Smarty 5 εκτελεί output filters μόνο από extensions
 * (Smarty::runOutputFilters → getExtensions()), οπότε εκεί προσθέτουμε δικό μας extension.
 * Το dispatch_before_display τρέχει ΜΕΤΑ το setExtensions() του fn_init_templater, άρα το
 * addExtension δεν σβήνεται.
 *
 * @param object   $view Tygh::$app['view']
 * @param callable $cb   δέχεται το HTML (η Smarty 5 περνά και το Template ως 2ο όρισμα)
 */
function fn_ergonet_critical_css_add_output_filter($view, callable $cb): void
{
    if (class_exists('\\Smarty\\Extension\\Base') && class_exists('\\Smarty\\Filter\\FilterPluginWrapper')
        && method_exists($view, 'addExtension')
    ) {
        $view->addExtension(new class ($cb) extends \Smarty\Extension\Base {
            /** @var callable */
            private $cb;

            public function __construct(callable $cb)
            {
                $this->cb = $cb;
            }

            /** @return list<\Smarty\Filter\FilterInterface> */
            public function getOutputFilters(): array
            {
                return [new \Smarty\Filter\FilterPluginWrapper($this->cb)];
            }
        });
        return;
    }
    $view->registerFilter('output', $cb);
}

/**
 * Ο φάκελος από τον οποίο διαβάζεται το critical CSS αυτής της σελίδας.
 *
 * ΑΝΑ ΘΕΜΑ (v1.3.0, 13/10/2026): το critical CSS είναι στιγμιότυπο ΕΝΟΣ θέματος. Στο
 * ergonet.store η ίδια εγκατάσταση σερβίρει δύο βιτρίνες με διαφορετικό θέμα
 * (ergonet_theme, thi_theme)· ένα κοινό αρχείο θα έδινε στη μία βιτρίνα την πρώτη
 * απόδοση της άλλης. Γι' αυτό τα παραγόμενα αρχεία ζουν στο
 * var/ergonet_critical_css/<θέμα>/ — έξω από τον φάκελο του addon, ώστε ένα deploy να
 * μην τα σβήνει ούτε να τα αντικαθιστά με τα αρχεία άλλου καταστήματος.
 *
 * Όταν ο var/ergonet_critical_css/ ΥΠΑΡΧΕΙ, το παλιό assets/ ΔΕΝ διαβάζεται ποτέ: ένα
 * θέμα χωρίς δικό του φάκελο παίρνει κενό, δηλαδή καμία αλλαγή στη σελίδα. Αλλιώς η
 * βιτρίνα THI θα έπεφτε στο critical.css του qualitytoners που φέρνει το repo.
 * Όταν ΔΕΝ υπάρχει (qualitytoners σήμερα), όλα μένουν όπως πριν, στο assets/.
 *
 * @return string διαδρομή με τελικό '/', ή κενό αν αυτό το θέμα δεν έχει critical CSS
 */
function fn_ergonet_critical_css_dir(): string
{
    $base = Registry::get('config.dir.var') . 'ergonet_critical_css/';
    if (!is_dir($base)) {
        return Registry::get('config.dir.addons') . 'ergonet_critical_css/assets/';
    }
    $theme = (string) Registry::get('runtime.layout.theme_name');
    // Το όνομα γίνεται κομμάτι διαδρομής· οτιδήποτε έξω από το αλφάβητο των θεμάτων
    // του CS-Cart απορρίπτεται αντί να «καθαριστεί».
    if (preg_match('~^[a-z0-9_-]{1,64}$~', $theme) !== 1) {
        return '';
    }
    return $base . $theme . '/';
}

/**
 * Διαβάζει το critical CSS από τον δίσκο, μία φορά ανά αίτημα.
 *
 * ΑΠΟΤΥΓΧΑΝΕΙ ΑΘΟΡΥΒΑ ΚΑΙ ΑΣΦΑΛΩΣ: αν το αρχείο λείπει ή είναι άδειο, επιστρέφει κενό
 * και ο rewriter δεν κάνει τίποτα — η σελίδα μένει ακριβώς όπως ήταν, με φραγή απόδοσης
 * αλλά σωστή. Ποτέ μισο-εφαρμοσμένο: inline χωρίς rewrite, ή rewrite χωρίς inline, θα
 * έδινε σελίδα χωρίς στυλ.
 */
function fn_ergonet_critical_css_get(): string
{
    static $css = null;
    if ($css !== null) {
        return $css;
    }
    $dir = fn_ergonet_critical_css_dir();
    if ($dir === '') {
        return $css = '';
    }
    // Πρώτα το αρχείο του τύπου σελίδας, μετά η ένωση. Η ένωση υπάρχει ΠΑΝΤΑ και
    // είναι πλήρης — είναι το ίδιο αρχείο που σερβιριζόταν παντού πριν τις 03/09/2026.
    $candidates = [];
    $type = fn_ergonet_critical_css_page_type();
    if ($type !== '') {
        $candidates[] = $dir . 'critical.' . $type . '.css';
    }
    $candidates[] = $dir . 'critical.css';
    $css = '';
    foreach ($candidates as $path) {
        $c = (is_readable($path) ? (string) file_get_contents($path) : '');
        // Κάτω από 1 KB σημαίνει σχεδόν σίγουρα κατεστραμμένο ή μισογραμμένο αρχείο.
        if (strlen($c) >= 1024) {
            $css = $c;
            break;
        }
    }
    return $css;
}

/**
 * Ο τύπος σελίδας για την επιλογή critical CSS.
 *
 * ΓΙΑΤΙ ΑΝΑ ΤΥΠΟ: η ένωση όλων των τύπων ήταν 110 KB inline σε ΚΑΘΕ σελίδα —
 * η σελίδα προϊόντος στο κινητό κουβαλούσε 72 κανόνες ταμείου και 22 @keyframes.
 * Το Lighthouse χρέωνε 2.429 ms CPU στο έγγραφο (μόνο 136 ms ήταν JS).
 *
 * Τα κλειδιά ΠΡΕΠΕΙ να ταιριάζουν με όσα γράφει το tools/generate_critical.js.
 * Ό,τι δεν αντιστοιχίζεται (pages, companies, …) παίρνει κενό → ένωση. Ασφαλές
 * εξ ορισμού: ο άγνωστος τύπος παίρνει το πλήρες σύνολο, ποτέ ένα ξένο υποσύνολο.
 *
 * @return string 'index' | 'categories' | 'products' | 'search' | 'auth' | 'cart' | 'checkout' | ''
 */
function fn_ergonet_critical_css_page_type(): string
{
    $controller = (string) Registry::get('runtime.controller');
    $mode       = (string) Registry::get('runtime.mode');
    switch ($controller) {
        case 'index':
            return 'index';
        case 'categories':
            return 'categories';
        case 'products':
            // Η αναζήτηση έχει άλλη διάταξη (φίλτρα, λίστα) από τη σελίδα προϊόντος.
            return $mode === 'search' ? 'search' : 'products';
        case 'auth':
            return 'auth';
        case 'checkout':
            return $mode === 'cart' ? 'cart' : 'checkout';
        default:
            return '';
    }
}

/**
 * Χτίζει τα <link rel="preload"> για τις γραμματοσειρές κειμένου του πάνω μέρους.
 *
 * ΤΟ ΠΡΟΒΛΗΜΑ: με το critical CSS στη θέση του, η μόνη μετατόπιση που έμενε ήταν η
 * εναλλαγή γραμματοσειράς — μετρημένο 02/09/2026, CLS 0,132 με γραμματοσειρές και
 * **0,001 χωρίς αυτές**. Το ελληνικό Open Sans Condensed είναι μόλις 7,8 KB και
 * έφτανε στα 1.713 ms, γιατί ο browser το ανακαλύπτει μόνο αφού αναλύσει το CSS και
 * βρει ποιο κείμενο το χρειάζεται. Μέχρι τότε έχει ήδη ζωγραφίσει με την εφεδρική.
 *
 * ΤΟ URL ΠΡΕΠΕΙ ΝΑ ΕΙΝΑΙ ΑΚΡΙΒΕΣ. Ένα preload που διαφέρει έστω στο `?timestamp`
 * κατεβάζει το αρχείο ΔΕΥΤΕΡΗ φορά αντί να προλάβει την πρώτη. Γι' αυτό το URL δεν
 * κατασκευάζεται εδώ — εξάγεται από το ίδιο το critical CSS, που είναι η ίδια πηγή
 * από την οποία θα το ζητήσει ο browser.
 *
 * Η λίστα είναι αρχείο και όχι ρύθμιση επίτηδες: οι ρυθμίσεις addon του CS-Cart
 * σβήνονται ολόκληρες από fn_update_addon_settings(), και ένα κενό preload_fonts
 * θα ήταν αόρατη παλινδρόμηση απόδοσης.
 *
 * @param string $css Το critical CSS όπως θα ενσωματωθεί
 * @return string HTML με τα link tags, ή κενό
 */
function fn_ergonet_critical_css_preload_links(string $css): string
{
    $dir = fn_ergonet_critical_css_dir();
    $list = $dir . 'preload-fonts.txt';
    if ($dir === '' || !is_readable($list)) {
        return '';
    }
    $out = '';
    $seen = [];
    foreach (preg_split('~\R~', (string) file_get_contents($list)) ?: [] as $line) {
        $name = trim($line);
        if ($name === '' || $name[0] === '#') {
            continue;
        }
        // Το basename αρκεί ως κλειδί· η διαδρομή έρχεται από το CSS, όχι από τη λίστα.
        // Τα εισαγωγικά είναι προαιρετικά στο url() και ο combiner τα βάζει —
        // χωρίς αυτά στο μοτίβο, το preload βγαίνει σιωπηλά κενό.
        $re = '~url\(\s*["\']?([^)"\'\s]*' . preg_quote($name, '~') . '[^)"\'\s]*)["\']?\s*\)~i';
        if (!preg_match($re, $css, $m)) {
            continue;
        }
        $url = trim($m[1], "'\" \t");
        // Οι διαδρομές στο bundle είναι σχετικές με πολλά ../· inline στο HTML
        // αναλύονται ως προς το URL της σελίδας και το ../ σταματά στη ρίζα. Τα
        // κανονικοποιούμε ρητά ώστε το preload και το CSS να ζητούν την ΙΔΙΑ συμβολοσειρά.
        $url = '/' . ltrim(preg_replace('~^(\.\./)+~', '', $url), '/');
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        // crossorigin είναι ΥΠΟΧΡΕΩΤΙΚΟ στα fonts ακόμη και same-origin: χωρίς αυτό
        // το preload μπαίνει σε άλλο cache bucket από την αίτηση της CSS και το
        // αρχείο κατεβαίνει δύο φορές.
        $out .= '<link rel="preload" as="font" type="font/woff2" href="'
             . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" crossorigin>';
    }
    return $out;
}

/**
 * Smarty output filter: ενθέτει το critical CSS και ξεμπλοκάρει το bundle.
 *
 * @param string $html Το πλήρες HTML της σελίδας
 * @return string
 */
function fn_ergonet_critical_css_rewrite(string $html): string
{
    // Μόνο ολόκληρες σελίδες. Ένα fetch() για email ή για μπλοκ δεν έχει <head>.
    if (stripos($html, '</head>') === false) {
        return $html;
    }
    // Idempotent: αν για οποιονδήποτε λόγο ξανατρέξει, μην ενθέσεις δεύτερη φορά.
    if (strpos($html, 'id="erg-critical"') !== false) {
        return $html;
    }

    $css = fn_ergonet_critical_css_get();
    if ($css === '') {
        return $html;
    }

    // Το ΕΝΑ render-blocking stylesheet. Το ψάχνουμε με το όνομα που δίνει ο combiner
    // του CS-Cart (`standalone.<hash>.css`), όχι με θέση — η σειρά στο <head> αλλάζει.
    if (!preg_match('~<link[^>]+href="[^"]*/standalone\.[^"]+\.css[^"]*"[^>]*>~i', $html, $m)) {
        return $html;
    }
    $original = $m[0];

    // media="print" κάνει το stylesheet μη-blocking: ο browser το κατεβάζει με χαμηλή
    // προτεραιότητα και ΔΕΝ περιμένει να αποδώσει. Το onload το επαναφέρει σε "all".
    $nonblocking = preg_replace('~\s+media="[^"]*"~i', '', $original);
    $nonblocking = str_replace(
        '>',
        ' media="print" onload="this.media=\'all\';this.onload=null">',
        rtrim($nonblocking, '/>') . '>'
    );

    // ΧΩΡΙΣ ΑΥΤΟ Η ΣΕΛΙΔΑ ΜΕΝΕΙ ΑΣΤΥΛΙΣΤΗ ΜΕ JS ΚΛΕΙΣΤΗ: το onload δεν τρέχει ποτέ,
    // άρα το media μένει "print" για πάντα. Το <noscript> δίνει το κανονικό link.
    // Τα preload μπαίνουν ΠΡΙΝ το inline CSS: ο browser τα βλέπει νωρίτερα στο
    // parsing και ξεκινά τη λήψη ενώ ακόμη διαβάζει τους κανόνες.
    $replacement = fn_ergonet_critical_css_preload_links($css)
        . '<style id="erg-critical">' . $css . '</style>'
        . $nonblocking
        . '<noscript>' . $original . '</noscript>';

    // Μία αντικατάσταση: αν κάποια σελίδα εκπέμψει το link δύο φορές, θέλουμε το inline
    // CSS μία μόνο φορά.
    $pos = strpos($html, $original);
    return substr_replace($html, $replacement, $pos, strlen($original));
}
