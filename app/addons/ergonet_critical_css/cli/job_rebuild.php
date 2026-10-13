<?php
declare(strict_types=1);

/**
 * Εργασία «rebuild» του χρονοπρογραμματιστή του ergonet_core: το cli/rebuild.php ανά βιτρίνα,
 * σε δική της διεργασία. Οι βιτρίνες χωρίς ενεργοποιημένο θέμα βγαίνουν με 0 χωρίς δουλειά.
 * Κωδικός εξόδου ≠ 0 σε οποιαδήποτε βιτρίνα → αποτυχία στην οθόνη του core.
 */

defined('BOOTSTRAP') or die('Access denied');

$erg_result = fn_ergonet_core_run_per_storefront('app/addons/ergonet_critical_css/cli/rebuild.php', [], 'ergonet_critical_css');

if ($erg_result['failed'] > 0) {
    throw new \RuntimeException(sprintf('%d από %d βιτρίνες απέτυχαν στο χτίσιμο του critical CSS', $erg_result['failed'], $erg_result['storefronts']));
}
