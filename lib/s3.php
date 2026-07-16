<?php
// Lightweight AWS S3 helper using Signature Version 4 — no SDK, no Composer.
// Reads credentials from cfg(): s3_region, s3_bucket, s3_key, s3_secret.
if (defined('AGENTEDGE_S3_LOADED')) return;
define('AGENTEDGE_S3_LOADED', true);

function _s3_cfg(): array {
    $c = cfg();
    foreach (['s3_region','s3_bucket','s3_key','s3_secret'] as $k) {
        if (empty($c[$k])) throw new \RuntimeException("S3 config missing: $k");
    }
    return $c;
}

// Upload a file already on disk (or from a PHP tmp path) to S3.
// Streams directly from disk — never loads the whole file into PHP memory.
function s3_put_file(string $local_path, string $s3_key, string $content_type): void {
    $c    = _s3_cfg();
    $size = filesize($local_path);
    $hash = hash_file('sha256', $local_path);
    $now  = gmdate('Ymd\THis\Z');
    $day  = substr($now, 0, 8);
    $host = "{$c['s3_bucket']}.s3.{$c['s3_region']}.amazonaws.com";
    $path = '/' . ltrim($s3_key, '/');

    $headers = [
        'content-length'       => $size,
        'content-type'         => $content_type,
        'host'                 => $host,
        'x-amz-content-sha256' => $hash,
        'x-amz-date'           => $now,
    ];
    ksort($headers);
    $signed_headers    = implode(';', array_keys($headers));
    $canonical_headers = '';
    foreach ($headers as $k => $v) $canonical_headers .= "$k:$v\n";

    $canonical      = "PUT\n$path\n\n$canonical_headers\n$signed_headers\n$hash";
    $scope          = "$day/{$c['s3_region']}/s3/aws4_request";
    $string_to_sign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);

    $signing_key = hash_hmac('sha256', 'aws4_request',
        hash_hmac('sha256', 's3',
            hash_hmac('sha256', $c['s3_region'],
                hash_hmac('sha256', $day, 'AWS4' . $c['s3_secret'], true), true), true), true);
    $sig = hash_hmac('sha256', $string_to_sign, $signing_key);

    $auth = "AWS4-HMAC-SHA256 Credential={$c['s3_key']}/$scope, "
          . "SignedHeaders=$signed_headers, Signature=$sig";

    $fh = fopen($local_path, 'rb');
    $ch = curl_init("https://$host$path");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_UPLOAD         => true,
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => $size,
        CURLOPT_HTTPHEADER     => [
            "Authorization: $auth",
            "x-amz-date: $now",
            "x-amz-content-sha256: $hash",
            "Content-Type: $content_type",
            "Content-Length: $size",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FAILONERROR    => false,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    if ($status < 200 || $status >= 300) {
        throw new \RuntimeException("S3 PUT failed ($status): $resp");
    }
}

// Delete an object from S3.
function s3_delete(string $s3_key): void {
    $c    = _s3_cfg();
    $hash = hash('sha256', '');
    $now  = gmdate('Ymd\THis\Z');
    $day  = substr($now, 0, 8);
    $host = "{$c['s3_bucket']}.s3.{$c['s3_region']}.amazonaws.com";
    $path = '/' . ltrim($s3_key, '/');

    $headers = ['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $now];
    ksort($headers);
    $signed_headers    = implode(';', array_keys($headers));
    $canonical_headers = '';
    foreach ($headers as $k => $v) $canonical_headers .= "$k:$v\n";

    $canonical      = "DELETE\n$path\n\n$canonical_headers\n$signed_headers\n$hash";
    $scope          = "$day/{$c['s3_region']}/s3/aws4_request";
    $string_to_sign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);

    $signing_key = hash_hmac('sha256', 'aws4_request',
        hash_hmac('sha256', 's3',
            hash_hmac('sha256', $c['s3_region'],
                hash_hmac('sha256', $day, 'AWS4' . $c['s3_secret'], true), true), true), true);
    $sig = hash_hmac('sha256', $string_to_sign, $signing_key);

    $auth = "AWS4-HMAC-SHA256 Credential={$c['s3_key']}/$scope, "
          . "SignedHeaders=$signed_headers, Signature=$sig";

    $ch = curl_init("https://$host$path");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => ["Authorization: $auth", "x-amz-date: $now", "x-amz-content-sha256: $hash"],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// Return a short-lived presigned PUT URL so a browser can upload directly to S3.
// Requires the S3 bucket to have CORS configured — see DEPLOY.md for the JSON.
function s3_presigned_put_url(string $s3_key, int $expires = 3600): string {
    $c    = _s3_cfg();
    $now  = gmdate('Ymd\THis\Z');
    $day  = substr($now, 0, 8);
    $host = "{$c['s3_bucket']}.s3.{$c['s3_region']}.amazonaws.com";
    $path = '/' . ltrim($s3_key, '/');
    $scope = "$day/{$c['s3_region']}/s3/aws4_request";

    $query = http_build_query([
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => "{$c['s3_key']}/$scope",
        'X-Amz-Date'          => $now,
        'X-Amz-Expires'       => $expires,
        'X-Amz-SignedHeaders' => 'host',
    ]);

    $canonical      = "PUT\n$path\n$query\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
    $string_to_sign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);

    $signing_key = hash_hmac('sha256', 'aws4_request',
        hash_hmac('sha256', 's3',
            hash_hmac('sha256', $c['s3_region'],
                hash_hmac('sha256', $day, 'AWS4' . $c['s3_secret'], true), true), true), true);
    $sig = hash_hmac('sha256', $string_to_sign, $signing_key);

    return "https://$host$path?$query&X-Amz-Signature=$sig";
}

// Return a short-lived presigned GET URL for downloading an S3 object.
function s3_presigned_url(string $s3_key, int $expires = 3600): string {
    $c    = _s3_cfg();
    $now  = gmdate('Ymd\THis\Z');
    $day  = substr($now, 0, 8);
    $host = "{$c['s3_bucket']}.s3.{$c['s3_region']}.amazonaws.com";
    $path = '/' . ltrim($s3_key, '/');
    $scope = "$day/{$c['s3_region']}/s3/aws4_request";

    $query = http_build_query([
        'X-Amz-Algorithm'  => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential' => "{$c['s3_key']}/$scope",
        'X-Amz-Date'       => $now,
        'X-Amz-Expires'    => $expires,
        'X-Amz-SignedHeaders' => 'host',
    ]);

    $canonical      = "GET\n$path\n$query\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
    $string_to_sign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);

    $signing_key = hash_hmac('sha256', 'aws4_request',
        hash_hmac('sha256', 's3',
            hash_hmac('sha256', $c['s3_region'],
                hash_hmac('sha256', $day, 'AWS4' . $c['s3_secret'], true), true), true), true);
    $sig = hash_hmac('sha256', $string_to_sign, $signing_key);

    return "https://$host$path?$query&X-Amz-Signature=$sig";
}

// Human-readable file size string.
function s3_fmt_size(int $bytes): string {
    if ($bytes < 1024)       return $bytes . ' B';
    if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

// Guess a mime type from filename extension for common doc/image types.
function s3_mime_from_name(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'zip'  => 'application/zip',
        'mp4'  => 'video/mp4',
        'mp3'  => 'audio/mpeg',
    ][$ext] ?? 'application/octet-stream';
}
