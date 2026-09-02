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
 * Χρήση: node generate_critical.js <έξοδος.css> [base_url]
 * Έξοδος: 0 γράφτηκε · 2 απέτυχε (η προηγούμενη έκδοση ΔΕΝ πειράζεται)
 */

const PW_PATH = process.env.QT_PLAYWRIGHT_PATH || '/opt/ergonet-cookie-scanner/node_modules/playwright';
const { chromium } = require(PW_PATH);
const fs = require('fs');

const OUT = process.argv[2];
const B   = (process.argv[3] || 'https://qualitytoners.gr').replace(/\/$/, '');
if (!OUT) {
    console.error('χρήση: generate_critical.js <έξοδος.css> [base_url]');
    process.exit(2);
}

/* Κάτω από αυτό, κάτι πήγε στραβά (άδειες σελίδες, αποτυχία φόρτωσης). ΔΕΝ γράφουμε:
   μισο-γεμάτο critical είναι χειρότερο από παλιό αλλά σωστό — δίνει σελίδα που
   φαίνεται σπασμένη στην πρώτη απόδοση, σε κάθε επισκέπτη. Μετρήθηκε 96 KB υγιές. */
const MIN_BYTES = parseInt(process.env.QT_CRIT_MIN_BYTES || '40000', 10);

const PROD = process.env.QT_CRIT_PRODUCT
    || B + '/analosima-ektypoton/analosima-ektypoton/lexmark-b2236dw-mb2236adw-mb2236adwe-toner-black-ehc-6k-b222x00-lexb222x00-clone/';

const EXTRACT = (vh) => {
    const keep = [], at = [];
    const inView = (el) => {
        const r = el.getBoundingClientRect();
        return r.bottom >= 0 && r.top <= vh * 1.25 && r.width > 0 && r.height > 0;
    };
    const clean = (sel) => sel.split(',').map((s) => s
        .replace(/::?(before|after|placeholder|selection|backdrop|-[\w-]+)/g, '')
        .replace(/:(hover|focus|focus-within|active|visited|checked|disabled|not\([^)]*\))/g, '').trim())
        .filter(Boolean).join(',');
    const walk = (rules) => {
        for (const rule of rules) {
            try {
                if (rule.type === CSSRule.STYLE_RULE) {
                    const probe = clean(rule.selectorText);
                    if (!probe) { keep.push(rule.cssText); continue; }
                    let els = [];
                    try { els = [...document.querySelectorAll(probe)]; }
                    catch (e) { keep.push(rule.cssText); continue; }
                    if (els.some(inView)) { keep.push(rule.cssText); }
                } else if (rule.type === CSSRule.MEDIA_RULE) {
                    if (!window.matchMedia(rule.conditionText).matches) { continue; }
                    const inner = [];
                    for (const r2 of rule.cssRules) {
                        if (r2.type !== CSSRule.STYLE_RULE) { inner.push(r2.cssText); continue; }
                        const probe = clean(r2.selectorText);
                        let els = [];
                        try { els = probe ? [...document.querySelectorAll(probe)] : []; }
                        catch (e) { inner.push(r2.cssText); continue; }
                        if (!probe || els.some(inView)) { inner.push(r2.cssText); }
                    }
                    if (inner.length) { keep.push('@media ' + rule.conditionText + '{' + inner.join('') + '}'); }
                } else if (rule.type === CSSRule.FONT_FACE_RULE || rule.type === CSSRule.KEYFRAMES_RULE
                        || rule.type === CSSRule.SUPPORTS_RULE || (rule.cssText || '').startsWith(':root')) {
                    at.push(rule.cssText);
                }
            } catch (e) { /* ένας κανόνας που δεν διαβάζεται δεν ακυρώνει τη σάρωση */ }
        }
    };
    for (const sh of document.styleSheets) {
        try {
            if (sh.href && !sh.href.includes('standalone')) { continue; }
            walk(sh.cssRules);
        } catch (e) { /* cross-origin */ }
    }
    return { keep, at };
};

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox', '--ignore-certificate-errors'] });
    const all = new Set(), at = new Set();
    let failures = 0;

    for (const vp of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
        const ctx = await browser.newContext({ viewport: vp, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        const grab = async (name, url, after) => {
            try {
                await page.goto(url, { waitUntil: 'load', timeout: 120000 });
                if (after) { await after(page); }
                await page.waitForTimeout(1200);
                const r = await page.evaluate(EXTRACT, vp.height);
                r.keep.forEach((x) => all.add(x));
                r.at.forEach((x) => at.add(x));
                console.error(`  ${name} @${vp.width}: +${r.keep.length} → ${all.size}`);
            } catch (e) {
                failures++;
                console.error(`  ${name} @${vp.width}: ΑΠΕΤΥΧΕ ${String(e.message).split('\n')[0].slice(0, 60)}`);
            }
        };
        await grab('αρχική',    B + '/');
        await grab('κατηγορία', B + '/analosima-ektypoton/');
        await grab('προϊόν',    PROD);
        await grab('αναζήτηση', B + '/index.php?dispatch=products.search&q=toner');
        await grab('σύνδεση',   B + '/index.php?dispatch=auth.login_form');
        await grab('καλάθι',    B + '/index.php?dispatch=checkout.cart');
        await grab('ταμείο',    PROD, async (pg) => {
            const btn = await pg.$('button[name*="add_to_cart"], .ty-btn__add-to-cart, [id^="button_cart"]');
            if (btn) { await btn.click().catch(() => {}); await pg.waitForTimeout(2500); }
            await pg.goto(B + '/index.php?dispatch=checkout.checkout', { waitUntil: 'load', timeout: 120000 });
            await pg.waitForTimeout(1500);
        });
        await ctx.close();
    }
    await browser.close();

    const out = [...at].join('\n') + '\n' + [...all].join('\n');

    // Ο φύλακας: μισο-γεμάτο critical δεν γράφεται ΠΟΤΕ πάνω από καλό.
    if (out.length < MIN_BYTES) {
        console.error(`ΑΡΝΗΣΗ: ${out.length} bytes < ${MIN_BYTES} — η προηγούμενη έκδοση μένει ως έχει`);
        process.exit(2);
    }
    if (failures > 2) {
        console.error(`ΑΡΝΗΣΗ: ${failures} σελίδες απέτυχαν — το δείγμα δεν είναι αντιπροσωπευτικό`);
        process.exit(2);
    }

    fs.writeFileSync(OUT, out);
    console.error(`γράφτηκε: ${OUT} — ${(out.length / 1024).toFixed(1)} KB, ${all.size} κανόνες, ${failures} αποτυχίες`);
    process.exit(0);
})().catch((e) => {
    console.error('σφάλμα: ' + String(e && e.message ? e.message : e));
    process.exit(2);
});
