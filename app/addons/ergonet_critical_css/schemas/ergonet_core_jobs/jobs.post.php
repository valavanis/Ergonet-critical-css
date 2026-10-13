<?php
/**
 * Η χρονισμένη εργασία του critical CSS, ΔΗΛΩΤΙΚΑ.
 *
 * Αντιστοιχεί στη γραμμή /etc/cron.d/qt-critical-css του qualitytoners.gr (που μένει ως έχει
 * εκεί μέχρι τη μεταφορά). Ωριαία, γιατί ο έλεγχος είναι ΕΝΑ αίτημα σελίδας + ένα κατέβασμα
 * bundle· ξαναχτίζει μόνο όταν αλλάξει όντως το CSS του θέματος, οπότε το παράθυρο παλαίωσης
 * είναι το πολύ μία ώρα με σχεδόν μηδενικό κόστος.
 *
 * Στο :25, μακριά από τα :00/:05 του φύλακα του FPC και τα :07/:27/:47 του warmer: αν
 * ξαναχτιστεί, το FPC αδειάζει και ο warmer του :27 το ξαναγεμίζει αμέσως μετά.
 *
 * self_locks=true: το rebuild.php κλειδώνει ανά θέμα με flock στον φάκελό του.
 *
 * ΠΡΕΠΕΙ να τελειώνει με `return $schema;`.
 */

$schema['ergonet_critical_css.rebuild'] = array(
    'addon'      => 'ergonet_critical_css',
    'title'      => 'Critical CSS: ξαναχτίσιμο όταν αλλάξει το CSS του θέματος',
    'schedule'   => '25 * * * *',
    'script'     => 'app/addons/ergonet_critical_css/cli/job_rebuild.php',
    'memory'     => '256M',
    'timeout'    => 900,
    'self_locks' => true,
);

return $schema;
