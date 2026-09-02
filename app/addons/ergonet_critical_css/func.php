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

    $view->registerFilter('output', 'fn_ergonet_critical_css_rewrite');
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
    $path = Registry::get('config.dir.addons') . 'ergonet_critical_css/assets/critical.css';
    $css = (is_readable($path) ? (string) file_get_contents($path) : '');
    // Κάτω από 1 KB σημαίνει σχεδόν σίγουρα κατεστραμμένο ή μισογραμμένο αρχείο.
    if (strlen($css) < 1024) {
        $css = '';
    }
    return $css;
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
    $replacement = '<style id="erg-critical">' . $css . '</style>'
        . $nonblocking
        . '<noscript>' . $original . '</noscript>';

    // Μία αντικατάσταση: αν κάποια σελίδα εκπέμψει το link δύο φορές, θέλουμε το inline
    // CSS μία μόνο φορά.
    $pos = strpos($html, $original);
    return substr_replace($html, $replacement, $pos, strlen($original));
}
