'use strict';
/**
 * Χτίζει το critical CSS: τους κανόνες που αγγίζουν το ΑΡΧΙΚΟ παράθυρο, σε όλους
 * τους τύπους σελίδας και σε δύο αναλύσεις.
 *
 * ΓΕΝΝΑΙΟΔΩΡΟΣ ΚΑΤΑ ΣΧΕΔΙΑΣΗ: ένας κανόνας που λείπει φαίνεται στον χρήστη ως
 * αναπήδηση όταν φτάσει το πλήρες stylesheet· ένας παραπανίσιος κοστίζει μόνο bytes.
 * Γι' αυτό κρατάμε 1,25× το ύψος του παραθύρου και αφαιρούμε τις ψευδο-κλάσεις πριν
 * το matching αντί να πετάξουμε τον κανόνα.
 *
 * ΤΟ ΤΑΜΕΙΟ ΘΕΛΕΙ ΓΕΜΑΤΟ ΚΑΛΑΘΙ — αλλιώς ανακατευθύνει και το CSS του λείπει.
 *
 * ΑΝΑ ΤΥΠΟ ΣΕΛΙΔΑΣ (03/09/2026). Μέχρι τώρα όλοι οι τύποι ενώνονταν σε ΕΝΑ
 * αρχείο 110 KB που έμπαινε inline σε ΚΑΘΕ σελίδα: η σελίδα προϊόντος στο κινητό
 * κουβαλούσε 72 κανόνες ταμείου, 22 @keyframes και τους κανόνες του desktop.
 * Το Lighthouse χρέωνε 2.429 ms CPU στο ίδιο το έγγραφο, με μόνο 136 ms JS —
 * το υπόλοιπο ήταν parse/style/layout, και το inline CSS ήταν το 22% του HTML.
 * Τώρα γράφεται ένα αρχείο ανά τύπο (critical.products.css κ.λπ.) ΚΑΙ η ένωση
 * (critical.css) ως εφεδρικό για κάθε controller που δεν αντιστοιχίζεται.
 * Τα @keyframes μπαίνουν μόνο στον τύπο του οποίου οι κανόνες τα αναφέρουν.
 *
 * ΤΟ ΚΡΙΤΗΡΙΟ ΤΟΥ ΑΝΑ-ΤΥΠΟ ΕΙΝΑΙ Η ΥΠΑΡΞΗ, ΟΧΙ Η ΟΡΑΤΟΤΗΤΑ. Η πρώτη εκδοχή
 * κρατούσε ανά τύπο μόνο ό,τι έβλεπε στο παράθυρο (64 KB για το προϊόν) και
 * μετρήθηκε CLS 0,065 σε 4/5 φορτώσεις στα 390px: κανόνες για στοιχεία που
 * ΥΠΑΡΧΟΥΝ στη σελίδα αλλά ήταν κάτω από την πτυχή τη στιγμή της εξαγωγής
 * έλειπαν, και όταν έφτανε το πλήρες stylesheet η διάταξη κουνιόταν. Ίδιο
 * μάθημα με το collapses() παρακάτω. Ανά τύπο κρατιέται κάθε κανόνας της
 * ένωσης που ταιριάζει σε ≥1 στοιχείο της σελίδας του τύπου — στο markup που
 * στέλνει ο server (πριν το JS) ΚΑΙ στο DOM μετά το JS, σε 390 ΚΑΙ 1440. Ένας
 * τύπος πετάει μόνο κανόνες για στοιχεία που δεν έχει καθόλου: 77 KB για το
 * προϊόν, με μηδενική διαφορά στο CLS από τη ζωντανή ένωση.
 *
 * Χρήση: node generate_critical.js <κατάλογος_εξόδου> [base_url]
 * Έξοδος: 0 γράφτηκε · 2 απέτυχε (η προηγούμενη έκδοση ΔΕΝ πειράζεται)
 */

const PW_PATH = process.env.QT_PLAYWRIGHT_PATH || '/opt/ergonet-cookie-scanner/node_modules/playwright';
const { chromium } = require(PW_PATH);
const fs = require('fs');

const OUT_DIR = process.argv[2];
const B   = (process.argv[3] || 'https://qualitytoners.gr').replace(/\/$/, '');
if (!OUT_DIR) {
    console.error('χρήση: generate_critical.js <κατάλογος_εξόδου> [base_url]');
    process.exit(2);
}
const path = require('path');

/* Κάτω από αυτό, κάτι πήγε στραβά (άδειες σελίδες, αποτυχία φόρτωσης). ΔΕΝ γράφουμε:
   μισο-γεμάτο critical είναι χειρότερο από παλιό αλλά σωστό — δίνει σελίδα που
   φαίνεται σπασμένη στην πρώτη απόδοση, σε κάθε επισκέπτη. Μετρήθηκε 96 KB υγιές. */
const MIN_BYTES = parseInt(process.env.QT_CRIT_MIN_BYTES || '40000', 10);

const PROD = process.env.QT_CRIT_PRODUCT
    || B + '/analosima-ektypoton/analosima-ektypoton/lexmark-b2236dw-mb2236adw-mb2236adwe-toner-black-ehc-6k-b222x00-lexb222x00-clone/';

/* Κάθε κανόνας ως { m: συνθήκη @media ή '', c: cssText }. Η ένωση και τα ανά-τύπο
   σύνολα δουλεύουν σε επίπεδο ΚΑΝΟΝΑ, όχι μπλοκ @media, αλλιώς η τομή τους δεν
   ορίζεται· στην έξοδο ξαναομαδοποιούνται ανά συνθήκη ώστε τα bytes να μη διπλασιαστούν. */
const EXTRACT = (vh) => {
    const keep = [], at = [], kf = [], exists = [];
    const inView = (el) => {
        const r = el.getBoundingClientRect();
        return r.bottom >= 0 && r.top <= vh * 1.25 && r.width > 0 && r.height > 0;
    };
    /* ΤΟ ΚΕΝΟ ΠΟΥ ΕΣΤΕΙΛΕ ΤΟ CLS ΣΤΟ 0,88 (μετρήθηκε 02/09/2026).
       Το inView απαιτεί width>0 και height>0 — δηλαδή ένα κρυμμένο στοιχείο δεν
       «φαίνεται» ΠΟΤΕ, άρα ο κανόνας που το κρύβει δεν έμπαινε ποτέ στο critical.
       Αποτέλεσμα: τα 136 `ty-menu__submenu` του bundle έλειπαν, τα υπομενού
       αποδίδονταν ξεδιπλωμένα, και όταν έφτανε το πλήρες stylesheet η κεφαλίδα
       κατέρρεε 1.055 → 285px παρασύροντας ΟΛΗ τη σελίδα.

       Ο κανόνας που αφαιρεί κάτι από τη ροή είναι κρίσιμος ΑΚΡΙΒΩΣ επειδή το
       στοιχείο δεν φαίνεται. Κρατιέται με κριτήριο την ΥΠΑΡΞΗ στο DOM, όχι την
       ορατότητα. Στενό σύνολο τιμών: μόνο όσες αλλάζουν τη ροή. Το visibility και
       το opacity δεν μπαίνουν — δεσμεύουν χώρο, άρα δεν μετατοπίζουν τίποτα. */
    const collapses = (st) => {
        if (!st) { return false; }
        const d = st.getPropertyValue('display').trim();
        if (d === 'none') { return true; }
        const p = st.getPropertyValue('position').trim();
        if (p === 'absolute' || p === 'fixed') { return true; }
        for (const prop of ['height', 'max-height']) {
            const v = st.getPropertyValue(prop).trim();
            if (v === '0' || v === '0px' || v === '0%') { return true; }
        }
        return false;
    };
    /* ΟΙ ΚΑΝΟΝΕΣ ΠΟΥ ΤΟ JS ΕΞΑΦΑΝΙΖΕΙ ΠΡΙΝ ΠΡΟΛΑΒΟΥΜΕ ΝΑ ΤΟΥΣ ΔΟΥΜΕ
       (μετρήθηκε 03/09/2026, Moto G4 4x CPU).

       Η γεννήτρια εξάγει μετά το `load` + 1,2 s — δηλαδή ΑΦΟΥ το owl carousel
       αντικαταστήσει το `ty-scroller__item` με `owl-item`. Το markup που στέλνει
       ο server όμως έχει `ty-scroller__item`, οπότε ο κανόνας που δίνει πλάτος
       στο slide δεν έμπαινε ΠΟΤΕ στο critical: τα 5 slides μοιράζονταν το δοχείο
       των 360px και η εικόνα-ήρωας αποδιδόταν 68x68 αντί 360x360 επί ~3,7 s.
       Το LCP γραφόταν στα 5,8 s ενώ τα pixels ήταν έτοιμα στα 2,0 s.

       Κανένα ευρετικό πάνω στο DOM δεν τους πιάνει — τη στιγμή της μέτρησης δεν
       υπάρχουν. Γι' αυτό δηλώνονται ρητά, και τραβιούνται από το ΙΔΙΟ bundle
       ώστε οι τιμές να μένουν συγχρονισμένες.

       ΠΡΟΣΟΧΗ στο υποσύνολο: πρέπει να μπουν ΚΑΙ οι κανόνες nth-child(n+2) που
       κρύβουν τα slides 2-5. Μόνο το width:100% θα τα στοίβαζε κάθετα και το owl
       θα τα μάζευε μετά — θα ανταλλάσσαμε LCP με CLS. Το κοινό `.ty-scroller__item`
       πιάνει και τα δύο. Ίδια λογική με το collapses() παραπάνω: ο κανόνας είναι
       κρίσιμος ΑΚΡΙΒΩΣ επειδή δεν φαίνεται στη μέτρηση. */
    const FORCE = ['.ty-scroller__item'];
    const forced = (sel) => !!sel && FORCE.some((f) => sel.indexOf(f) !== -1);
    const clean = (sel) => sel.split(',').map((s) => s
        .replace(/::?(before|after|placeholder|selection|backdrop|-[\w-]+)/g, '')
        .replace(/:(hover|focus|focus-within|active|visited|checked|disabled|not\([^)]*\))/g, '').trim())
        .filter(Boolean).join(',');
    /* Ένας κανόνας στυλ: αποφασίζει (α) αν μπαίνει στην ένωση — ορατός στο παράθυρο,
       ή collapse, ή forced — και (β) αν ΥΠΑΡΧΕΙ το στοιχείο του σε αυτή τη σελίδα.
       Το (β) είναι το κριτήριο του ανά-τύπο αρχείου. Ό,τι δεν μπορεί να ελεγχθεί
       (άκυρος selector για το querySelectorAll) θεωρείται και κρατούμενο και υπάρχον:
       ένας κανόνας παραπάνω κοστίζει bytes, ένας λιγότερος κοστίζει αναπήδηση. */
    const styleRule = (rule, m) => {
        const c = rule.cssText;
        if (forced(rule.selectorText)) { keep.push({ m, c }); exists.push({ m, c }); return; }
        const probe = clean(rule.selectorText);
        if (!probe) { keep.push({ m, c }); exists.push({ m, c }); return; }
        let els = [];
        try { els = [...document.querySelectorAll(probe)]; }
        catch (e) { keep.push({ m, c }); exists.push({ m, c }); return; }
        if (els.length) { exists.push({ m, c }); }
        if (els.some(inView) || (els.length && collapses(rule.style))) { keep.push({ m, c }); }
    };
    const walk = (rules) => {
        for (const rule of rules) {
            try {
                if (rule.type === CSSRule.STYLE_RULE) {
                    styleRule(rule, '');
                } else if (rule.type === CSSRule.MEDIA_RULE) {
                    if (!window.matchMedia(rule.conditionText).matches) { continue; }
                    for (const r2 of rule.cssRules) {
                        if (r2.type !== CSSRule.STYLE_RULE) { keep.push({ m: rule.conditionText, c: r2.cssText }); exists.push({ m: rule.conditionText, c: r2.cssText }); continue; }
                        styleRule(r2, rule.conditionText);
                    }
                } else if (rule.type === CSSRule.KEYFRAMES_RULE) {
                    /* Χωριστά και με όνομα: ένα @keyframes είναι κρίσιμο ΜΟΝΟ αν κάποιος
                       κανόνας του ίδιου τύπου σελίδας το αναφέρει σε animation(-name).
                       Τα 22 του bundle (fadein, backslideout, scaletofade…) είναι
                       ειδοποιήσεις και popups — κανένα δεν παίζει στην πρώτη απόδοση. */
                    kf.push({ name: rule.name, css: rule.cssText });
                } else if (rule.type === CSSRule.FONT_FACE_RULE
                        || rule.type === CSSRule.SUPPORTS_RULE || (rule.cssText || '').startsWith(':root')) {
                    at.push(rule.cssText);
                }
            } catch (e) { /* ένας κανόνας που δεν διαβάζεται δεν ακυρώνει τη σάρωση */ }
        }
    };
    for (const sh of document.styleSheets) {
        try {
            /* ΣΥΣΣΩΡΕΥΣΗ: το inline <style id="erg-critical"> ΔΕΝ έχει href, οπότε
               περνούσε τον έλεγχο παρακάτω και η γεννήτρια κατάπινε το προϊόν του
               προηγούμενου τρεξίματος. Κάθε γύρος πρόσθετε ένα ακόμη αντίγραφο των
               @font-face με το τότε ?timestamp — μετρήθηκαν 3 γενιές μαζί. Το
               critical μεγάλωνε μονότονα και ο browser έβλεπε το ίδιο αρχείο
               γραμματοσειράς κάτω από τρία URL. Πηγή είναι ΜΟΝΟ το bundle. */
            if (sh.ownerNode && sh.ownerNode.id === 'erg-critical') { continue; }
            if (sh.href && !sh.href.includes('standalone')) { continue; }
            walk(sh.cssRules);
        } catch (e) { /* cross-origin */ }
    }
    return { keep, at, kf, exists };
};

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox', '--ignore-certificate-errors'] });
    const all = new Map(), at = new Set(), kfAll = new Map();
    const key = (r) => r.m + '\u0000' + r.c;
    /* Ένα σύνολο ΥΠΑΡΞΗΣ ανά τύπο σελίδας. Το κλειδί είναι αυτό που θα ζητήσει το
       func.php από τον controller/mode του CS-Cart — κράτα τα δύο συγχρονισμένα. */
    const existsBy = {};
    const typeFailed = {};
    let failures = 0;

    for (const vp of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
        const ctx = await browser.newContext({ viewport: vp, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        /* Το ίδιο URL φορτώνεται ΔΥΟ φορές: μία με τα scripts αφαιρεμένα (το markup
           όπως το στέλνει ο server — αυτό στυλίζει ο browser πριν τρέξει τίποτα) και
           μία κανονικά. Το owl-item, το erg-fly και ό,τι άλλο προσθέτει το JS υπάρχει
           μόνο στη δεύτερη· το ty-scroller__item μόνο στην πρώτη. Θέλουμε και τα δύο.

           ΣΕ ΞΕΧΩΡΙΣΤΗ ΣΕΛΙΔΑ, ΟΧΙ ΜΕ route/unroute ΣΤΗΝ ΙΔΙΑ. Η πρώτη εκδοχή έβαζε
           routes με function matcher και τα «αφαιρούσε» με page.unroute('**\/*') —
           που αφαιρεί μόνο routes με τον ΙΔΙΟ matcher. Τα routes έμεναν, κάθε επόμενη
           φόρτωση έτρεχε χωρίς scripts, το add-to-cart δεν έτρεχε, το ταμείο
           ανακατεύθυνε στο καλάθι, και από την ένωση έλειπαν 187 κανόνες ταμείου.
           Το έπιασε ο έλεγχος «η νέα ένωση ⊇ η ζωντανή» — κράτα τον. */
        const existsPreJs = async (url) => {
            const p2 = await ctx.newPage();
            try {
                await p2.route((u) => /\.js(\?|$)/.test(u.pathname), (r) => r.abort());
                await p2.route((u) => u.href.split('?')[0] === url.split('?')[0], async (r) => {
                    if (r.request().resourceType() !== 'document') { return r.continue(); }
                    const res = await r.fetch();
                    const html = (await res.text()).replace(/<script[\s\S]*?<\/script>/gi, '');
                    await r.fulfill({ response: res, body: html, headers: Object.assign({}, res.headers(), { 'content-length': String(Buffer.byteLength(html)) }) });
                });
                await p2.goto(url, { waitUntil: 'load', timeout: 120000 });
                return (await p2.evaluate(EXTRACT, vp.height)).exists;
            } finally {
                await p2.close();
            }
        };
        const grab = async (type, name, url, after) => {
            existsBy[type] = existsBy[type] || new Map();
            try {
                // 1. Ύπαρξη στο server-markup (χωρίς JS). Το `after` (ροή ταμείου) θέλει JS,
                //    οπότε για εκείνον τον τύπο η pre-JS μέτρηση γίνεται στο τελικό URL μόνο.
                if (!after) {
                    (await existsPreJs(url)).forEach((x) => existsBy[type].set(key(x), x));
                }
                // 2. Κανονική φόρτωση: ορατότητα (ένωση) + ύπαρξη μετά το JS.
                await page.goto(url, { waitUntil: 'load', timeout: 120000 });
                if (after) { await after(page); }
                await page.waitForTimeout(1200);
                const r = await page.evaluate(EXTRACT, vp.height);
                r.keep.forEach((x) => all.set(key(x), x));
                r.exists.forEach((x) => existsBy[type].set(key(x), x));
                r.at.forEach((x) => at.add(x));
                r.kf.forEach((k) => kfAll.set(k.name, k.css));
                console.error(`  ${name} @${vp.width}: +${r.keep.length} ορατοί, ${existsBy[type].size} υπάρχουν · ένωση=${all.size}`);
            } catch (e) {
                failures++;
                typeFailed[type] = true;
                console.error(`  ${name} @${vp.width}: ΑΠΕΤΥΧΕ ${String(e.message).split('\n')[0].slice(0, 60)}`);
            }
        };
        await grab('index',      'αρχική',    B + '/');
        await grab('categories', 'κατηγορία', B + '/analosima-ektypoton/');
        await grab('products',   'προϊόν',    PROD);
        await grab('search',     'αναζήτηση', B + '/index.php?dispatch=products.search&q=toner');
        await grab('auth',       'σύνδεση',   B + '/index.php?dispatch=auth.login_form');
        await grab('cart',       'καλάθι',    B + '/index.php?dispatch=checkout.cart');
        await grab('checkout',   'ταμείο',    PROD, async (pg) => {
            const btn = await pg.$('button[name*="add_to_cart"], .ty-btn__add-to-cart, [id^="button_cart"]');
            if (btn) { await btn.click().catch(() => {}); await pg.waitForTimeout(2500); }
            await pg.goto(B + '/index.php?dispatch=checkout.checkout', { waitUntil: 'load', timeout: 120000 });
            await pg.waitForTimeout(1500);
        });
        await ctx.close();
    }
    await browser.close();

    /* Ποια @keyframes χρειάζεται ένα σύνολο κανόνων: όσα αναφέρονται σε animation
       ή animation-name. Το όνομα ψάχνεται ως ολόκληρη λέξη, αλλιώς το «fade»
       θα τραβούσε και το «fadein» και το «fadeout». */
    const keyframesFor = (rules) => {
        const text = [...rules].map((r) => r.c).join('\n');
        const used = [];
        for (const [name, css] of kfAll) {
            const esc = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            if (new RegExp('animation(?:-name)?\\s*:[^;{}]*\\b' + esc + '\\b').test(text)) { used.push(css); }
        }
        return used;
    };
    /* Οι κανόνες με την ίδια συνθήκη @media βγαίνουν σε ΕΝΑ μπλοκ, με τη σειρά που
       πρωτοεμφανίστηκαν — η σειρά μετράει στο CSS (ίδια ειδικότητα → νικά ο τελευταίος). */
    const assemble = (rules) => {
        const plain = [], media = new Map();
        for (const r of rules) {
            if (!r.m) { plain.push(r.c); continue; }
            if (!media.has(r.m)) { media.set(r.m, []); }
            media.get(r.m).push(r.c);
        }
        const blocks = [...media.entries()].map(([m, list]) => '@media ' + m + '{' + list.join('') + '}');
        return [...at].join('\n') + '\n' + keyframesFor(rules).join('\n') + '\n' + plain.join('\n') + '\n' + blocks.join('\n');
    };
    const unionRules = [...all.values()];
    const out = assemble(unionRules);

    // Ο φύλακας: μισο-γεμάτο critical δεν γράφεται ΠΟΤΕ πάνω από καλό.
    if (out.length < MIN_BYTES) {
        console.error(`ΑΡΝΗΣΗ: ${out.length} bytes < ${MIN_BYTES} — η προηγούμενη έκδοση μένει ως έχει`);
        process.exit(2);
    }
    if (failures > 2) {
        console.error(`ΑΡΝΗΣΗ: ${failures} σελίδες απέτυχαν — το δείγμα δεν είναι αντιπροσωπευτικό`);
        process.exit(2);
    }

    fs.mkdirSync(OUT_DIR, { recursive: true });
    fs.writeFileSync(path.join(OUT_DIR, 'critical.css'), out);
    console.error(`γράφτηκε: critical.css — ${(out.length / 1024).toFixed(1)} KB, ${all.size} κανόνες (ένωση), ${failures} αποτυχίες`);
    /* Ανά τύπο: γράφεται ΜΟΝΟ αν και οι δύο αναλύσεις του τύπου πέτυχαν. Ένα μισό
       σύνολο (π.χ. μόνο desktop) θα έδινε σπασμένη πρώτη απόδοση στο κινητό·
       χωρίς αρχείο, το func.php πέφτει στην ένωση, που είναι πάντα πλήρης. */
    for (const type of Object.keys(existsBy)) {
        const ex = existsBy[type];
        if (typeFailed[type] || ex.size === 0) {
            console.error(`  ${type}: παραλείπεται (αποτυχία ή κενό) → θα σερβίρεται η ένωση`);
            continue;
        }
        // ένωση ∩ ύπαρξη: μόνο κανόνες που είναι κρίσιμοι ΚΑΠΟΥ και το στοιχείο τους υπάρχει ΕΔΩ
        const rules = unionRules.filter((r) => ex.has(key(r)));
        const css = assemble(rules);
        fs.writeFileSync(path.join(OUT_DIR, `critical.${type}.css`), css);
        console.error(`  critical.${type}.css — ${(css.length / 1024).toFixed(1)} KB, ${rules.length}/${unionRules.length} κανόνες`);
    }
    process.exit(0);
})().catch((e) => {
    console.error('σφάλμα: ' + String(e && e.message ? e.message : e));
    process.exit(2);
});
