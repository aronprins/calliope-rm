<?php
/**
 * Local-dev router for `php -S`.
 *
 * Routes /api/submit and /api/board to their PHP handlers, serves
 * index.html at /, and lets the built-in server handle /uploads/*
 * directly off disk. Not for production — nginx does this.
 *
 * Usage:
 *   php -S localhost:8000 -t . router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/api/submit') {
    require __DIR__ . '/submit.php';
    return true;
}
if ($path === '/api/board') {
    require __DIR__ . '/board.php';
    return true;
}
if ($path === '/api/comments') {
    require __DIR__ . '/comments.php';
    return true;
}
if ($path === '/api/status') {
    require __DIR__ . '/status.php';
    return true;
}
if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

// Let the built-in server serve everything else from disk
// (this includes /uploads/<date>/<token>.webp once UPLOADS_DIR points here)
return false;
