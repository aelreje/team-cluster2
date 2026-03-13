<?php

require_once __DIR__ . '/../../config/auth.php';
requireRole('super admin');

function currentUserId(): int
{
    return (int)($_SESSION['user']['id'] ?? 0);
}