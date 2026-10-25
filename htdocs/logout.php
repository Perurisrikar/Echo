<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
start_secure_session();
if (!empty($_SESSION['student_id'])) { try { db()->prepare('DELETE FROM user_sessions WHERE sid_hash = ?')->execute([current_sid_hash()]); } catch (Throwable $e) {} }
$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;
