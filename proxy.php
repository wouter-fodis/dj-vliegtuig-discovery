<?php
// Proxy voor DJ Vliegtuig Discovery: haalt data op van een paar vaste bronnen,
// met een korte cache zodat we minder vaak tegen limieten aanlopen
// en bij een hapering de vorige data teruggeven.
$allowed = [
    'api.adsb.lol'               => 1,      // cache in seconden
    'api.airplanes.live'         => 1,
    'api.adsbdb.com'             => 86400,  // routes veranderen niet
    'hexdb.io'                   => 86400,  // reserve voor routes en luchthavens
    'commons.wikimedia.org'      => 86400,
    'query.wikidata.org'         => 86400,  // leiders, democratie-index, landenfeiten
    'nominatim.openstreetmap.org'=> 86400,  // plaatsnaam bij je GPS-locatie
    'raw.githubusercontent.com'  => 86400,  // Big Mac-index (CSV van The Economist)
    'api.frankfurter.dev'        => 3600,   // wisselkoersen (dagelijks bijgewerkt)
    'api.worldbank.org'          => 86400,  // inwoners, bbp, levensverwachting
    'rss.marketingtools.apple.com' => 3600, // hitlijsten per land (Apple Music)
    'musicbrainz.org'            => 86400,  // artiesten per land en genre, Spotify-links
    'itunes.apple.com'           => 86400,  // fragmenten van 30 seconden
    'overpass-api.de'            => 86400,  // plaatsnamen op de radar (OpenStreetMap)
];

header('Cache-Control: no-store');

$url = $_GET['url'] ?? '';
$p = parse_url($url);
$host = $p['host'] ?? '';
if (!$p || ($p['scheme'] ?? '') !== 'https' || !isset($allowed[$host])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'URL niet toegestaan']);
    exit;
}
// GitHub alleen voor de Big Mac-data
if ($host === 'raw.githubusercontent.com' && strpos($p['path'] ?? '', '/TheEconomist/big-mac-data/') !== 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'URL niet toegestaan']);
    exit;
}

$isCsv = ($host === 'raw.githubusercontent.com');
header($isCsv ? 'Content-Type: text/csv; charset=utf-8' : 'Content-Type: application/json; charset=utf-8');

$cacheFile = sys_get_temp_dir() . '/discproxy_' . md5($url) . '.cache';
$age = is_file($cacheFile) ? time() - filemtime($cacheFile) : PHP_INT_MAX;

// Verse cache? Direct teruggeven.
if ($age < $allowed[$host]) {
    readfile($cacheFile);
    exit;
}

// MusicBrainz staat maximaal 1 verzoek per seconde toe (per server). Wachten tot het mag.
if ($host === 'musicbrainz.org') {
    $lock = fopen(sys_get_temp_dir() . '/discproxy_mb.lock', 'c+');
    if ($lock && flock($lock, LOCK_EX)) {
        $last = (float)stream_get_contents($lock);
        $wait = $last + 1.1 - microtime(true);
        if ($wait > 0) usleep((int)min(5e6, $wait * 1e6));
        ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)microtime(true)); fflush($lock);
        flock($lock, LOCK_UN);
    }
    if ($lock) fclose($lock);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 2,
    CURLOPT_USERAGENT      => 'DJVliegtuigDiscovery/0.1 (https://fodis.nl/dj-vliegtuig-discovery/)', // o.a. adsb.lol en Nominatim eisen een User-Agent
    CURLOPT_HTTPHEADER     => [$isCsv ? 'Accept: text/csv,text/plain' : 'Accept: application/json'],
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

$valid = $isCsv
    ? (strlen($body) > 200 && stripos($body, '<html') === false)
    : (json_decode($body) !== null);
$ok = $body !== false && $code >= 200 && $code < 300 && $valid;

if ($ok) {
    @file_put_contents($cacheFile, $body, LOCK_EX);
    echo $body;
    exit;
}

// Mislukt: geef recente oude data terug als die er is (max 60 s oud voor vluchtdata).
$staleLimit = $allowed[$host] > 60 ? PHP_INT_MAX : 60;
if ($age < $staleLimit) {
    header('X-Proxy-Stale: 1');
    readfile($cacheFile);
    exit;
}

http_response_code(502);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => $err ?: "Bron gaf HTTP $code"]);
