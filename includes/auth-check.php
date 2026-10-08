<?php
/**
 * Include this at the very top of every protected page:
 *
 *   require_once __DIR__ . '/../config/database.php';
 *   require_once __DIR__ . '/../includes/functions.php';
 *   require_once __DIR__ . '/../includes/auth-check.php';
 *   requireRole(['admin']); // or ['librarian'], ['student'], or [] for "any logged-in user"
 *
 * This file just makes sure a session exists; the actual role check
 * happens via requireRole() so each page controls which roles may view it.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
