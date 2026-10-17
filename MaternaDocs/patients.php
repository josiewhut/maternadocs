<?php
// Patient list + name correction (admin & front desk). Full record form comes later.
require 'includes/auth.php';
$u = require_role(['admin', 'front_desk']);
$msg = ''; $err = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  [$fn, $mn, $ln] = [trim($_POST['first_name'] ?? ''), trim($_POST['middle_name'] ?? ''), trim($_POST['last_name'] ?? '')];
  if (!csrf_ok($_POST['csrf'] ?? null)) $err[] = 'Session expired. Please try again.';
  elseif (!valid_name($fn) || !valid_name($ln) || ($mn !== '' && !valid_name($mn))) $err[] = 'Enter a valid first name and surname (middle name is optional).';
  else {
    $pdo = db(); $pdo->beginTransaction();
    $pdo->prepare('UPDATE patients SET first_name=?, middle_name=?, last_name=? WHERE id=?')->execute([$fn, $mn ?: null, $ln, $id]);
    // keep the patient's portal account name in sync
    $pdo->prepare('UPDATE users SET first_name=?, middle_name=?, last_name=? WHERE id = (SELECT user_id FROM patients WHERE id=?)')->execute([$fn, $mn ?: null, $ln, $id]);
    $pdo->commit();
    log_action($u['id'], 'patient_name_changed', "patient #$id");
    $_SESSION['flash'] = 'Patient name updated.'; header('Location: patients.php'); exit;
  }
  $_GET['edit'] = $id;
}
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

$q = trim($_GET['q'] ?? ''); $like = '%' . addcslashes($q, '%_\\') . '%';
$st = db()->prepare("SELECT p.id, p.first_name, p.middle_name, p.last_name, p.birthdate, u.username FROM patients p
  LEFT JOIN users u ON u.id = p.user_id WHERE (? = '' OR p.first_name LIKE ? OR p.last_name LIKE ?) ORDER BY p.last_name, p.first_name LIMIT 100");
$st->execute([$q, $like, $like]); $rows = $st->fetchAll();
$edit = null;
if (!empty($_GET['edit'])) foreach ($rows as $r) if ((int)$r['id'] === (int)$_GET['edit']) $edit = $r;
if (!$edit && !empty($_GET['edit'])) { $s2 = db()->prepare('SELECT id, first_name, middle_name, last_name FROM patients WHERE id=?'); $s2->execute([(int)$_GET['edit']]); $edit = $s2->fetch() ?: null; }

shell_start($u, 'Patient Records'); ?>
<?php if ($flash): ?><div class="alert alert-success py-2"><?= e($flash) ?></div><?php endif; ?>
<?php foreach ($err as $x): ?><div class="alert alert-danger py-2"><?= e($x) ?></div><?php endforeach; ?>

<?php if ($edit): ?>
<div class="card-soft mb-4" style="height:auto"><h5>Edit patient name</h5>
<form method="post" class="row g-2 align-items-end">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <div class="col-md-3"><label class="form-label small" for="a">First name</label><input id="a" name="first_name" class="form-control" required value="<?= e($_POST['first_name'] ?? $edit['first_name']) ?>"></div>
  <div class="col-md-3"><label class="form-label small" for="b">Middle name <span class="text-secondary">(optional)</span></label><input id="b" name="middle_name" class="form-control" value="<?= e($_POST['middle_name'] ?? (string)$edit['middle_name']) ?>"></div>
  <div class="col-md-3"><label class="form-label small" for="c">Surname</label><input id="c" name="last_name" class="form-control" required value="<?= e($_POST['last_name'] ?? $edit['last_name']) ?>"></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary">Save</button><a href="patients.php" class="btn btn-outline-primary">Cancel</a></div>
</form></div>
<?php endif; ?>

<form class="d-flex gap-2 mb-3" method="get" style="max-width:420px">
  <input name="q" class="form-control" placeholder="Search by first name or surname" value="<?= e($q) ?>"><button class="btn btn-primary">Search</button></form>
<div class="card-soft" style="height:auto"><div class="table-responsive"><table class="table align-middle mb-0">
<thead><tr><th>Surname</th><th>First name</th><th>Middle name</th><th>Birthdate</th><th>Portal username</th><th class="text-end"></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
<tr><td><?= e($r['last_name']) ?></td><td><?= e($r['first_name']) ?></td><td><?= e((string)$r['middle_name']) ?></td><td><?= e($r['birthdate']) ?></td>
  <td class="text-secondary"><?= e((string)$r['username']) ?: '—' ?></td>
  <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$r['id'] ?>&q=<?= urlencode($q) ?>">Edit name</a></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-secondary">No patients found.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php shell_end();
