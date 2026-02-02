<?php
// /web/html/index.php
// Friendly landing page. Keep it safe and non-indexed.
// IMPORTANT: Return 200 here; enforce auth on /v1/* endpoints instead.
require_once __DIR__ . '/lib/bootstrap.php';

http_response_code(200);

$year = date('Y');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>AI API Wars · Tell the Truth · api.iernc.net</title>
  <meta name="robots" content="noindex,nofollow" />
  <style>
    :root {
      --bg: #0b0d10;
      --ink: #e6e6e6;
      --mute: #9aa3ad;
      --edge: #1c222b;
      --accent: #7c5cff;
      --accent-2: #11c3ff;
      --good: #45d483;
      --bad: #ff5e66;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
      margin: 0;
      font-family: ui-sans-serif, system-ui, Segoe UI, Roboto, Helvetica, Arial, Apple Color Emoji, Segoe UI Emoji;
      background: radial-gradient(1200px 800px at 20% -10%, #12151b 0%, var(--bg) 60%) fixed;
      color: var(--ink);
      display: grid; place-items: center;
    }
    .wrap { width: min(1100px, 92vw); padding: 48px 20px; }
    header { display: flex; align-items: center; gap: 16px; margin-bottom: 18px; }
    .logo { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 12px; background: linear-gradient(135deg, var(--accent), var(--accent-2)); box-shadow: 0 6px 28px rgba(17,195,255,0.25); }
    .logo svg { width: 24px; height: 24px; fill: white; }
    h1 { margin: 0; font-size: clamp(28px, 3.5vw, 44px); font-weight: 800; letter-spacing: 0.2px; }

    .glitch { position: relative; display: inline-block; }
    .glitch::before, .glitch::after {
      content: attr(data-text);
      position: absolute; left: 0; top: 0; width: 100%; overflow: hidden; opacity: 0.8;
    }
    .glitch::before { transform: translate(2px, 0); color: var(--accent); mix-blend-mode: screen; animation: flick 2.4s infinite steps(1); }
    .glitch::after  { transform: translate(-2px, 0); color: var(--accent-2); mix-blend-mode: screen; animation: flick 3s infinite steps(1); }
    @keyframes flick { 0% { clip-path: inset(0 0 86% 0);} 20% { clip-path: inset(0 0 0 0);} 40% { clip-path: inset(72% 0 0 0);} 60% { clip-path: inset(0 0 40% 0);} 80% { clip-path: inset(54% 0 0 0);} 100% { clip-path: inset(0 0 0 0);} }

    .tagline { color: var(--mute); margin-top: 2px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; margin-top: 28px; }
    .card { background: linear-gradient(180deg, #10141b, #0b0d10); border: 1px solid var(--edge); border-radius: 16px; padding: 18px; box-shadow: 0 10px 30px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.02); }
    .card h3 { margin: 2px 0 10px; font-size: 18px; }
    .card p { margin: 0; color: var(--mute); line-height: 1.45; }

    .cta-row { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 18px; }
    .btn {
      appearance: none; border: 1px solid var(--edge); background: #12161e; color: var(--ink);
      padding: 12px 16px; border-radius: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 10px;
      transition: transform .06s ease, background .2s ease, border-color .2s ease;
      cursor: pointer;
    }
    .btn:hover { transform: translateY(-1px); border-color: #263041; }
    .btn.primary { background: linear-gradient(135deg, var(--accent), var(--accent-2)); color: #0b0d10; border: none; box-shadow: 0 8px 24px rgba(124,92,255,.25), 0 12px 30px rgba(17,195,255,.25); }
    .btn.kbd { font-weight: 600; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: #0f1319; }

    pre { margin: 0; background: #0b0f15; border: 1px solid var(--edge); border-radius: 12px; padding: 14px; overflow: auto; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; }

    .footer { margin-top: 26px; color: var(--mute); font-size: 13px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .status { font-weight: 700; }
    .ok { color: var(--good); }
    .bad { color: var(--bad); }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div class="logo" aria-hidden="true">
        <svg viewBox="0 0 24 24" role="img" aria-label="AI">
          <path d="M12 2l2.9 5.9L21 9l-4.5 4.4L17.8 21 12 17.8 6.2 21l1.3-7.6L3 9l6.1-1.1L12 2z"/>
        </svg>
      </div>
      <div>
        <h1><span id="title" class="glitch" data-text="AI API Wars">AI API Wars</span></h1>
        <div class="tagline">Tell the Truth • Secure by Default • Keys Required</div>
      </div>
    </header>

    <div class="grid">
      <section class="card">
        <h3>Welcome</h3>
        <p>This is a private API surface for Industrial Electronic Repair tooling. Public info is limited. Write ops and detailed inventories require a valid API key.</p>
        <div class="cta-row">
          <a class="btn primary" href="mailto:sales@electronics-recycling.org?subject=API%20Key%20Request&body=Hi%2C%20please%20provision%20an%20API%20key%20for%20api.iernc.net%20%28intended%20use%3A%20%5Byour%20use%5D%29.">Request API Key</a>
          <a class="btn" href="/v1/health" rel="noopener">Status & Health</a>
          <a class="btn" href="/v1/ping" rel="noopener">Ping</a>
          <a class="btn" href="/notes.php?view=human" rel="noopener">Notes</a>

          <button class="btn kbd" id="cycle" type="button">Cycle Title (A/B/C)</button>
        </div>
      </section>

      <section class="card">
        <h3>Use Your Key</h3>
        <p>Include your key as <code>X-API-Key</code> or a <code>Bearer</code> token:</p>
        <pre><code># cURL example
curl -s https://api.iernc.net/v1/health.php \
  -H "X-API-Key: YOUR_KEY_HERE"

# or
curl -s https://api.iernc.net/v1/health.php \
  -H "Authorization: Bearer YOUR_KEY_HERE"</code></pre>
      </section>

      <section class="card">
        <h3>Truth In, Truth Out</h3>
        <p>Health is open; sensitive routes require keys. If something looks missing, it’s probably being intentionally quiet unless you’re authorized.</p>
      </section>
    </div>

   

    <div class="footer">
         <div><?=htmlspecialchars(gethostname());?> · <?=htmlspecialchars($_SERVER['SERVER_ADDR'] ?? ''); ?></div>
      <div>© <?=$year;?> Industrial Electronic Repair · api.iernc.net</div>
      <div class="status">HTTP <span class="ok">200</span></div>
    </div>
  </div>

  <script>
    (function(){
      const titles = [
        { text: 'AI API Wars', tag: 'Tell the Truth' },
        { text: 'API AI Wars', tag: 'Truth In · Truth Out' },
        { text: 'AI Wars',     tag: 'Secure by Default' }
      ];
      let i = 0;
      const el = document.getElementById('title');
      const tag = document.querySelector('.tagline');
      function setTitle(ix){
        const t = titles[ix];
        el.textContent = t.text;
        el.setAttribute('data-text', t.text);
        tag.textContent = t.tag + ' • Keys Required';
        try { localStorage.setItem('api_title_ix', ix); } catch(e){}
      }
      try { i = parseInt(localStorage.getItem('api_title_ix')||'0',10); } catch(e){ i=0; }
      setTitle(isNaN(i)?0:i%titles.length);

      document.getElementById('cycle').addEventListener('click', function(){
        i = (i + 1) % titles.length;
        setTitle(i);
      });
    })();
  </script>
</body>
</html>
