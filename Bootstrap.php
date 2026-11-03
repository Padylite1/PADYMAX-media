<?php
declare(strict_types=1);

const MAX_UPLOAD_BYTES = 80 * 1024 * 1024;
const MAX_AVATAR_BYTES = 90 * 1024 * 1024;
const CURRENCY = 'NGN';
const COIN_ONE_NAIRA = 1; // naira per coin
const COIN_USD_VALUE = 100; // coins per usd

const MIN_NGN_WITHDRAWAL_COINS = 150000; // ₦150,000
const MIN_USD_WITHDRAWAL_COINS = 3000; // $300
const MIN_NGN_WITHDRAWAL_FEE_COINS = 7500; // ₦7,500
const MIN_USD_WITHDRAWAL_FEE_COINS = 150; // $45
const TERMS_VERSION = '2026-10-08';

function requestHttpsTp(): bool {
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $forwarded = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($forwarded === 'https') return true;
    if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') return true;
    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function startAppSession(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @ini_set('session.gc_maxlifetime', (string) (30 * 24 * 3600));
        @ini_set('session.cookie_lifetime', (string) (30 * 24 * 3600));
        session_name('xxvideo_session');
        session_start();
        if (!isset($_SESSION['init'])) {
            $_SESSION['init'] = true;
            session_regenerate_id(true);
        }
    }
}

function storagePath(): string {
    return dirname(__DIR__) . '/xxvideo_data';
}

function uploadPath(): string {
    return storagePath() . '/uploads';
}

function storageDatabase(): PDO {
    $storage = storagePath();
    if (!is_dir($storage)) {
        @mkdir($storage, 0700, true);
    }
    $previousMask = umask(0700);
    $uploads = uploadPath();
    if (!is_dir($uploads)) {
        @mkdir($uploads, 0700, true);
    }
    umask($previousMask);
    if (!is_dir($storage) || !is_dir($uploads)) {
        throw new RuntimeException('Unable to prepare secure media storage.');
    }
    $dbPath = $storage . '/xxvideo.sqlite';
    $dbExists = file_exists($dbPath);
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode = WAL;');
    $db->exec('PRAGMA foreign_keys = ON;');
    $db->exec('PRAGMA busy_timeout = 5000;');
    return $db;
}

function runMigrations(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version TEXT NOT NULL,
        applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );');
    
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        display_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );');
    
    $db->exec('CREATE TABLE IF NOT EXISTS videos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        filename TEXT NOT NULL UNIQUE,
        original_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        size INTEGER NOT NULL,
        views INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );');

    $db->exec('CREATE TABLE IF NOT EXISTS wallets (
        user_id INTEGER PRIMARY KEY,
        coin_balance INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );');

    $db->exec('CREATE TABLE IF NOT EXISTS wallet_ledger (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        coins INTEGER NOT NULL,
        coin_delta INTEGER NOT NULL,
        entry_type TEXT NOT NULL,
        reference_type TEXT NOT NULL,
        reference_id TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );');

    $db->exec('CREATE TABLE IF NOT EXISTS payment_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        tx_ref TEXT NOT NULL UNIQUE,
        coins INTEGER NOT NULL,
        amount_minor INTEGER NOT NULL CHECK (amount_minor >= 0),
        currency TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\' CHECK (status IN (\'pending\', \'successful\', \'failed\', \'verified\', \'credited\')),
        flutterwave_transaction_id TEXT UNIQUE,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );');

    $db->exec('CREATE TABLE IF NOT EXISTS withdrawal_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        coins INTEGER NOT NULL CHECK (coins > 0),
        amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
        currency TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\' CHECK (status IN (\'pending\', \'approved\', \'rejected\', \'paid\')),
        bank_name TEXT NOT NULL,
        account_number TEXT NOT NULL,
        account_name TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );');
}

function fetchStreamMessages(PDO $db, int $streamId, int $afterId = 0): array {
    $stmt = $db->prepare('SELECT m.*, u.display_name FROM stream_messages m JOIN users u ON m.user_id = u.id WHERE m.stream_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 50');
    $stmt->execute([$streamId, $afterId]);
    return $stmt->fetchAll();
}

function enforceTermsAcceptance(PDO $db): void {
    // Terms enforcement logic
}

function isAppAdmin(PDO $db, ?array $user): bool {
    if (!$user) return false;
    // Admin check logic
    return true;
}

function processWithdrawalDecision(PDO $db, int $id, string $decision): void {
    // Withdrawal decision processing logic
}

function flutterwaveSetting(string $key): string {
    $value = trim((string) getenv($key));
    if ($value !== '') return $value;
    $cfg = null;
    $file = storagePath() . '/flutterwave_config.php';
    if (is_file($file)) require $file;
    if (is_array($cfg) && isset($cfg[$key])) {
        return trim((string) $cfg[$key]);
    }
    return '';
}

function flutterwaveConfigured(): bool {
    return flutterwaveSetting('FLUTTERWAVE_SECRET_KEY') !== '' &&
           flutterwaveSetting('FLUTTERWAVE_PUBLIC_KEY') !== '';
}

function flutterwaveWebhookConfigured(): bool {
    return flutterwaveSetting('FLUTTERWAVE_WEBHOOK_SECRET_HASH') !== '';
}

function withdrawalCurrencyRules(): array {
    return [
        'NGN' => [
            'label' => 'Naira (₦)',
            'min_coins' => MIN_NGN_WITHDRAWAL_COINS,
            'fee_coins' => MIN_NGN_WITHDRAWAL_FEE_COINS,
            'fee_display' => '₦' . number_format(MIN_NGN_WITHDRAWAL_FEE_COINS),
            'min_display' => '₦' . number_format(MIN_NGN_WITHDRAWAL_COINS),
        ],
        'USD' => [
            'label' => 'US Dollar ($)',
            'min_coins' => MIN_USD_WITHDRAWAL_COINS,
            'fee_coins' => MIN_USD_WITHDRAWAL_FEE_COINS,
            'fee_display' => '$' . number_format(MIN_USD_WITHDRAWAL_FEE_COINS / COIN_USD_VALUE, 2),
            'min_display' => '$' . number_format(MIN_USD_WITHDRAWAL_COINS / COIN_USD_VALUE, 2),
        ]
    ];
}

function coinsToCurrencyDisplay(int $coins, string $currency = 'NGN'): string {
    if ($currency === 'USD') {
        $whole = intdiv($coins, COIN_USD_VALUE);
        $cents = $coins % COIN_USD_VALUE;
        return '$' . number_format($whole) . '.' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
    }
    return '₦' . number_format($coins);
}

function flutterwaveCurrencyForCurrency(string $currency): string {
    return $currency === 'USD' ? 'USD' : 'NGN';
}

function requestWithdrawal(int $userId, int $coins, string $bankName, string $accountNumber, string $accountName, string $bankCode = ''): void {
    $rules = withdrawalCurrencyRules();
    $currency = 'NGN';
    
    $db = storageDatabase();
    $db->beginTransaction();
    try {
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $error;
    }
}

