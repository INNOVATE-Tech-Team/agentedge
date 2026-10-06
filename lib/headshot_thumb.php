<?php
// Headshot thumbnail generation. Originals in data/headshots/ are never
// touched by any function here — thumbnails are separate files in
// data/headshots/thumbs/, always re-encoded as JPEG regardless of the
// source format, named after the original's file_key stem (extension
// dropped, since the thumbnail format is fixed).
if (defined('AGENTEDGE_HEADSHOT_THUMB_LOADED')) return;
define('AGENTEDGE_HEADSHOT_THUMB_LOADED', true);

const HEADSHOT_THUMB_MAX_DIM = 128;
const HEADSHOT_THUMB_QUALITY = 82;

function headshot_thumb_dir(): string {
    $cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dataDir = $cfgDir ?: (__DIR__ . '/../data');
    return $dataDir . '/headshots/thumbs';
}

// Same stem as the original file_key, extension dropped — thumbnails are
// always JPEG regardless of what the original was.
function headshot_thumb_filename(string $fileKey): string {
    return pathinfo($fileKey, PATHINFO_FILENAME) . '.jpg';
}

function headshot_thumb_path(string $fileKey): string {
    return headshot_thumb_dir() . '/' . headshot_thumb_filename($fileKey);
}

// Generates (or regenerates) the thumbnail for one headshot. Returns true
// on success, false on any failure — callers must treat false as "serve the
// original instead," never as an error to surface. Never modifies $sourcePath.
//
// Some real uploads here are full phone-camera originals up to ~6000x9000px.
// Decoding one of those into GD's in-memory truecolor bitmap can need
// several hundred MB — comfortably more than PHP's memory_limit (128M for
// both web and CLI on this box). That's not a catchable failure: "Allowed
// memory size exhausted" is a hard fatal that skips straight past any
// try/catch, which would take the whole request down (including the
// fallback-to-original logic) instead of failing safely. So this estimates
// decode cost from the dimensions alone via getimagesize() — which only
// reads the file header, never risky regardless of file size — and skips
// generation up front for anything that would risk it, before ever handing
// the file to a decoder. Those originals just keep being served as-is.
const HEADSHOT_THUMB_MAX_DECODE_BYTES = 48 * 1024 * 1024;

function generate_headshot_thumbnail(string $sourcePath, string $fileKey): bool {
    if (!extension_loaded('gd')) return false;
    if (!is_file($sourcePath) || !is_readable($sourcePath)) return false;

    try {
        $info = @getimagesize($sourcePath);
        if ($info === false) return false;
        [$srcW, $srcH] = $info;
        if ($srcW < 1 || $srcH < 1) return false;

        // Conservative: truecolor RGBA (4 bytes/px) plus ~2.5x for
        // decompression working buffers/libjpeg-libpng overhead.
        $estimatedDecodeBytes = $srcW * $srcH * 4 * 2.5;
        if ($estimatedDecodeBytes > HEADSHOT_THUMB_MAX_DECODE_BYTES) return false;

        $src = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($sourcePath); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($sourcePath);  break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($sourcePath); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($sourcePath);  break;
            default: return false; // unsupported source format — fall back to original
        }
        if (!$src) return false;

        $scale = min(1.0, HEADSHOT_THUMB_MAX_DIM / max($srcW, $srcH));
        $dstW  = max(1, (int)round($srcW * $scale));
        $dstH  = max(1, (int)round($srcH * $scale));

        $dst = imagecreatetruecolor($dstW, $dstH);
        // Flatten transparency onto white — thumbnails are always JPEG (no alpha).
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);

        $dir = headshot_thumb_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($dst);
            return false;
        }

        // Write to a temp file in the same directory, then rename — an
        // in-progress/failed write can never leave a half-written thumbnail
        // sitting at the real path for another request to pick up.
        $finalPath = headshot_thumb_path($fileKey);
        $tmpPath   = $finalPath . '.tmp' . getmypid();
        $ok = imagejpeg($dst, $tmpPath, HEADSHOT_THUMB_QUALITY);
        imagedestroy($dst);
        if (!$ok) { @unlink($tmpPath); return false; }

        if (!@rename($tmpPath, $finalPath)) { @unlink($tmpPath); return false; }
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

// Returns the thumbnail path, generating it first if missing/stale. Returns
// null (never throws) if a usable thumbnail couldn't be produced — callers
// must fall back to serving $sourcePath directly in that case.
function ensure_headshot_thumbnail(string $sourcePath, string $fileKey): ?string {
    $thumbPath = headshot_thumb_path($fileKey);
    if (is_file($thumbPath) && filemtime($thumbPath) >= filemtime($sourcePath)) {
        return $thumbPath;
    }
    if (generate_headshot_thumbnail($sourcePath, $fileKey)) {
        return $thumbPath;
    }
    return null;
}
