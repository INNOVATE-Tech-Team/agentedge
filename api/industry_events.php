<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$CACHE_FILE = __DIR__ . '/../cache/industry_events.json';
$CACHE_TTL  = 21600; // 6 hours

function industry_rss_news(): array {
    $ctx  = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: Mozilla/5.0 AgentEdge/1.0\r\n"]]);
    $feed = @file_get_contents('https://www.inman.com/feed/', false, $ctx);
    if (!$feed) return [];
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($feed);
    libxml_clear_errors();
    if (!$xml || empty($xml->channel->item)) return [];

    $kws = ['connect', 'summit', 'conference', 'forum', 'expo', 'convention', 'register', 'event', 'symposium'];
    $out = [];
    foreach ($xml->channel->item as $it) {
        $title = trim((string)$it->title);
        $blob  = strtolower($title . ' ' . (string)$it->description);
        $hit   = false;
        foreach ($kws as $kw) { if (strpos($blob, $kw) !== false) { $hit = true; break; } }
        if (!$hit) continue;
        $ts = @strtotime((string)$it->pubDate);
        $out[] = [
            'title'   => $title,
            'url'     => trim((string)$it->link),
            'date'    => $ts ? date('Y-m-d', $ts) : date('Y-m-d'),
            'summary' => mb_substr(strip_tags((string)$it->description), 0, 200),
        ];
        if (count($out) >= 6) break;
    }
    return $out;
}

function industry_events_curated(): array {
    return [
        ['id' => 'imn-winter-2026', 'name' => 'IMN Winter Forum on Real Estate',
         'organizer' => 'IMN', 'category' => 'finance',
         'start_date' => '2026-01-21', 'end_date' => '2026-01-23',
         'location' => 'Laguna Beach, CA', 'url' => 'https://www.imn.org/real-estate/',
         'description' => 'Top institutional real estate investment forum connecting fund managers, lenders, and operators across the capital stack.',
         'featured' => false],

        ['id' => 'inman-connect-ny-2026', 'name' => 'Inman Connect New York',
         'organizer' => 'Inman', 'category' => 'inman',
         'start_date' => '2026-02-03', 'end_date' => '2026-02-05',
         'location' => 'New York, NY', 'url' => 'https://www.inman.com/events/',
         'description' => 'The premier real estate technology and innovation conference — top industry leaders, provocative sessions, and unmatched networking.',
         'featured' => true],

        ['id' => 'nahb-ibs-2026', 'name' => "NAHB International Builders' Show",
         'organizer' => 'NAHB', 'category' => 'industry',
         'start_date' => '2026-02-17', 'end_date' => '2026-02-19',
         'location' => 'Orlando, FL', 'url' => 'https://buildersshow.com',
         'description' => "The world's largest annual light construction show — essential for agents working new construction and home builders.",
         'featured' => false],

        ['id' => 'kw-family-reunion-2026', 'name' => 'KW Family Reunion',
         'organizer' => 'Keller Williams', 'category' => 'brokerage',
         'start_date' => '2026-02-21', 'end_date' => '2026-02-24',
         'location' => 'Las Vegas, NV', 'url' => 'https://kwri.kw.com',
         'description' => "Keller Williams' annual mega-event delivering training, culture, and proven business strategies for KW agents and leaders.",
         'featured' => false],

        ['id' => 't3-leadership-2026', 'name' => 'T3 Leadership Summit',
         'organizer' => 'T3 Sixty', 'category' => 'leadership',
         'start_date' => '2026-04-22', 'end_date' => '2026-04-24',
         'location' => 'Orlando, FL', 'url' => 'https://t3sixty.com',
         'description' => 'Invitation-only event for top real estate executives and brokerage leaders — focused on strategy, data, and industry M&A.',
         'featured' => false],

        ['id' => 'realcomm-ibcon-2026', 'name' => 'Realcomm | IBcon',
         'organizer' => 'Realcomm', 'category' => 'technology',
         'start_date' => '2026-06-03', 'end_date' => '2026-06-04',
         'location' => 'San Diego, CA', 'url' => 'https://realcomm.com',
         'description' => 'Commercial real estate technology summit focused on automation, AI, and connected smart buildings.',
         'featured' => false],

        ['id' => 'nar-legislative-2026', 'name' => 'REALTORS® Legislative Meetings',
         'organizer' => 'NAR', 'category' => 'nar',
         'start_date' => '2026-06-13', 'end_date' => '2026-06-18',
         'location' => 'Washington, D.C.', 'url' => 'https://legislative.nar.realtor',
         'description' => 'REALTORS® from across the country gather on Capitol Hill to advocate for homeownership and real estate policy.',
         'featured' => false],

        ['id' => 'naa-apartmentalize-2026', 'name' => 'NAA Apartmentalize',
         'organizer' => 'NAA', 'category' => 'industry',
         'start_date' => '2026-06-17', 'end_date' => '2026-06-19',
         'location' => 'New Orleans, LA', 'url' => 'https://www.naahq.org/apartmentalize',
         'description' => "The National Apartment Association's premier trade show for multifamily housing professionals.",
         'featured' => false],

        ['id' => 'inman-luxury-2026', 'name' => 'Inman Luxury Connect',
         'organizer' => 'Inman', 'category' => 'inman',
         'start_date' => '2026-07-27', 'end_date' => '2026-07-28',
         'location' => 'San Diego, CA', 'url' => 'https://www.inman.com/events/',
         'description' => 'The definitive luxury real estate conference for agents and brokers serving the high-net-worth property market.',
         'featured' => false],

        ['id' => 'inman-connect-sd-2026', 'name' => 'Inman Connect San Diego',
         'organizer' => 'Inman', 'category' => 'inman',
         'start_date' => '2026-07-28', 'end_date' => '2026-07-30',
         'location' => 'San Diego, CA', 'url' => 'https://www.inman.com/events/',
         'description' => "Inman's summer real estate technology summit — emerging trends, investor insights, and future-forward strategy sessions.",
         'featured' => true],

        ['id' => 'tom-ferry-summit-2026', 'name' => 'Tom Ferry Success Summit',
         'organizer' => 'Tom Ferry International', 'category' => 'training',
         'start_date' => '2026-08-03', 'end_date' => '2026-08-05',
         'location' => 'Anaheim, CA', 'url' => 'https://www.tomferry.com/summit/',
         'description' => "The real estate industry's leading coaching event with actionable strategies for top-producing agents and teams.",
         'featured' => true],

        ['id' => 'five-star-2026', 'name' => 'Five Star Conference & Expo',
         'organizer' => 'Five Star Global', 'category' => 'industry',
         'start_date' => '2026-09-01', 'end_date' => '2026-09-03',
         'location' => 'Dallas, TX', 'url' => 'https://www.fivestarconference.com',
         'description' => "The mortgage and default servicing industry's premier event connecting executives with housing market intelligence.",
         'featured' => false],

        ['id' => 'blueprint-lv-2026', 'name' => 'Blueprint Las Vegas',
         'organizer' => 'Blueprint', 'category' => 'technology',
         'start_date' => '2026-09-22', 'end_date' => '2026-09-24',
         'location' => 'Las Vegas, NV', 'url' => 'https://blueprintvegas.com',
         'description' => 'The leading proptech conference connecting real estate investors, operators, and technology innovators.',
         'featured' => false],

        ['id' => 't3-technology-2026', 'name' => 'T3 Technology Summit',
         'organizer' => 'T3 Sixty', 'category' => 'technology',
         'start_date' => '2026-09-29', 'end_date' => '2026-10-01',
         'location' => 'New Orleans, LA', 'url' => 'https://t3sixty.com',
         'description' => 'Where real estate technology meets executive leadership — tools and platforms shaping the future of brokerage.',
         'featured' => false],

        ['id' => 'nar-nxt-2026', 'name' => 'NAR NXT — The REALTOR® Experience',
         'organizer' => 'NAR', 'category' => 'nar',
         'start_date' => '2026-11-06', 'end_date' => '2026-11-08',
         'location' => 'New Orleans, LA', 'url' => 'https://narnxt.com',
         'description' => "NAR's flagship annual conference bringing together 20,000+ real estate professionals for education, networking, and advocacy.",
         'featured' => true],
    ];
}

// Custom events from SQLite — always fetched fresh so additions show immediately.
function industry_custom_events(string $today): array {
    try {
        $rows = local_db()->query(
            "SELECT * FROM custom_events ORDER BY start_date, id"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $end = $r['end_date'] ?: $r['start_date'];
        if ($end < $today) continue;
        $out[] = [
            'id'          => 'custom-' . $r['id'],
            'name'        => $r['name'],
            'organizer'   => $r['organizer'],
            'category'    => $r['category'],
            'start_date'  => $r['start_date'],
            'end_date'    => $r['end_date'] ?: null,
            'location'    => $r['location'],
            'url'         => $r['url'],
            'description' => $r['description'],
            'featured'    => (bool)$r['featured'],
            'source'      => 'custom',
        ];
    }
    return $out;
}

// Serve from cache if still fresh (curated + RSS only — custom events always merged live)
$cached_events = null;
$cached_news   = [];
$cached_ts     = null;
if (file_exists($CACHE_FILE)) {
    $c = @json_decode(@file_get_contents($CACHE_FILE), true);
    if (is_array($c) && (time() - ($c['ts'] ?? 0)) < $CACHE_TTL) {
        $cached_events = $c['events'];
        $cached_news   = $c['news'] ?? [];
        $cached_ts     = $c['ts'];
    }
}

$today = date('Y-m-d');

if ($cached_events === null) {
    // Build fresh curated + RSS payload and cache it
    $cached_events = array_values(array_filter(industry_events_curated(), function ($e) use ($today) {
        return ($e['end_date'] ?? $e['start_date']) >= $today;
    }));
    $cached_news = industry_rss_news();
    $cached_ts   = time();
    $out = ['ts' => $cached_ts, 'events' => $cached_events, 'news' => $cached_news];
    $dir = dirname($CACHE_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($CACHE_FILE, json_encode($out));
}

// Merge custom events live, then sort combined list by start_date
$custom  = industry_custom_events($today);
$all     = array_merge($cached_events, $custom);
usort($all, function ($a, $b) { return strcmp($a['start_date'], $b['start_date']); });

echo json_encode(['events' => $all, 'news' => $cached_news, 'cached_at' => date('c', $cached_ts)]);
