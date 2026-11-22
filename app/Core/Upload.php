<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Secure photo upload:
 *  - checks the PHP upload error code and is_uploaded_file()
 *  - enforces a size limit
 *  - detects the real MIME type from file contents (finfo), never trusting
 *    the browser-supplied type or extension
 *  - verifies the file is a decodable image
 *  - stores it outside the web root under a random name
 */
final class Upload
{
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public static function dir(string $kind): string
    {
        if (!in_array($kind, ['students', 'staff'], true)) {
            throw new HttpException('Invalid upload type.', 400);
        }
        $dir = STORAGE_PATH . '/uploads/' . $kind;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /** Returns the stored file name, or null when no file was submitted. */
    public static function photo(string $field, string $kind): ?string
    {
        $f = $_FILES[$field] ?? null;
        if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (is_array($f['error'])) {
            throw HttpException::validation([$field => 'Only one photo may be uploaded.']);
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $msg = in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'Photo is too large.' : 'Photo upload failed. Please try again.';
            throw HttpException::validation([$field => $msg]);
        }
        $max = (int) Config::get('uploads.max_photo_bytes', 2 * 1024 * 1024);
        if ($f['size'] <= 0 || $f['size'] > $max) {
            throw HttpException::validation([$field => 'Photo must be smaller than ' . round($max / 1048576, 1) . ' MB.']);
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            throw HttpException::validation([$field => 'Invalid upload.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(self::TYPES[$mime])) {
            throw HttpException::validation([$field => 'Photo must be a JPG, PNG or WEBP image.']);
        }
        $info = @getimagesize($f['tmp_name']);
        if ($info === false || $info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) {
            throw HttpException::validation([$field => 'Photo is not a valid image.']);
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        $dest = self::dir($kind) . '/' . $name;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            throw new HttpException('Could not save the uploaded photo. Check that storage/ is writable.', 500);
        }
        @chmod($dest, 0644);
        return $name;
    }

    public static function delete(string $kind, ?string $name): void
    {
        if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name)) {
            $path = self::dir($kind) . '/' . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Streams a stored photo after the caller has authorised the request. */
    public static function stream(string $kind, ?string $name): never
    {
        if (!$name || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name, $m)) {
            throw HttpException::notFound('No photo.');
        }
        $path = self::dir($kind) . '/' . $name;
        if (!is_file($path)) {
            throw HttpException::notFound('No photo.');
        }
        $mime = array_search($m[1], self::TYPES, true) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        readfile($path);
        exit;
    }
}
