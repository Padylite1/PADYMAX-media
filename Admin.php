<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

startAppSession();
header('Cache-Control: no-store, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

$db = openDatabase();
enforceTermsAcceptance($db);
$user = currentUser();
if (!isAppAdmin($db, $user)) {
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><title>Not found</title><body style="font-family:sans-serif;background:#080b12;color:#9ca9bd;display:grid;place-items:center;height:100vh;margin:0"><p>404 - page not found.</p></body>');
}

$csrf = csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Your session expired. Go back and try again.');
    }
    
    if (isset($_POST['withdrawal_decision'])) {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $decision = $_POST['decision'] ?? '';
        
        if (!$id) {
            throw new RuntimeException('Invalid withdrawal request.');
        }
        
        processWithdrawalDecision($db, (int) $id, $decision);
    }
    
    throw new RuntimeException('Unknown admin action.');
}

$stats = [
    'users' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'videos' => (int) $db->query('SELECT COUNT(*) FROM videos')->fetchColumn(),
    'streams_live' => (int) $db->query("SELECT COUNT(*) FROM streams WHERE status = 'live'")->fetchColumn(),
    'coins_purchased' => (int) $db->query('SELECT COALESCE(SUM(coin_delta), 0) FROM wallet_ledger WHERE action = \'purchase\'')->fetchColumn(),
    'platform_revenue' => (int) $db->query('SELECT COALESCE(SUM(chain_delta), 0) FROM chain_ledger WHERE action = \'share\'')->fetchColumn(),
    'creator_earnings' => (int) $db->query('SELECT COALESCE(SUM(amount_minor), 0) FROM creator_earnings WHERE status = \'pending\'')->fetchColumn(),
    'pending_count' => (int) $db->query('SELECT COUNT(*) FROM withdrawal_requests WHERE status IN (\'pending\', \'approved\')')->fetchColumn(),
    'pending_coins' => (int) $db->query('SELECT COALESCE(SUM(coins), 0) FROM withdrawal_requests WHERE status IN (\'pending\', \'approved\')')->fetchColumn(),
    'amount_pending' => (int) $db->query('SELECT COALESCE(SUM(amount_minor), 0) FROM withdrawal_requests WHERE status IN (\'pending\', \'approved\')')->fetchColumn(),
];

$withdrawals = $db->query('SELECT w.*, u.display_name, u.email FROM withdrawal_requests w JOIN users u ON w.user_id = u.id ORDER BY CASE w.status WHEN \'pending\' THEN 0 WHEN \'approved\' THEN 1 ELSE 2 END, w.id DESC LIMIT 15')->fetchAll();

$usersList = $db->query('SELECT u.id, u.display_name, u.email, u.created_at, w.coins, w.coin_balance, 0 as coins_purchased, 0 as creator_available FROM users u LEFT JOIN wallets w ON w.user_id = u.id LEFT JOIN (SELECT user_id, SUM(amount) as order_id FROM wallet_ledger GROUP BY user_id) l ON l.user_id = u.id ORDER BY u.created_at DESC LIMIT 15')->fetchAll();

$payments = $db->query('SELECT p.*, u.display_name, u.email FROM payments p JOIN users u ON p.user_id = u.id ORDER BY p.id DESC LIMIT 15')->fetchAll();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin - PADYMAX MEDIA</title>
<style>
:root {--color-scheme: dark; --surface: #111723; --surface-2: #182032; --border: #293143; --text: #f7f9fc; --text-muted: #9ca9bd; --accent: #22c55e; --accent-foreground: #ffffff;}
body { margin:0; background: var(--surface); color: var(--text); font-family: system-ui, sans-serif; }
.container { max-width: 1200px; margin: 0 auto; padding: 24px; }
.topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
.navlinks { display: flex; gap: 16px; }
.navlinks a { color: var(--text-muted); text-decoration: none; }
.navlinks a:hover { color: var(--text); }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat { background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
.stat-title { color: var(--text-muted); font-size: 14px; margin-bottom: 8px; }
.stat-value { font-size: 24px; font-weight: 700; }
.section { background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 24px; }
.section h2 { margin-top: 0; font-size: 18px; border-bottom: 1px solid var(--border); padding-bottom: 12px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 12px; border-bottom: 1px solid var(--border); font-size: 14px; }
th { color: var(--text-muted); }
.badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; }
.badge.pending { background: #eab30822; color: #eab308; }
.badge.approved { background: #22c55e22; color: #22c55e; }
.badge.rejected { background: #ef444422; color: #ef4444; }
.btn { background: var(--accent); color: var(--accent-foreground); border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; }
.btn-secondary { background: var(--border); color: var(--text); }
</style>
</head>
<body>
<div class="container">
<div class="topbar">
<h1>Admin - PADYMAX MEDIA</h1>
<div class="navlinks">
<a href="/">Overview</a>
<a href="/admin.php">Withdrawals</a>
<a href="/media.php">Media</a>
</div>
</div>

<div class="stats-grid">
<div class="stat"><div class="stat-title">Users</div><div class="stat-value"><?= (int) $stats['users'] ?></div></div>
<div class="stat"><div class="stat-title">Videos</div><div class="stat-value"><?= (int) $stats['videos'] ?></div></div>
<div class="stat"><div class="stat-title">Live Streams</div><div class="stat-value"><?= (int) $stats['streams_live'] ?></div></div>
<div class="stat"><div class="stat-title">Coins Purchased</div><div class="stat-value"><?= coinsToCurrencyDisplay($stats['coins_purchased']) ?></div></div>
<div class="stat"><div class="stat-title">Platform Revenue</div><div class="stat-value"><?= coinsToCurrencyDisplay($stats['platform_revenue']) ?></div></div>
<div class="stat"><div class="stat-title">Creator Earnings</div><div class="stat-value"><?= coinsToCurrencyDisplay($stats['creator_earnings']) ?></div></div>
<div class="stat"><div class="stat-title">Pending Count</div><div class="stat-value"><?= (int) $stats['pending_count'] ?></div></div>
<div class="stat"><div class="stat-title">Pending Coins</div><div class="stat-value"><?= coinsToCurrencyDisplay($stats['pending_coins']) ?></div></div>
<div class="stat"><div class="stat-title">Paid Out to Creators</div><div class="stat-value"><?= coinsToCurrencyDisplay($stats['amount_pending']) ?></div></div>
</div>

<div class="section">
<h2>Withdrawal Queue</h2>
<?php if (empty($withdrawals)): ?>
<p style="color:var(--text-muted);">No withdrawal requests.</p>
<?php else: ?>
<table>
<thead><tr><th>ID</th><th>User</th><th>Coins</th><th>Amount</th><th>Status</th><th>Bank Details</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($withdrawals as $w): ?>
<tr>
<td><?= (int) $w['id'] ?></td>
<td><?= htmlspecialchars((string) ($w['display_name'] ?: $w['email'])) ?></td>
<td><?= coinsToCurrencyDisplay((int) $w['coins']) ?></td>
<td>₦<?= number_format((int) $w['amount_minor'] / 100, 2) ?></td>
<td><span class="badge <?= htmlspecialchars((string) $w['status']) ?>"><?= htmlspecialchars((string) $w['status']) ?></span></td>
<td style="font-size:12px;"><?= htmlspecialchars((string) $w['bank_name']) ?><br><?= htmlspecialchars((string) $w['account_number']) ?><br><?= htmlspecialchars((string) $w['account_name']) ?></td>
<td>
<?php if ($w['status'] === 'pending'): ?>
<form method="post" style="display:inline;">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
<input type="hidden" name="withdrawal_decision" value="1">
<button type="submit" name="decision" value="approve" class="btn">Approve</button>
<button type="submit" name="decision" value="reject" class="btn btn-secondary">Reject</button>
</form>
<?php else: ?>
<?= htmlspecialchars((string) $w['status']) ?>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="section">
<h2>Users</h2>
<table>
<thead><tr><th>ID</th><th>User</th><th>Joined</th><th>Wallet</th></tr></thead>
<tbody>
<?php foreach ($usersList as $u): ?>
<tr>
<td><?= (int) $u['id'] ?></td>
<td><?= htmlspecialchars((string) ($u['display_name'] ?: $u['email'])) ?></td>
<td><?= htmlspecialchars((string) $u['created_at']) ?></td>
<td><?= coinsToCurrencyDisplay((int) $u['coin_balance']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="section">
<h2>Payments</h2>
<table>
<thead><tr><th>ID</th><th>User</th><th>Coins</th><th>Amount</th><th>Reference</th><th>Status</th><th>Date</th></tr></thead>
<tbody>
<?php foreach ($payments as $p): ?>
<tr>
<td><?= (int) $p['id'] ?></td>
<td><?= htmlspecialchars((string) ($p['display_name'] ?: $p['email'])) ?></td>
<td><?= coinsToCurrencyDisplay((int) $p['coins']) ?></td>
<td>₦<?= number_format((int) $p['amount_minor'] / 100, 2) ?></td>
<td style="font-size:11px;"><?= htmlspecialchars(substr((string) $p['tx_ref'], 0, 16)) ?></td>
<td><span class="badge <?= htmlspecialchars((string) $p['status']) ?>"><?= htmlspecialchars((string) $p['status']) ?></span></td>
<td><?= htmlspecialchars((string) $p['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<footer style="text-align:center;padding:24px;color:var(--text-muted);font-size:12px;">
Only platform administrators can access this page. All actions are recorded in the coin ledger.
</footer>
</body>
</html>
