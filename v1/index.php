<?php
// /web/api.iernc.net/public_html/v1/index.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once dirname(__DIR__) . '/lib/bootstrap.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Agent Hive - Private AI Ops and Search API</title>
  <meta name="description" content="Agent Hive is a private, LAN-first API and agent stack for search, notes, automation, and local AI workflows under your control." />
  <meta name="robots" content="noindex,nofollow" />
  <meta name="theme-color" content="#0b0d10" />
  <style>
    :root {
      --bg: #0b0d10;
      --ink: #e6e6e6;
      --mute: #9aa3ad;
      --edge: #1c222b;
      --accent: #7c5cff;
      --accent-2: #11c3ff;
      --good: #45d483;
      --warn: #ffc107;
      --bad: #ff5e66;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      margin: 0;
      font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: radial-gradient(1200px 800px at 20% -10%, #12151b 0%, var(--bg) 60%) fixed;
      color: var(--ink);
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
    }
    a { color: inherit; text-decoration: none; }
    
    /* Navigation */
    nav {
      position: sticky;
      top: 0;
      background: rgba(11, 13, 16, 0.95);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--edge);
      z-index: 100;
      padding: 16px 0;
    }
    nav .wrap {
      width: min(1200px, 92vw);
      margin: 0 auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 20px;
    }
    nav .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      font-weight: 800;
      font-size: 18px;
    }
    nav .nav-links {
      display: flex;
      gap: 32px;
      align-items: center;
    }
    nav .nav-links a {
      color: var(--mute);
      font-weight: 500;
      transition: color 0.2s;
    }
    nav .nav-links a:hover { color: var(--ink); }
    @media (max-width: 768px) {
      nav .nav-links { gap: 16px; font-size: 14px; }
      nav .nav-links .hide-mobile { display: none; }
    }

    /* Container */
    .wrap { width: min(1200px, 92vw); margin: 0 auto; padding: 0 20px; }
    
    /* Hero Section */
    .hero {
      padding: 80px 0 60px;
      text-align: center;
    }
    .logo {
      width: 64px; height: 64px; display: inline-grid; place-items: center;
      border-radius: 18px;
      background: linear-gradient(135deg, var(--accent), var(--accent-2));
      box-shadow: 0 8px 32px rgba(124, 92, 255, 0.3), 0 12px 40px rgba(17, 195, 255, 0.2);
      margin-bottom: 24px;
    }
    .logo svg { width: 38px; height: 38px; fill: white; }
    
    h1 { 
      font-size: clamp(38px, 5vw, 56px); 
      font-weight: 900; 
      letter-spacing: -0.02em; 
      line-height: 1.1;
      margin-bottom: 20px;
      color: #f8fbff;
      text-shadow: 0 2px 22px rgba(17, 195, 255, 0.16);
    }
    
    .hero-subtitle {
      font-size: clamp(18px, 2.5vw, 22px);
      color: var(--mute);
      max-width: 720px;
      margin: 0 auto 32px;
      line-height: 1.5;
    }
    
    .hero-cta {
      display: flex;
      gap: 16px;
      justify-content: center;
      flex-wrap: wrap;
      margin-bottom: 48px;
    }
    .hero-shot {
      max-width: 980px;
      margin: 0 auto 36px;
      border-radius: 22px;
      overflow: hidden;
      border: 1px solid rgba(124, 92, 255, 0.28);
      background: linear-gradient(180deg, rgba(18, 21, 27, 0.92), rgba(11, 13, 16, 0.98));
      box-shadow: 0 24px 70px rgba(0,0,0,0.48), 0 0 0 1px rgba(17,195,255,0.08) inset;
    }
    .hero-shot img {
      display: block;
      width: 100%;
      height: auto;
    }

    /* Trust badges */
    .trust-row {
      display: flex;
      justify-content: center;
      gap: 32px;
      flex-wrap: wrap;
      padding: 24px 0;
      border-top: 1px solid var(--edge);
      border-bottom: 1px solid var(--edge);
      margin-top: 40px;
    }
    .trust-item {
      display: flex;
      align-items: center;
      gap: 8px;
      color: var(--mute);
      font-size: 14px;
      font-weight: 600;
    }
    .trust-icon {
      width: 20px;
      height: 20px;
      fill: var(--good);
    }

    /* Value Propositions */
    .section {
      padding: 80px 0;
    }
    .section-header {
      text-align: center;
      max-width: 800px;
      margin: 0 auto 48px;
    }
    h2 {
      font-size: clamp(32px, 4vw, 42px);
      font-weight: 800;
      margin-bottom: 16px;
      letter-spacing: -0.01em;
    }
    .section-subtitle {
      font-size: 18px;
      color: var(--mute);
      line-height: 1.6;
    }

    /* Cards Grid */
    .grid { 
      display: grid; 
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); 
      gap: 24px; 
      margin-top: 40px; 
    }
    .card {
      background: linear-gradient(180deg, rgba(28, 34, 43, 0.4), rgba(11, 13, 16, 0.6));
      border: 1px solid var(--edge);
      border-radius: 20px;
      padding: 32px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.3);
      transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
      position: relative;
      overflow: hidden;
    }
    .card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--accent), var(--accent-2));
      opacity: 0;
      transition: opacity 0.2s;
    }
    .card:hover {
      transform: translateY(-4px);
      border-color: rgba(124, 92, 255, 0.4);
      box-shadow: 0 20px 50px rgba(0,0,0,0.4), 0 0 40px rgba(124, 92, 255, 0.1);
    }
    .card:hover::before {
      opacity: 1;
    }
    .card-icon {
      width: 48px;
      height: 48px;
      padding: 12px;
      border-radius: 12px;
      background: linear-gradient(135deg, rgba(124, 92, 255, 0.2), rgba(17, 195, 255, 0.2));
      margin-bottom: 20px;
    }
    .card-icon svg {
      width: 100%;
      height: 100%;
      fill: var(--accent-2);
    }
    h3 { 
      font-size: 22px; 
      font-weight: 700; 
      margin-bottom: 12px; 
      color: var(--ink);
    }
    .card p { 
      color: var(--mute); 
      line-height: 1.7; 
      margin-bottom: 16px;
    }
    .card ul { 
      list-style: none;
      margin: 16px 0;
    }
    .card ul li {
      color: var(--mute);
      padding-left: 24px;
      position: relative;
      margin-bottom: 10px;
      line-height: 1.6;
    }
    .card ul li::before {
      content: '✓';
      position: absolute;
      left: 0;
      color: var(--good);
      font-weight: 700;
    }

    /* Feature comparison */
    .comparison {
      background: linear-gradient(180deg, rgba(28, 34, 43, 0.3), rgba(11, 13, 16, 0.5));
      border: 1px solid var(--edge);
      border-radius: 20px;
      padding: 40px;
      margin: 40px 0;
    }
    .comparison-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 40px;
      margin-top: 32px;
    }
    .comparison h4 {
      font-size: 16px;
      font-weight: 700;
      margin-bottom: 16px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .vs-left h4 { color: var(--bad); }
    .vs-right h4 { color: var(--good); }
    
    @media (max-width: 768px) {
      .comparison-grid { grid-template-columns: 1fr; gap: 24px; }
    }

    /* Buttons */
    .btn {
      appearance: none;
      border: 1px solid var(--edge);
      background: #12161e;
      color: var(--ink);
      padding: 14px 28px;
      border-radius: 12px;
      font-weight: 700;
      font-size: 16px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      transition: all 0.2s ease;
      cursor: pointer;
    }
    .btn:hover {
      transform: translateY(-2px);
      border-color: #263041;
      box-shadow: 0 8px 20px rgba(0,0,0,0.3);
    }
    .btn.primary {
      background: linear-gradient(135deg, var(--accent), var(--accent-2));
      color: white;
      border: none;
      box-shadow: 0 8px 24px rgba(124,92,255,.3), 0 12px 30px rgba(17,195,255,.2);
      font-weight: 800;
      font-size: 17px;
    }
    .btn.primary:hover {
      box-shadow: 0 12px 32px rgba(124,92,255,.4), 0 16px 40px rgba(17,195,255,.3);
    }
    .btn.secondary {
      background: transparent;
      border: 2px solid rgba(124, 92, 255, 0.5);
      color: var(--ink);
    }

    /* API Documentation */
    pre {
      background: #0b0f15;
      border: 1px solid var(--edge);
      border-radius: 12px;
      padding: 20px;
      overflow-x: auto;
      margin: 20px 0;
    }
    code {
      font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 14px;
      line-height: 1.6;
    }

    /* Stats */
    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 32px;
      margin: 48px 0;
    }
    .stat {
      text-align: center;
    }
    .stat-value {
      font-size: 48px;
      font-weight: 900;
      background: linear-gradient(135deg, var(--accent), var(--accent-2));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      display: block;
      margin-bottom: 8px;
    }
    .stat-label {
      color: var(--mute);
      font-weight: 600;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    /* Footer */
    .footer {
      margin-top: 100px;
      padding: 40px 0 24px;
      border-top: 1px solid var(--edge);
      color: var(--mute);
      font-size: 14px;
    }
    .footer-content {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
    }
    .footer-links {
      display: flex;
      gap: 24px;
    }
    .footer-links a:hover {
      color: var(--ink);
    }
    .status {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-weight: 700;
    }
    .status-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--good);
      box-shadow: 0 0 12px var(--good);
      animation: pulse 2s infinite;
    }
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }

    /* Badges */
    .pillrow { 
      display: flex; 
      flex-wrap: wrap; 
      gap: 10px; 
      margin-top: 16px; 
    }
    .pill {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 12px;
      font-weight: 600;
      padding: 6px 12px;
      border-radius: 6px;
      background: rgba(124, 92, 255, 0.1);
      border: 1px solid rgba(124, 92, 255, 0.3);
      color: var(--accent-2);
    }
  </style>
</head>
<body>
  <!-- Navigation -->
  <nav>
    <div class="wrap">
      <div class="brand">
        <div class="logo" style="width: 36px; height: 36px; border-radius: 10px;">
          <svg viewBox="0 0 24 24" style="width: 22px; height: 22px;">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/>
          </svg>
        </div>
        Agent Hive
      </div>
      <div class="nav-links">
        <a href="#features" class="hide-mobile">Features</a>
        <a href="#security" class="hide-mobile">Security</a>
        <a href="#api">API</a>
        <a href="/v1/health">Status</a>
        <a href="#contact" class="btn primary" style="padding: 10px 20px; font-size: 14px;">Get Started</a>
      </div>
    </div>
  </nav>

  <!-- Hero Section -->
  <section class="hero">
    <div class="wrap">
      <div class="logo">
        <svg viewBox="0 0 24 24">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/>
        </svg>
      </div>
      <h1>Private Agents, Search, and Memory<br/>That Stay On Your Network</h1>
      <p class="hero-subtitle">
        A private "digital employee" that lives on your network. Enterprise-grade AI memory layer for teams who need intelligence without cloud dependency.
        Keep your operational data private, secure, and under your complete control.
      </p>
      <div class="hero-cta">
        <a href="https://www.iernc.com/contact/" class="btn primary">Request API Access</a>
        <a href="/v1/health" class="btn secondary">View System Status</a>
        <a href="https://github.com/ier1990/agent-hive" class="btn" rel="noopener">Documentation</a>
      </div>
      <div class="hero-shot">
        <img src="/agent_shell.png" alt="Agent Hive shell interface screenshot" loading="eager" />
      </div>

      <!-- Trust Indicators -->
      <div class="trust-row">
        <div class="trust-item">
          <svg class="trust-icon" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
          100% On-Premise
        </div>
        <div class="trust-item">
          <svg class="trust-icon" viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zM9 8V6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9z"/></svg>
          Zero Cloud Dependency
        </div>
        <div class="trust-item">
          <svg class="trust-icon" viewBox="0 0 24 24"><path d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
          LAN-First Architecture
        </div>
        <div class="trust-item">
          <svg class="trust-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
          Apache 2.0 Licensed
        </div>
      </div>
    </div>
  </section>

  <!-- Problem/Solution Section -->
  <section class="section" style="background: rgba(28, 34, 43, 0.2);">
    <div class="wrap">
      <div class="section-header">
        <h2>Your Private Digital Employee</h2>
        <p class="section-subtitle">
          Most AI tools force you to choose between intelligence and control. Agent Hive keeps the useful parts local: private agents, searchable memory, and API workflows that stay on your infrastructure.
        </p>
      </div>

      <div class="comparison">
        <div class="comparison-grid">
          <div class="vs-left">
            <h4>❌ Cloud AI Services</h4>
            <ul>
              <li>Send all data to third parties</li>
              <li>Require internet connectivity</li>
              <li>Lock you into proprietary dashboards</li>
              <li>Charge per API call at scale</li>
              <li>Learn nothing about YOUR environment</li>
              <li>Subject to service interruptions</li>
            </ul>
          </div>
          <div class="vs-right">
            <h4>✓ Agent Hive</h4>
            <ul>
              <li>All data stays on your network</li>
              <li>Works completely offline</li>
              <li>Open API with full control</li>
              <li>Flat hosting cost, unlimited usage</li>
              <li>Learns your operational patterns</li>
              <li>Always available, LAN-speed</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Features Section -->
  <section class="section" id="features">
    <div class="wrap">
      <div class="section-header">
        <h2>Enterprise Features, Small Team Simplicity</h2>
        <p class="section-subtitle">
          Everything you need for intelligent operational memory, nothing you don't.
        </p>
      </div>

      <div class="grid">
        <div class="card">
          <div class="card-icon">
            <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
          </div>
          <h3>Privacy First</h3>
          <p>
            Your data never touches the cloud by default. Agent Hive runs on your infrastructure,
            with LAN-first architecture ensuring safe defaults even on fresh installs.
          </p>
          <ul>
            <li>RFC1918 private network security</li>
            <li>Configurable IP allowlists</li>
            <li>API key scoping & rate limiting</li>
            <li>No external dependencies required</li>
          </ul>
        </div>

        <div class="card">
          <div class="card-icon">
            <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
          </div>
          <h3>Operational Intelligence</h3>
          <p>
            Captures and enriches the data that matters: bash history, search queries,
            AI-generated summaries, and operational notes—all with time-aware search.
          </p>
          <ul>
            <li>Command history with context</li>
            <li>Search query rankings & results</li>
            <li>AI enrichment & metadata</li>
            <li>Human operational logs</li>
          </ul>
        </div>

        <div class="card">
          <div class="card-icon">
            <svg viewBox="0 0 24 24"><path d="M20 6h-2.18c.11-.31.18-.65.18-1 0-1.66-1.34-3-3-3-1.05 0-1.96.54-2.5 1.35l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-5-2c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zM9 4c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm11 15H4v-2h16v2zm0-5H4V8h5.08L7 10.83 8.62 12 11 8.76l1-1.36 1 1.36L15.38 12 17 10.83 14.92 8H20v6z"/></svg>
          </div>
          <h3>Stable API Contract</h3>
          <p>
            Built on a solid <code>/v1/*</code> API foundation. No breaking changes, no vendor lock-in.
            Use it with any client, any language, any platform.
          </p>
          <ul>
            <li>JSON API endpoints</li>
            <li>Bearer token or API key auth</li>
            <li>Comprehensive health endpoints</li>
            <li>Rate limiting & scope control</li>
          </ul>
        </div>

        <div class="card">
          <div class="card-icon">
            <svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 9H9V9h10v2zm-4 4H9v-2h6v2zm4-8H9V5h10v2z"/></svg>
          </div>
          <h3>SQLite Everywhere</h3>
          <p>
            Self-bootstrapping database architecture with zero configuration. No database servers
            to manage, no migrations to run manually.
          </p>
          <ul>
            <li>Automatic schema creation</li>
            <li>File-based, portable data</li>
            <li>No external DB dependencies</li>
            <li>Backup-friendly architecture</li>
          </ul>
        </div>

        <div class="card">
          <div class="card-icon">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
          </div>
          <h3>Operationally Boring</h3>
          <p>
            Production-ready defaults. Idempotent operations. Cron-safe. Debuggable.
            The infrastructure should fade into the background.
          </p>
          <ul>
            <li>Deterministic file paths</li>
            <li>Comprehensive error logging</li>
            <li>Health monitoring built-in</li>
            <li>Non-bricking fresh installs</li>
          </ul>
        </div>

        <div class="card">
          <div class="card-icon">
            <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
          </div>
          <h3>Time-Aware Search</h3>
          <p>
            Not just keyword matching—Agent Hive understands temporal patterns and
            operational context to surface the right information at the right time.
          </p>
          <ul>
            <li>Historical query tracking</li>
            <li>Ranking snapshot storage</li>
            <li>Contextual AI enrichment</li>
            <li>Pattern recognition over time</li>
          </ul>
        </div>

        <div class="card">
          <div class="card-icon">
            <svg viewBox="0 0 24 24"><path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>
          </div>
          <h3>CodeWalker Analysis</h3>
          <p>
            Scan entire project directories and generate intelligent summaries or code rewrites.
            Perfect for understanding legacy code or updating entire codebases at once.
          </p>
          <ul>
            <li>Multi-language support (PHP, Python, Shell)</li>
            <li>Automated code documentation</li>
            <li>Quick "what's this file?" analysis</li>
            <li>Bulk codebase modernization</li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <!-- Stats Section -->
  <section class="section" style="background: rgba(28, 34, 43, 0.2);">
    <div class="wrap">
      <div class="section-header">
        <h2>Built for Real Teams</h2>
      </div>
      <div class="stats">
        <div class="stat">
          <span class="stat-value">0%</span>
          <span class="stat-label">Cloud Dependency</span>
        </div>
        <div class="stat">
          <span class="stat-value">100%</span>
          <span class="stat-label">Data Ownership</span>
        </div>
        <div class="stat">
          <span class="stat-value">&lt;5min</span>
          <span class="stat-label">Setup Time</span>
        </div>
        <div class="stat">
          <span class="stat-value">24/7</span>
          <span class="stat-label">LAN Availability</span>
        </div>
      </div>
    </div>
  </section>

  <!-- Security Section -->
  <section class="section" id="security">
    <div class="wrap">
      <div class="section-header">
        <h2>Security By Design</h2>
        <p class="section-subtitle">
          Multiple layers of protection ensure your data stays private and your network stays secure.
        </p>
      </div>

      <div class="grid">
        <div class="card">
          <h3>LAN-First Security Mode</h3>
          <p>
            Default configuration allows keyless access only from RFC1918 private networks and loopback.
            Safe even on fresh installs with zero configuration.
          </p>
          <div class="pillrow">
            <span class="pill">192.168.0.0/16</span>
            <span class="pill">10.0.0.0/8</span>
            <span class="pill">172.16.0.0/12</span>
            <span class="pill">127.0.0.1</span>
          </div>
        </div>

        <div class="card">
          <h3>API Key Scoping</h3>
          <p>
            Granular permission control with scope-based access. Each key can be limited to specific
            capabilities: chat, tools, health monitoring, or custom scopes.
          </p>
        </div>

        <div class="card">
          <h3>Rate Limiting</h3>
          <p>
            Built-in sliding-window rate limiter protects against abuse. Per-IP and per-key limits
            with file-based storage for minimal overhead.
          </p>
        </div>

        <div class="card">
          <h3>Bootstrap Admin Auth</h3>
          <p>
            Fresh installs generate a one-time bootstrap token—no lockouts, no complicated setup.
            Claim admin access from LAN, then normal session auth applies.
          </p>
        </div>
      </div>
    </div>
  </section>

  <!-- API Documentation -->
  <section class="section" id="api" style="background: rgba(28, 34, 43, 0.2);">
    <div class="wrap">
      <div class="section-header">
        <h2>Simple, Powerful API</h2>
        <p class="section-subtitle">
          Start using Agent Hive in minutes with the JSON API and admin tools.
        </p>
      </div>

      <div class="card">
        <h3>Authentication</h3>
        <p>Include your API key in the request header:</p>
        <pre><code># Using X-API-Key header
curl -s https://api.iernc.net/v1/health \
  -H "X-API-Key: YOUR_KEY_HERE"

# Or using Bearer token
curl -s https://api.iernc.net/v1/health \
  -H "Authorization: Bearer YOUR_KEY_HERE"</code></pre>
      </div>

      <div class="grid" style="margin-top: 32px;">
        <div class="card">
          <h3>Core Endpoints</h3>
          <ul>
            <li><code>/v1/health</code> - System health & status</li>
            <li><code>/v1/ping</code> - Connection test</li>
            <li><code>/v1/notes/</code> - Notes application</li>
            <li><code>/v1/search</code> - Query search</li>
            <li><code>/v1/chat</code> - AI chat interface</li>
          </ul>
        </div>

        <div class="card">
          <h3>Response Format</h3>
          <p>All API responses return JSON:</p>
          <pre><code>{
  "status": "ok",
  "data": {...},
  "timestamp": "2026-01-02T..."
}</code></pre>
        </div>
      </div>

      <div class="hero-cta" style="margin-top: 40px;">
        <a href="/v1/health" class="btn primary">Test API Now</a>
        <a href="https://github.com/ier1990/agent-hive/tree/main/admin/AI" class="btn">Full Documentation</a>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="section" id="contact">
    <div class="wrap">
      <div class="section-header" style="max-width: 700px;">
        <h2>Ready to Keep Your AI Private?</h2>
        <p class="section-subtitle">
          Join forward-thinking teams who refuse to compromise between intelligence and privacy.
        </p>
      </div>

      <div class="hero-cta">
        <a href="https://www.iernc.com/contact" class="btn primary" style="font-size: 18px; padding: 16px 32px;">Request API Access</a>
        <a href="https://github.com/ier1990/agent-hive" class="btn">View on GitHub</a>
      </div>

      <div class="pillrow" style="justify-content: center; margin-top: 32px;">
        <span class="pill">No credit card required</span>
        <span class="pill">Self-hosted</span>
        <span class="pill">Apache 2.0 License</span>
        <span class="pill">Production ready</span>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="footer">
    <div class="wrap">
      <div class="footer-content">
        <div>
          <strong>Agent Hive</strong> by Industrial Electronic Repair<br/>
          ai-node-nyc3 
        </div>
        <div class="footer-links">
          <a href="https://github.com/ier1990/graphmert" rel="noopener">GitHub</a>
          <a href="/v1/health">API Health</a>          
          <a href="https://www.iernc.com/contact/">Contact</a>
        </div>
        <div class="status">
          <span class="status-dot"></span>
          All Systems Operational
        </div>
      </div>
      <div style="margin-top: 20px; text-align: center; opacity: 0.7;">
        © 2026 Industrial Electronic Repair · api.iernc.net
      </div>
    </div>
  </footer>

</body>
</html>
