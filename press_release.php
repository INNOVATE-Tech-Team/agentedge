<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { header('Location: index.php'); exit; }

$agentName  = htmlspecialchars($agent['name']  ?? '');
$agentEmail = htmlspecialchars($agent['email'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Press Release Studio — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .wrap { max-width: none; padding: 20px 28px 40px; }

    .pr-tabs { display: flex; gap: 8px; margin-bottom: 20px; }
    .pr-tab  { padding: 7px 18px; border-radius: 6px; border: 1px solid #ddd; background: #fff; font-size: 12px; font-weight: 700; cursor: pointer; color: #888; }
    .pr-tab.active { background: #82C112; color: #000; border-color: #82C112; }

    /* ── Builder split ── */
    .pr-split {
      display: grid;
      grid-template-columns: 390px 1fr;
      border: 1px solid #e4e4e4;
      border-radius: 10px;
      overflow: hidden;
      height: calc(100vh - 195px);
      min-height: 520px;
    }
    .pr-form {
      background: #fff;
      border-right: 1px solid #e4e4e4;
      overflow-y: auto;
      padding: 20px 22px 36px;
    }
    .pr-preview-pane {
      background: #F8F7F5;
      overflow-y: auto;
      padding: 36px 52px;
    }

    /* ── Form ── */
    .pr-sec { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .17em; color: #82C112; padding-bottom: 8px; border-bottom: 1px solid #e6f5cc; margin: 18px 0 14px; }
    .pr-sec:first-child { margin-top: 0; }
    .pr-f { margin-bottom: 11px; }
    .pr-f label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #aaa; margin-bottom: 4px; }
    .pr-f input, .pr-f textarea {
      width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px;
      font-size: 13px; box-sizing: border-box; font-family: inherit; color: #222;
    }
    .pr-f input:focus, .pr-f textarea:focus { outline: 2px solid #82C112; border-color: #82C112; }
    .pr-f textarea { min-height: 68px; resize: vertical; line-height: 1.5; }
    .pr-g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .fact-row { display: flex; align-items: center; gap: 8px; margin-bottom: 7px; }
    .fact-bullet { color: #82C112; font-size: 15px; flex-shrink: 0; line-height: 1; }
    .pr-btns { display: flex; gap: 8px; margin-top: 8px; }
    .btn-pr-primary { flex: 1; padding: 10px; border-radius: 6px; border: none; cursor: pointer; background: #82C112; color: #000; font-size: 12px; font-weight: 800; }
    .btn-pr-primary:hover { background: #5b8e0d; color: #fff; }
    .btn-pr-sec { padding: 10px 14px; border-radius: 6px; cursor: pointer; background: #f4f4f4; color: #555; border: 1px solid #ddd; font-size: 12px; font-weight: 700; }
    .btn-pr-sec:hover { background: #e8e8e8; }

    /* ── PR Document Preview ── */
    .pr-doc { max-width: 600px; margin: 0 auto; font-family: Georgia, serif; color: #111; }
    .pr-doc-top { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2.5px solid #111; padding-bottom: 16px; margin-bottom: 20px; }
    .pr-org-name { font-family: -apple-system, sans-serif; font-weight: 800; font-size: 16px; letter-spacing: -.02em; }
    .pr-org-sub  { font-family: monospace; font-size: 8px; letter-spacing: .15em; text-transform: uppercase; color: #aaa; margin-top: 2px; }
    .pr-flag     { text-align: right; }
    .pr-imm      { font-family: monospace; font-size: 9px; letter-spacing: .13em; text-transform: uppercase; color: #82C112; font-weight: 700; }
    .pr-dstr     { font-size: 11.5px; color: #999; margin-top: 4px; }
    .pr-cb  { background: #EFEDE9; border-left: 3px solid #82C112; padding: 10px 14px; margin-bottom: 22px; font-family: -apple-system, sans-serif; }
    .pr-cb-lbl  { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; color: #aaa; margin-bottom: 5px; }
    .pr-cb-name { font-size: 12px; font-weight: 700; color: #111; }
    .pr-cb-info { font-family: monospace; font-size: 11px; color: #777; margin-top: 2px; }
    .pr-hl  { font-size: 26px; font-weight: 700; line-height: 1.25; margin: 0 0 10px; }
    .pr-sl  { font-size: 15px; font-style: italic; color: #555; margin: 0 0 20px; line-height: 1.45; }
    .pr-dl  { font-family: -apple-system, sans-serif; font-size: 12px; font-weight: 700; margin-bottom: 12px; }
    .pr-body p { font-size: 14.5px; line-height: 1.78; color: #1a1a1a; margin: 0 0 14px; }
    .pr-ph  { color: #ccc; font-style: italic; }
    .pr-ul  { list-style: none; padding: 0; margin: 14px 0; }
    .pr-ul li { font-size: 14.5px; line-height: 1.65; color: #1a1a1a; padding: 7px 0 7px 20px; border-bottom: 1px solid #E0DDD8; position: relative; }
    .pr-ul li:first-child { border-top: 1px solid #E0DDD8; }
    .pr-ul li::before { content: '▸'; color: #82C112; position: absolute; left: 0; top: 9px; font-size: 11px; }
    .pr-qb  { border-left: 4px solid #82C112; padding: 12px 20px; background: #F2F0EC; margin: 22px 0; }
    .pr-qt  { font-size: 16px; font-style: italic; line-height: 1.65; margin: 0 0 10px; }
    .pr-qa  { font-family: -apple-system, sans-serif; font-size: 11px; font-weight: 700; color: #777; text-transform: uppercase; letter-spacing: .05em; }
    .pr-bp  { margin-top: 28px; padding-top: 16px; border-top: 1px solid #ccc; }
    .pr-bpl { font-family: monospace; font-size: 8px; letter-spacing: .13em; text-transform: uppercase; color: #ccc; margin-bottom: 7px; }
    .pr-bp p { font-family: -apple-system, sans-serif; font-size: 11.5px; line-height: 1.68; color: #999; }
    .pr-end { text-align: center; margin-top: 26px; font-family: monospace; font-size: 13px; color: #ccc; letter-spacing: .1em; }

    /* ── Contacts tab ── */
    .ct-note { font-size: 13px; color: #777; margin-bottom: 22px; line-height: 1.65; max-width: 600px; }
    .ct-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .ct-cat  { background: #fff; border: 1px solid #e8e8e8; border-radius: 8px; padding: 18px; }
    .ct-ch   { font-size: 9px; font-weight: 800; color: #82C112; text-transform: uppercase; letter-spacing: .17em; padding-bottom: 10px; border-bottom: 1px solid #f2f2f2; margin-bottom: 12px; }
    .ct-e    { padding-bottom: 11px; margin-bottom: 11px; border-bottom: 1px solid #f5f5f5; }
    .ct-e:last-child { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
    .ct-out  { font-size: 13px; font-weight: 700; color: #222; margin-bottom: 2px; }
    .ct-beat { font-size: 11px; color: #aaa; margin-bottom: 4px; }
    .ct-how  { font-family: monospace; font-size: 10.5px; color: #5b8e0d; }
    .ct-note2{ font-size: 10px; color: #bbb; font-style: italic; margin-top: 3px; line-height: 1.5; }

    @media (max-width: 960px) {
      .pr-split { grid-template-columns: 1fr; }
      .pr-preview-pane { display: none; }
      .ct-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('press_release', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Press Release Studio</div>
    </header>
    <main class="wrap">

      <div class="pr-tabs">
        <button class="pr-tab active" onclick="prShowTab('builder',this)">Builder</button>
        <button class="pr-tab"        onclick="prShowTab('contacts',this)">Who to Pitch</button>
      </div>

      <!-- ══ BUILDER ══ -->
      <div id="pr-builder">
        <div class="pr-split">

          <!-- Form -->
          <div class="pr-form">

            <div class="pr-sec">Release Details</div>
            <div class="pr-g2">
              <div class="pr-f"><label>Date</label><input type="date" id="prDate"></div>
              <div class="pr-f"><label>City, State</label><input type="text" id="prCity" value="Myrtle Beach, SC"></div>
            </div>
            <div class="pr-f"><label>Headline</label><input type="text" id="prHl" placeholder="INNOVATE Expands Into Raleigh Market"></div>
            <div class="pr-f">
              <label>Subheadline <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#ccc">(optional)</span></label>
              <input type="text" id="prSl" placeholder="Brokerage now serves 15 states with 300+ agents">
            </div>

            <div class="pr-sec">Body</div>
            <div class="pr-f"><label>Opening Paragraph</label><textarea id="prOpen" placeholder="What happened, where, and why it matters."></textarea></div>
            <div class="pr-f">
              <label>Key Facts <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#ccc">(up to 4)</span></label>
              <div class="fact-row"><span class="fact-bullet">▸</span><input type="text" class="pr-fact" placeholder="300+ agents across 15 Market Centers"></div>
              <div class="fact-row"><span class="fact-bullet">▸</span><input type="text" class="pr-fact" placeholder="Operating in 14 states"></div>
              <div class="fact-row"><span class="fact-bullet">▸</span><input type="text" class="pr-fact" placeholder=""></div>
              <div class="fact-row"><span class="fact-bullet">▸</span><input type="text" class="pr-fact" placeholder=""></div>
            </div>
            <div class="pr-f"><label>Additional Context</label><textarea id="prBody" placeholder="Background, market context, what this means for agents and clients…"></textarea></div>

            <div class="pr-sec">Quote</div>
            <div class="pr-f"><label>Quote Text</label><textarea id="prQt" placeholder="We're building something agents have never seen before…" style="min-height:80px"></textarea></div>
            <div class="pr-g2">
              <div class="pr-f"><label>Name</label><input type="text" id="prQn" value="Darren Woodard"></div>
              <div class="pr-f"><label>Title</label><input type="text" id="prQtl" value="CEO, INNOVATE"></div>
            </div>
            <div class="pr-f"><label>Closing / Call to Action</label><textarea id="prClose" placeholder="Agents interested in INNOVATE can visit growwithinnovate.com…"></textarea></div>

            <div class="pr-sec">Media Contact</div>
            <div class="pr-f"><label>Contact Name</label><input type="text" id="prCn" value="<?= $agentName ?>"></div>
            <div class="pr-g2">
              <div class="pr-f"><label>Email</label><input type="email" id="prCe" value="<?= $agentEmail ?>"></div>
              <div class="pr-f"><label>Phone</label><input type="tel" id="prCp" placeholder="(843) 000-0000"></div>
            </div>

            <div class="pr-btns">
              <button class="btn-pr-primary" id="prCopyBtn" onclick="prCopy()">Copy Release</button>
              <button class="btn-pr-sec" onclick="prDownload()">Download .txt</button>
            </div>
          </div><!-- /form -->

          <!-- Live preview -->
          <div class="pr-preview-pane">
            <div class="pr-doc" id="prPreview"></div>
          </div>

        </div><!-- /split -->
      </div><!-- /builder -->

      <!-- ══ CONTACTS ══ -->
      <div id="pr-contacts" style="display:none">
        <p class="ct-note">Reporters, editors, and wire services most likely to cover INNOVATE news — organized by beat. Lead with the outlet that fits your story type; don't blast all of them at once.</p>
        <div class="ct-grid">

          <div class="ct-cat">
            <div class="ct-ch">National Real Estate Trade</div>
            <div class="ct-e">
              <div class="ct-out">Inman News</div>
              <div class="ct-beat">Brokerage growth, agent recruitment, industry trends</div>
              <div class="ct-how">tips@inman.com</div>
              <div class="ct-note2">Highest reach for brokerage expansion. Lead with agent count + growth data.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">RISMedia</div>
              <div class="ct-beat">Real estate leadership, independent brokerages</div>
              <div class="ct-how">press@rismedia.com</div>
              <div class="ct-note2">Receptive to regional brokerages with compelling agent value props.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">HousingWire</div>
              <div class="ct-beat">Brokerage tech, market data</div>
              <div class="ct-how">editorial@housingwire.com</div>
              <div class="ct-note2">Best for tech announcements and market data stories.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">RealTrends</div>
              <div class="ct-beat">Brokerage rankings, agent productivity</div>
              <div class="ct-how">info@realtrends.com</div>
              <div class="ct-note2">Submit when crossing volume milestones or for rankings consideration.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">The Real Deal</div>
              <div class="ct-beat">Market moves, notable hires, brokerage competition</div>
              <div class="ct-how">tips@therealdeal.com</div>
              <div class="ct-note2">Skews large markets — use for major expansions or exec hires.</div>
            </div>
          </div>

          <div class="ct-cat">
            <div class="ct-ch">Regional Business Press</div>
            <div class="ct-e">
              <div class="ct-out">Myrtle Beach Sun News</div>
              <div class="ct-beat">Local business, real estate, coastal SC economy</div>
              <div class="ct-how">business@myrtlebeachsun.com</div>
              <div class="ct-note2">Home market — pitch everything. Quote local agent counts and SC data.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">The State (Columbia, SC)</div>
              <div class="ct-beat">SC business, economy, commercial real estate</div>
              <div class="ct-how">newsdesk@thestate.com</div>
              <div class="ct-note2">Strong for statewide growth stories and Columbia/Upstate expansion.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">Post and Courier (Charleston)</div>
              <div class="ct-beat">Charleston real estate, SC business</div>
              <div class="ct-how">business@postandcourier.com</div>
              <div class="ct-note2">One of SC's most credible papers — any SC-wide news belongs here.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">Charlotte Observer</div>
              <div class="ct-beat">Carolinas business, real estate, expansion</div>
              <div class="ct-how">business@charlotteobserver.com</div>
              <div class="ct-note2">Covers NC + greater Carolinas. Best for NC market expansion stories.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">Business North Carolina</div>
              <div class="ct-beat">NC business, growth companies</div>
              <div class="ct-how">editor@businessnc.com</div>
              <div class="ct-note2">Monthly magazine — submit 6–8 weeks ahead for print consideration.</div>
            </div>
          </div>

          <div class="ct-cat">
            <div class="ct-ch">Wire Services &amp; Industry</div>
            <div class="ct-e">
              <div class="ct-out">PR Newswire</div>
              <div class="ct-beat">Broad distribution to hundreds of outlets</div>
              <div class="ct-how">prnewswire.com (submit online)</div>
              <div class="ct-note2">~$350–700/release. Best ROI for milestones. Ask for SE regional circuit.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">Business Wire</div>
              <div class="ct-beat">Financial and trade press distribution</div>
              <div class="ct-how">businesswire.com (submit online)</div>
              <div class="ct-note2">Preferred by financial journalists; use for investor-audience stories.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">EIN Presswire</div>
              <div class="ct-beat">Broad online distribution, affordable</div>
              <div class="ct-how">einpresswire.com (submit online)</div>
              <div class="ct-note2">Free tier available. Good for SEO pickup on routine announcements.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">Broker Agent Magazine</div>
              <div class="ct-beat">Independent brokerage, agent recruiting</div>
              <div class="ct-how">editorial@brokeragentmagazine.com</div>
              <div class="ct-note2">Directly reaches agents evaluating brokerage moves — prime for recruiting news.</div>
            </div>
            <div class="ct-e">
              <div class="ct-out">NAR Newsroom / Realtor Magazine</div>
              <div class="ct-beat">NAR member news, market data</div>
              <div class="ct-how">newsroom@nar.realtor</div>
              <div class="ct-note2">Use for MLS data, compliance, and agent advocacy stories.</div>
            </div>
          </div>

        </div><!-- /ct-grid -->
      </div><!-- /contacts -->

    </main>
  </div>
</div>

<script>
const BOILERPLATE = 'INNOVATE is a full-service real estate brokerage operating across 14 states with more than 300 agents and 15 Market Centers. Built on the belief that agents deserve better technology, deeper support, and a genuine path to financial independence, INNOVATE combines a proprietary CRM and lead platform with hands-on mentorship and a competitive compensation model. Headquartered in Myrtle Beach, SC, INNOVATE serves buyers and sellers from the Carolinas to Texas. Learn more at growwithinnovate.com.';

const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

function fmtDate(d) {
  if (!d) return '';
  const p = d.split('-');
  return MONTHS[parseInt(p[1])-1] + ' ' + parseInt(p[2]) + ', ' + p[0];
}

function g(id) { return (document.getElementById(id)||{}).value || ''; }

function buildPlainText() {
  const facts = [...document.querySelectorAll('.pr-fact')].map(i=>i.value.trim()).filter(Boolean);
  const lines = [];
  lines.push('FOR IMMEDIATE RELEASE');
  lines.push(fmtDate(g('prDate')) || fmtDate(new Date().toISOString().slice(0,10)));
  lines.push('');
  const cn=g('prCn'), ce=g('prCe'), cp=g('prCp');
  if (cn||ce||cp) {
    lines.push('MEDIA CONTACT');
    if (cn) lines.push(cn); if (ce) lines.push(ce); if (cp) lines.push(cp);
    lines.push('');
  }
  lines.push((g('prHl')||'YOUR HEADLINE HERE').toUpperCase());
  if (g('prSl')) lines.push(g('prSl'));
  lines.push('');
  lines.push((g('prCity')||'Myrtle Beach, SC').toUpperCase() + ' —');
  if (g('prOpen')) lines.push(g('prOpen'));
  lines.push('');
  if (facts.length) { facts.forEach(f=>lines.push('  * '+f)); lines.push(''); }
  if (g('prQt')) {
    lines.push('"' + g('prQt') + '"');
    const attr=[g('prQn'),g('prQtl')].filter(Boolean).join(', ');
    if (attr) lines.push('-- '+attr);
    lines.push('');
  }
  if (g('prBody'))  { lines.push(g('prBody'));  lines.push(''); }
  if (g('prClose')) { lines.push(g('prClose')); lines.push(''); }
  lines.push('ABOUT INNOVATE');
  lines.push(BOILERPLATE);
  lines.push(''); lines.push('###');
  return lines.join('\n');
}

function renderPreview() {
  const hl   = g('prHl');
  const sl   = g('prSl');
  const city = (g('prCity')||'Myrtle Beach, SC').toUpperCase();
  const date = fmtDate(g('prDate')) || fmtDate(new Date().toISOString().slice(0,10));
  const open = g('prOpen');
  const body = g('prBody');
  const close= g('prClose');
  const qt   = g('prQt');
  const qn   = g('prQn');
  const qtl  = g('prQtl');
  const cn   = g('prCn');
  const ce   = g('prCe');
  const cp   = g('prCp');
  const facts = [...document.querySelectorAll('.pr-fact')].map(i=>i.value.trim()).filter(Boolean);

  const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  let html = `
    <div class="pr-doc-top">
      <div>
        <div class="pr-org-name">INNOVATE</div>
        <div class="pr-org-sub">Real Estate Brokerage</div>
      </div>
      <div class="pr-flag">
        <div class="pr-imm">For Immediate Release</div>
        <div class="pr-dstr">${esc(date)}</div>
      </div>
    </div>`;

  if (cn||ce||cp) {
    html += `<div class="pr-cb"><div class="pr-cb-lbl">Media Contact</div>`;
    if (cn) html += `<div class="pr-cb-name">${esc(cn)}</div>`;
    const info = [ce,cp].filter(Boolean).map(esc).join('  ·  ');
    if (info) html += `<div class="pr-cb-info">${info}</div>`;
    html += `</div>`;
  }

  html += `<h1 class="pr-hl">${hl ? esc(hl) : '<span class="pr-ph">Your headline will appear here…</span>'}</h1>`;
  if (sl) html += `<p class="pr-sl">${esc(sl)}</p>`;
  html += `<p class="pr-dl">${esc(city)} —</p>`;

  html += '<div class="pr-body">';
  if (open) {
    html += `<p>${esc(open)}</p>`;
  } else {
    html += `<p class="pr-ph">Opening paragraph will appear here…</p>`;
  }

  if (facts.length) {
    html += '<ul class="pr-ul">';
    facts.forEach(f => html += `<li>${esc(f)}</li>`);
    html += '</ul>';
  }

  if (qt) {
    const attr = [qn,qtl].filter(Boolean).map(esc).join(', ');
    html += `<div class="pr-qb"><p class="pr-qt">&ldquo;${esc(qt)}&rdquo;</p>${attr ? `<div class="pr-qa">${attr}</div>` : ''}</div>`;
  }

  if (body)  html += `<p>${esc(body)}</p>`;
  if (close) html += `<p>${esc(close)}</p>`;
  html += '</div>';

  html += `<div class="pr-bp"><div class="pr-bpl">About INNOVATE</div><p>${esc(BOILERPLATE)}</p></div>`;
  html += `<div class="pr-end">###</div>`;

  document.getElementById('prPreview').innerHTML = html;
}

function prCopy() {
  navigator.clipboard.writeText(buildPlainText()).then(() => {
    const btn = document.getElementById('prCopyBtn');
    btn.textContent = '✓ Copied!';
    setTimeout(() => btn.textContent = 'Copy Release', 2200);
  }).catch(() => {});
}

function prDownload() {
  const slug = (g('prHl')||'press-release').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,50);
  const blob = new Blob([buildPlainText()], {type:'text/plain'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'INNOVATE-' + slug + '.txt';
  a.click();
}

function prShowTab(tab, btn) {
  document.getElementById('pr-builder').style.display  = tab === 'builder'  ? '' : 'none';
  document.getElementById('pr-contacts').style.display = tab === 'contacts' ? '' : 'none';
  document.querySelectorAll('.pr-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

// Set today's date and wire live preview
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('prDate').value = new Date().toISOString().slice(0,10);
  document.querySelectorAll('#pr-builder input, #pr-builder textarea').forEach(el => {
    el.addEventListener('input', renderPreview);
  });
  renderPreview();
});
</script>
</body>
</html>
