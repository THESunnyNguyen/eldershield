<?php
// pages/about.php — Public landing page (no login required)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ElderShield — Protecting Seniors from Scams</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
        /* ── Landing page specific styles ── */
        .landing-nav {
            position: sticky; top: 0; z-index: 100;
            background: #fff;
            border-bottom: 1px solid var(--color-border);
            padding: 0 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
            height: 64px; box-shadow: var(--shadow);
        }
        .landing-nav-brand { font-size: 1.4rem; font-weight: 800; color: var(--color-primary); text-decoration: none; }
        .landing-nav-links { display: flex; gap: .75rem; align-items: center; }

        /* ── Hero ── */
        .hero {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #06b6d4 100%);
            color: #fff; text-align: center;
            padding: 5rem 1.5rem 4rem;
        }
        .hero h1 { font-size: clamp(2rem, 6vw, 3.5rem); font-weight: 900; margin-bottom: 1rem; line-height: 1.15; }
        .hero p  { font-size: clamp(1.05rem, 2.5vw, 1.3rem); opacity: .92; max-width: 640px; margin: 0 auto 2rem; line-height: 1.7; }
        .hero-cta { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
        .btn-hero-primary { background: #fff; color: var(--color-primary); font-size: 1.1rem; padding: .85rem 2.25rem; border-radius: 9999px; font-weight: 800; text-decoration: none; transition: transform .15s, box-shadow .15s; box-shadow: 0 4px 14px rgba(0,0,0,.15); }
        .btn-hero-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.2); text-decoration: none; color: var(--color-primary); }
        .btn-hero-outline { background: transparent; color: #fff; border: 2px solid rgba(255,255,255,.7); font-size: 1.1rem; padding: .85rem 2.25rem; border-radius: 9999px; font-weight: 700; text-decoration: none; transition: background .15s; }
        .btn-hero-outline:hover { background: rgba(255,255,255,.15); text-decoration: none; color: #fff; }

        /* ── Stats strip ── */
        .stats-strip { background: var(--color-primary); color: #fff; padding: 1.5rem; display: flex; justify-content: center; gap: 3rem; flex-wrap: wrap; text-align: center; }
        .strip-stat-number { font-size: 2rem; font-weight: 900; }
        .strip-stat-label  { font-size: .85rem; opacity: .85; margin-top: .1rem; }

        /* ── Sections ── */
        .section { padding: 4rem 1.5rem; max-width: 1100px; margin: 0 auto; }
        .section-center { text-align: center; }
        .section h2 { font-size: clamp(1.5rem, 4vw, 2.2rem); margin-bottom: .75rem; }
        .section-lead { font-size: 1.1rem; color: var(--color-muted); max-width: 640px; margin: 0 auto 2.5rem; line-height: 1.7; }

        /* ── How it works ── */
        .steps-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; }
        .step-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius); padding: 1.75rem 1.5rem; text-align: center; box-shadow: var(--shadow); }
        .step-icon { font-size: 2.5rem; margin-bottom: .75rem; }
        .step-num  { display: inline-block; background: var(--color-primary); color: #fff; border-radius: 50%; width: 1.8rem; height: 1.8rem; line-height: 1.8rem; font-weight: 800; font-size: .9rem; margin-bottom: .5rem; }
        .step-card h3 { margin-bottom: .5rem; font-size: 1.05rem; }
        .step-card p  { font-size: .9rem; color: var(--color-muted); line-height: 1.6; }

        /* ── Who it's for ── */
        .audience-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; }
        .audience-card { background: var(--color-surface); border-radius: var(--radius); border: 2px solid var(--color-border); padding: 2rem; box-shadow: var(--shadow); }
        .audience-card.featured { border-color: var(--color-primary); background: #eff6ff; }
        .audience-card h3 { font-size: 1.2rem; margin-bottom: .5rem; }
        .audience-card p  { color: var(--color-muted); font-size: .95rem; line-height: 1.6; margin-bottom: 1rem; }
        .audience-list { list-style: none; }
        .audience-list li { padding: .3rem 0; font-size: .95rem; }
        .audience-list li::before { content: "✅ "; }

        /* ── Pricing ── */
        .pricing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; max-width: 800px; margin: 0 auto; }
        .pricing-card { background: var(--color-surface); border: 2px solid var(--color-border); border-radius: var(--radius); padding: 2rem; text-align: center; box-shadow: var(--shadow); }
        .pricing-card.featured { border-color: var(--color-primary); position: relative; }
        .pricing-badge { position: absolute; top: -14px; left: 50%; transform: translateX(-50%); background: var(--color-primary); color: #fff; padding: .25rem 1rem; border-radius: 999px; font-size: .8rem; font-weight: 700; white-space: nowrap; }
        .pricing-who  { font-size: .85rem; color: var(--color-muted); margin-bottom: .5rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }
        .pricing-price { font-size: 2.8rem; font-weight: 900; color: var(--color-primary); line-height: 1; margin: .5rem 0; }
        .pricing-price span { font-size: 1rem; font-weight: 400; color: var(--color-muted); }
        .pricing-list { list-style: none; margin: 1.25rem 0 1.5rem; text-align: left; }
        .pricing-list li { padding: .4rem 0; font-size: .95rem; border-bottom: 1px solid var(--color-border); }
        .pricing-list li:last-child { border: none; }

        /* ── Scam types ── */
        .scam-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; }
        .scam-chip { background: #fff; border: 1px solid var(--color-border); border-radius: var(--radius); padding: 1rem; text-align: center; box-shadow: var(--shadow); font-size: .9rem; font-weight: 600; }
        .scam-chip-icon { font-size: 1.6rem; display: block; margin-bottom: .4rem; }

        /* ── CTA band ── */
        .cta-band { background: linear-gradient(135deg, #1e40af, #3b82f6); color: #fff; text-align: center; padding: 4rem 1.5rem; }
        .cta-band h2 { font-size: clamp(1.5rem, 4vw, 2.2rem); margin-bottom: .75rem; }
        .cta-band p  { opacity: .9; margin-bottom: 2rem; font-size: 1.05rem; }

        /* ── Footer ── */
        .landing-footer { background: #111827; color: #9ca3af; text-align: center; padding: 2rem 1.5rem; font-size: .875rem; }
        .landing-footer a { color: #d1d5db; }

        /* ── Mobile nav ── */
        .nav-hamburger { display: none; background: none; border: none; font-size: 1.6rem; cursor: pointer; padding: .25rem; }
        @media (max-width: 600px) {
            .landing-nav-links { display: none; flex-direction: column; position: absolute; top: 64px; left: 0; right: 0; background: #fff; border-bottom: 1px solid var(--color-border); padding: 1rem; gap: .5rem; box-shadow: 0 4px 12px rgba(0,0,0,.1); }
            .landing-nav-links.open { display: flex; }
            .nav-hamburger { display: block; }
            .stats-strip { gap: 1.5rem; }
        }
    </style>
</head>
<body>

<!-- ── Sticky Nav ─────────────────────────────────────────── -->
<nav class="landing-nav">
    <a href="#" class="landing-nav-brand">🛡️ ElderShield</a>
    <button class="nav-hamburger" id="hamburger" aria-label="Menu">☰</button>
    <div class="landing-nav-links" id="navLinks">
        <a href="#how-it-works" class="btn btn-outline btn-sm">How It Works</a>
        <a href="#pricing"      class="btn btn-outline btn-sm">Pricing</a>
        <a href="<?= APP_URL ?>/pages/login.php"    class="btn btn-secondary btn-sm">Log In</a>
        <a href="<?= APP_URL ?>/pages/register.php" class="btn btn-primary btn-sm">Sign Up Free</a>
    </div>
</nav>

<!-- ── Hero ──────────────────────────────────────────────── -->
<section class="hero">
    <h1>🛡️ Protecting Seniors<br>from Scams</h1>
    <p>ElderShield uses AI to instantly analyze suspicious messages, calls, and emails —
       giving elderly users clear, plain-language guidance on what to do next.</p>
    <div class="hero-cta">
        <a href="<?= APP_URL ?>/pages/register.php" class="btn-hero-primary">Get Started Free</a>
        <a href="#how-it-works" class="btn-hero-outline">Learn More ↓</a>
    </div>
</section>

<!-- ── Stats strip ───────────────────────────────────────── -->
<div class="stats-strip">
    <div>
        <div class="strip-stat-number">$10B+</div>
        <div class="strip-stat-label">Lost to elder scams yearly (FBI)</div>
    </div>
    <div>
        <div class="strip-stat-number">1 in 5</div>
        <div class="strip-stat-label">Seniors targeted annually</div>
    </div>
    <div>
        <div class="strip-stat-number">AI</div>
        <div class="strip-stat-label">Instant scam detection</div>
    </div>
    <div>
        <div class="strip-stat-number">Free</div>
        <div class="strip-stat-label">Always free for seniors</div>
    </div>
</div>

<!-- ── How It Works ──────────────────────────────────────── -->
<section class="section section-center" id="how-it-works">
    <h2>How ElderShield Works</h2>
    <p class="section-lead">Three simple steps to protect the seniors you care about.</p>
    <div class="steps-grid">
        <div class="step-card">
            <div class="step-icon">📝</div>
            <div class="step-num">1</div>
            <h3>Submit the Message</h3>
            <p>Type out the suspicious message or call, or upload a screenshot. Takes less than a minute.</p>
        </div>
        <div class="step-card">
            <div class="step-icon">🤖</div>
            <div class="step-num">2</div>
            <h3>AI Analyzes It</h3>
            <p>Our AI scans for urgency tactics, impersonation, financial pressure, and 20+ other scam patterns.</p>
        </div>
        <div class="step-card">
            <div class="step-icon">📊</div>
            <div class="step-num">3</div>
            <h3>Get Clear Results</h3>
            <p>A plain-English risk score, explanation, and step-by-step guidance on exactly what to do next.</p>
        </div>
        <div class="step-card">
            <div class="step-icon">🔔</div>
            <div class="step-num">4</div>
            <h3>Caregivers Notified</h3>
            <p>High-risk reports automatically alert linked caregivers so they can step in before harm occurs.</p>
        </div>
    </div>
</section>

<!-- ── Who It's For ───────────────────────────────────────── -->
<div style="background: #f0f9ff; padding: 4rem 1.5rem;">
<section class="section" style="padding-top:0;padding-bottom:0;">
    <div class="section-center" style="margin-bottom:2rem;">
        <h2>Who ElderShield Is Built For</h2>
        <p class="section-lead">Whether you're a senior staying safe or a caregiver protecting those in your care.</p>
    </div>
    <div class="audience-grid">
        <div class="audience-card">
            <h3>👴 Elderly Users</h3>
            <p>Get instant, easy-to-understand analysis of anything that seems suspicious — completely free, forever.</p>
            <ul class="audience-list">
                <li>Unlimited scam reports — always free</li>
                <li>Plain-language explanations</li>
                <li>Screenshot & text analysis</li>
                <li>What-to-do guidance</li>
            </ul>
        </div>
        <div class="audience-card featured">
            <h3>👩‍⚕️ Individual Caregivers</h3>
            <p>Monitor the seniors you look after and get alerted when something looks dangerous.</p>
            <ul class="audience-list">
                <li>2 free elder account links</li>
                <li>Real-time high-risk alerts</li>
                <li>Incident history dashboard</li>
                <li>Upgrade for unlimited links</li>
            </ul>
        </div>
        <div class="audience-card featured">
            <h3>🏥 Nursing Home Staff</h3>
            <p>Manage all your residents in one dashboard. Premium gives you unlimited elder links for one flat fee.</p>
            <ul class="audience-list">
                <li>Unlimited elder accounts (Premium)</li>
                <li>Centralized incident monitoring</li>
                <li>Early intervention alerts</li>
                <li>Just $9.99/month flat</li>
            </ul>
        </div>
    </div>
</section>
</div>

<!-- ── Scam Types We Detect ───────────────────────────────── -->
<section class="section section-center">
    <h2>Scam Types We Detect</h2>
    <p class="section-lead">Our AI recognizes the most common and dangerous scams targeting seniors.</p>
    <div class="scam-grid">
        <div class="scam-chip"><span class="scam-chip-icon">🎣</span>Phishing</div>
        <div class="scam-chip"><span class="scam-chip-icon">🎭</span>Impersonation</div>
        <div class="scam-chip"><span class="scam-chip-icon">💻</span>Tech Support</div>
        <div class="scam-chip"><span class="scam-chip-icon">💝</span>Romance Scams</div>
        <div class="scam-chip"><span class="scam-chip-icon">🎰</span>Lottery / Prize</div>
        <div class="scam-chip"><span class="scam-chip-icon">👴</span>Grandparent</div>
        <div class="scam-chip"><span class="scam-chip-icon">📈</span>Investment Fraud</div>
        <div class="scam-chip"><span class="scam-chip-icon">🏛️</span>IRS / Gov</div>
    </div>
</section>

<!-- ── Pricing ────────────────────────────────────────────── -->
<div style="background:#f0f9ff; padding: 4rem 1.5rem;" id="pricing">
<section class="section" style="padding-top:0;padding-bottom:0;">
    <div class="section-center" style="margin-bottom:2.5rem;">
        <h2>Simple, Honest Pricing</h2>
        <p class="section-lead">Seniors always use ElderShield for free. Caregivers who manage multiple elders can unlock unlimited links for a flat monthly fee.</p>
    </div>
    <div class="pricing-grid">
        <div class="pricing-card">
            <div class="pricing-who">For Seniors</div>
            <div class="pricing-price">$0<span>/month</span></div>
            <p style="color:var(--color-muted);font-size:.9rem;margin-bottom:.5rem;">Always free. No credit card. No limits.</p>
            <ul class="pricing-list">
                <li>✅ Unlimited scam reports</li>
                <li>✅ AI risk analysis</li>
                <li>✅ Screenshot analysis</li>
                <li>✅ Plain-language guidance</li>
            </ul>
            <a href="<?= APP_URL ?>/pages/register.php" class="btn btn-primary btn-full">Create Free Account</a>
        </div>
        <div class="pricing-card">
            <div class="pricing-who">Caregiver — Free</div>
            <div class="pricing-price">$0<span>/month</span></div>
            <p style="color:var(--color-muted);font-size:.9rem;margin-bottom:.5rem;">Great for individual caregivers.</p>
            <ul class="pricing-list">
                <li>✅ Up to 2 linked elders</li>
                <li>✅ Incident dashboard</li>
                <li>✅ High-risk alerts</li>
                <li>❌ More than 2 elders</li>
            </ul>
            <a href="<?= APP_URL ?>/pages/register.php" class="btn btn-secondary btn-full">Get Started</a>
        </div>
        <div class="pricing-card featured">
            <div class="pricing-badge">⭐ Most Popular</div>
            <div class="pricing-who">Caregiver — Premium</div>
            <div class="pricing-price">$9.99<span>/month</span></div>
            <p style="color:var(--color-muted);font-size:.9rem;margin-bottom:.5rem;">Perfect for nursing home staff.</p>
            <ul class="pricing-list">
                <li>✅ Unlimited linked elders</li>
                <li>✅ Incident dashboard</li>
                <li>✅ High-risk alerts</li>
                <li>✅ Priority support</li>
            </ul>
            <a href="<?= APP_URL ?>/pages/register.php" class="btn btn-primary btn-full">Start Premium</a>
        </div>
    </div>
</section>
</div>

<!-- ── Final CTA ──────────────────────────────────────────── -->
<div class="cta-band">
    <h2>Start Protecting Your Loved Ones Today</h2>
    <p>Join ElderShield — free for seniors, affordable for caregivers.</p>
    <div class="hero-cta">
        <a href="<?= APP_URL ?>/pages/register.php" class="btn-hero-primary">Create Free Account</a>
        <a href="<?= APP_URL ?>/pages/login.php"    class="btn-hero-outline">Log In</a>
    </div>
</div>

<!-- ── Footer ─────────────────────────────────────────────── -->
<footer class="landing-footer">
    <p>🛡️ ElderShield — AI-Powered Scam Detection for Seniors</p>
    <p style="margin-top:.5rem;">If you suspect a scam call, contact the FTC at <strong style="color:#d1d5db;">1-877-382-4357</strong></p>
</footer>

<script>
// Mobile nav toggle
document.getElementById('hamburger').addEventListener('click', function() {
    document.getElementById('navLinks').classList.toggle('open');
});
// Close nav when a link is clicked
document.querySelectorAll('#navLinks a').forEach(a => {
    a.addEventListener('click', () => document.getElementById('navLinks').classList.remove('open'));
});
// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); }
    });
});
</script>
</body>
</html>
