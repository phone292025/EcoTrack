<?php
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$pdo = getPDO();

/**
 * Guard against removing the last way into the admin area. Demoting or
 * deleting an admin is only allowed while another admin would remain.
 */
function otherAdminsExist(PDO $pdo, int $excludingUserId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = "admin" AND user_id <> ?');
    $stmt->execute([$excludingUserId]);

    return (int)$stmt->fetchColumn() > 0;
}

/** Password rules for admin-created accounts, same as self-registration. */
function passwordProblem(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must include at least one uppercase letter and one number.';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role'] ?? 'participant';
        $password = $_POST['password'] ?? '';

        if (strlen($username) < 3) {
            setFlash('error', 'Username must be at least 3 characters.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
        } elseif (!in_array($role, ['participant', 'moderator', 'admin'], true)) {
            setFlash('error', 'Please choose a valid role.');
        } elseif (($problem = passwordProblem($password)) !== null) {
            setFlash('error', $problem);
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO users (username, email, password, role)
                     VALUES (?, ?, ?, ?)'
                )->execute([
                    $username,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role,
                ]);
                setFlash('success', 'User created.');
                setFormOld([]);
                redirectToSelf($_SERVER['QUERY_STRING'] ?? '');
            } catch (PDOException $e) {
                if (isDuplicateKeyError($e)) {
                    setFlash('error', 'That username or email is already registered.');
                } else {
                    error_log('[EcoTrack users] ' . $e->getMessage());
                    setFlash('error', 'Could not create the user right now. Please try again.');
                }
            }
        }

        setFormOld(['username' => $username, 'email' => $email, 'role' => $role]);
    } elseif ($action === 'update') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'participant';
        $newPassword = $_POST['new_password'] ?? '';

        $existing = $pdo->prepare('SELECT role FROM users WHERE user_id = ?');
        $existing->execute([$userId]);
        $existingRole = (string)($existing->fetchColumn() ?: '');

        $passwordProblem = $newPassword !== '' ? passwordProblem($newPassword) : null;

        if ($userId <= 0 || $existingRole === '') {
            setFlash('error', 'Invalid user selected.');
        } elseif (strlen($username) < 3) {
            setFlash('error', 'Username must be at least 3 characters.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
        } elseif (!in_array($role, ['participant', 'moderator', 'admin'], true)) {
            setFlash('error', 'Please choose a valid role.');
        } elseif ($userId === currentUserId() && $role !== 'admin') {
            setFlash('error', 'You cannot change your own role. Ask another admin to do it.');
        } elseif ($existingRole === 'admin' && $role !== 'admin' && !otherAdminsExist($pdo, $userId)) {
            setFlash('error', 'This is the last admin account. Promote another admin before changing this one.');
        } elseif ($passwordProblem !== null) {
            setFlash('error', $passwordProblem);
        } else {
            try {
                if ($newPassword !== '') {
                    $pdo->prepare(
                        'UPDATE users
                         SET username = ?, email = ?, role = ?, password = ?
                         WHERE user_id = ?'
                    )->execute([$username, $email, $role, password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                } else {
                    $pdo->prepare(
                        'UPDATE users
                         SET username = ?, email = ?, role = ?
                         WHERE user_id = ?'
                    )->execute([$username, $email, $role, $userId]);
                }
                setFlash('success', 'User updated.');
            } catch (PDOException $e) {
                if (isDuplicateKeyError($e)) {
                    setFlash('error', 'That username or email is already taken.');
                } else {
                    error_log('[EcoTrack users] ' . $e->getMessage());
                    setFlash('error', 'Could not update the user right now. Please try again.');
                }
            }
        }
    } elseif ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);

        $existing = $pdo->prepare('SELECT role FROM users WHERE user_id = ?');
        $existing->execute([$userId]);
        $existingRole = (string)($existing->fetchColumn() ?: '');

        if ($userId <= 0 || $existingRole === '') {
            setFlash('error', 'Invalid user selected.');
        } elseif ($userId === currentUserId()) {
            setFlash('error', 'You cannot delete your own account.');
        } elseif ($existingRole === 'admin' && !otherAdminsExist($pdo, $userId)) {
            setFlash('error', 'This is the last admin account and cannot be deleted.');
        } else {
            $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);
            setFlash('success', 'User deleted.');
        }
    }

    // Redirect so a refresh cannot repeat a create or a delete.
    redirectToSelf($_SERVER['QUERY_STRING'] ?? '');
}

$flash = takeFlash();
$old = takeFormOld();
$userForm = [
    'username' => $old['username'] ?? '',
    'email'    => $old['email'] ?? '',
    'role'     => $old['role'] ?? 'participant',
];

$roleCounts = [
    'participant' => 0,
    'moderator' => 0,
    'admin' => 0,
];

$roleRows = $pdo->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role')->fetchAll() ?: [];
foreach ($roleRows as $row) {
    $roleKey = (string)($row['role'] ?? '');
    if (array_key_exists($roleKey, $roleCounts)) {
        $roleCounts[$roleKey] = (int)$row['total'];
    }
}

$totalUsers = array_sum($roleCounts);
$filterRole = $_GET['role'] ?? 'all';
if (!in_array($filterRole, ['all', 'participant', 'moderator', 'admin'], true)) {
    $filterRole = 'all';
}

$searchQuery = trim($_GET['q'] ?? '');
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;

$where = [];
$params = [];

if ($filterRole !== 'all') {
    $where[] = 'role = :role';
    $params['role'] = $filterRole;
}

if ($searchQuery !== '') {
    $where[] = '(username LIKE :search_username OR email LIKE :search_email)';
    $searchLike = '%' . $searchQuery . '%';
    $params['search_username'] = $searchLike;
    $params['search_email'] = $searchLike;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM users' . $whereSql);
$countStmt->execute($params);
$filteredUsers = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredUsers / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$userStmt = $pdo->prepare(
    'SELECT user_id, username, email, role, created_at
     FROM users' . $whereSql . '
     ORDER BY FIELD(role, "admin", "moderator", "participant"), username ASC, user_id ASC
     LIMIT :limit OFFSET :offset'
);

foreach ($params as $key => $param) {
    $userStmt->bindValue(':' . $key, $param);
}
$userStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$userStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$userStmt->execute();
$users = $userStmt->fetchAll() ?: [];

$resultsFrom = $filteredUsers > 0 ? $offset + 1 : 0;
$resultsTo = $filteredUsers > 0 ? min($offset + $perPage, $filteredUsers) : 0;

$buildUserManagementUrl = static function (array $overrides = []) use ($filterRole, $searchQuery, $currentPage): string {
    $params = [
        'role' => $filterRole,
        'q' => $searchQuery,
        'page' => $currentPage,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    if (($params['role'] ?? 'all') === 'all') {
        unset($params['role']);
    }

    if (($params['q'] ?? '') === '') {
        unset($params['q']);
    }

    if (($params['page'] ?? 1) <= 1) {
        unset($params['page']);
    }

    $query = http_build_query($params);
    return 'user_management.php' . ($query ? '?' . $query : '');
};

$formatCreated = static function (?string $value): string {
    if (!$value) {
        return 'Unknown date';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('M j, Y g:i A', $timestamp);
};

$roleMeta = [
    'admin' => ['label' => 'Admin', 'badge' => 'badge-grey'],
    'moderator' => ['label' => 'Moderator', 'badge' => 'badge-amber'],
    'participant' => ['label' => 'Participant', 'badge' => 'badge-blue'],
];

$roleFilters = [
    'all' => ['label' => 'All roles', 'count' => $totalUsers],
    'participant' => ['label' => 'Participants', 'count' => $roleCounts['participant']],
    'moderator' => ['label' => 'Moderators', 'count' => $roleCounts['moderator']],
    'admin' => ['label' => 'Admins', 'count' => $roleCounts['admin']],
];

$activeFilterLabel = $roleFilters[$filterRole]['label'] ?? 'All roles';

$pageTitle = 'Users';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell admin-user-management-shell">
  <div class="section-header admin-user-management-header">
    <div>
      <h1 class="section-header__title">User management</h1>
    </div>
    <span class="badge badge-blue"><?= $totalUsers ?> user<?= $totalUsers === 1 ? '' : 's' ?></span>
  </div>

  <?php foreach ($flash['error'] as $flashMessage): ?>
    <div class="flash-message flash-error" role="alert"><?= sanitise($flashMessage) ?></div>
  <?php endforeach; ?>
  <?php foreach ($flash['success'] as $flashMessage): ?>
    <div class="flash-message flash-success" role="status"><?= sanitise($flashMessage) ?></div>
  <?php endforeach; ?>

  <div class="dashboard-grid admin-user-summary-grid">
    <article class="stat-widget admin-user-summary-card">
      <span class="stat-widget__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M4 4h16v3H4V4Zm0 5h16v3H4V9Zm0 5h10v3H4v-3Zm0 5h10v2H4v-2Z"/></svg></span>
      <span class="stat-widget__value"><?= $totalUsers ?></span>
      <span class="stat-widget__label">Accounts on the platform</span>
    </article>
    <article class="stat-widget admin-user-summary-card">
      <span class="stat-widget__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-4 0-8 2-8 5v3h16v-3c0-3-4-5-8-5Z"/></svg></span>
      <span class="stat-widget__value"><?= $roleCounts['participant'] ?></span>
      <span class="stat-widget__label">Learners and activity loggers</span>
    </article>
    <article class="stat-widget admin-user-summary-card">
      <span class="stat-widget__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 1 3 5v6c0 5.2 3.8 10 9 11 5.2-1 9-5.8 9-11V5l-9-4Zm-1 14-3.5-3.5L9 10l2 2 4.5-4.5L17 9l-6 6Z"/></svg></span>
      <span class="stat-widget__value"><?= $roleCounts['moderator'] ?></span>
      <span class="stat-widget__label">Review and publishing staff</span>
    </article>
    <article class="stat-widget admin-user-summary-card">
      <span class="stat-widget__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 1 3 5v6c0 5.2 3.8 10 9 11 5.2-1 9-5.8 9-11V5l-9-4Zm0 5a2.5 2.5 0 1 1-2.5 2.5A2.5 2.5 0 0 1 12 6Zm0 11a6 6 0 0 1-4.2-1.8C7.8 13.7 10 13 12 13s4.2.7 4.2 2.2A6 6 0 0 1 12 17Z"/></svg></span>
      <span class="stat-widget__value"><?= $roleCounts['admin'] ?></span>
      <span class="stat-widget__label">Full-control accounts</span>
    </article>
  </div>

  <div class="admin-user-management-layout">
    <aside class="card admin-user-create-card">
      <div class="admin-user-create-card__header">
        <div>
          <h2 class="card-title">Create user</h2>
        </div>
        <span class="inline-pill-note">Fast add</span>
      </div>

      <form method="POST" class="form-card admin-user-create-form">
        <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
        <input type="hidden" name="action" value="create">

        <div class="form-group" style="margin-bottom:0;">
          <label for="create_username">Username</label>
          <input type="text" id="create_username" name="username" maxlength="50" value="<?= sanitise($userForm['username']) ?>" required>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label for="create_email">Email</label>
          <input type="email" id="create_email" name="email" maxlength="100" value="<?= sanitise($userForm['email']) ?>" required>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label for="create_role">Role</label>
          <select id="create_role" name="role">
            <option value="participant" <?= $userForm['role'] === 'participant' ? 'selected' : '' ?>>participant</option>
            <option value="moderator" <?= $userForm['role'] === 'moderator' ? 'selected' : '' ?>>moderator</option>
            <option value="admin" <?= $userForm['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label for="create_password">Password</label>
          <input type="password" id="create_password" name="password" minlength="8" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Add user</button>
      </form>
    </aside>

    <section class="card admin-user-directory-card">
      <div class="admin-card-header admin-user-directory-card__header">
        <div>
          <h2 class="card-title">User directory</h2>
        </div>
        <span class="inline-pill-note"><?= $filteredUsers ?> match<?= $filteredUsers === 1 ? '' : 'es' ?></span>
      </div>

      <div class="admin-user-role-switch" aria-label="Filter users by role">
        <?php foreach ($roleFilters as $roleKey => $filter): ?>
          <a
            href="<?= sanitise($buildUserManagementUrl(['role' => $roleKey, 'page' => 1])) ?>"
            class="admin-user-role-chip<?= $filterRole === $roleKey ? ' admin-user-role-chip--active' : '' ?>"
          >
            <span><?= sanitise($filter['label']) ?></span>
            <strong><?= (int)$filter['count'] ?></strong>
          </a>
        <?php endforeach; ?>
      </div>

      <form method="GET" class="admin-user-toolbar">
        <?php if ($filterRole !== 'all'): ?>
          <input type="hidden" name="role" value="<?= sanitise($filterRole) ?>">
        <?php endif; ?>
        <div class="admin-user-toolbar__search">
          <label class="sr-only" for="user_search">Search by username or email</label>
          <input type="text" id="user_search" name="q" value="<?= sanitise($searchQuery) ?>" placeholder="Search username or email">
        </div>

        <div class="admin-user-toolbar__filters">
          <button type="submit" class="btn btn-primary btn-sm">Search</button>
          <?php if ($searchQuery !== '' || $filterRole !== 'all'): ?>
            <a href="user_management.php" class="btn btn-outline btn-sm">Reset</a>
          <?php endif; ?>
        </div>
      </form>

      <div class="admin-user-results-bar">
        <span class="inline-pill-note">View: <?= sanitise($activeFilterLabel) ?></span>
      </div>

      <?php if (empty($users)): ?>
        <div class="admin-user-empty">
          <h3>No users match this view</h3>
          <p class="empty-state__text">Try clearing the search or switching the role filter to see more accounts.</p>
        </div>
      <?php else: ?>
        <div class="admin-user-list">
          <?php foreach ($users as $u): ?>
            <?php $meta = $roleMeta[$u['role']] ?? $roleMeta['participant']; ?>
            <details class="admin-user-item">
              <summary class="admin-user-item__summary">
                <div class="admin-user-item__identity">
                  <span class="admin-user-item__id">#<?= (int)$u['user_id'] ?></span>
                  <div class="admin-user-item__identity-body">
                    <strong><?= sanitise($u['username']) ?></strong>
                    <p><?= sanitise($u['email']) ?></p>
                  </div>
                </div>

                <div class="admin-user-item__stats">
                  <span class="badge <?= $meta['badge'] ?>"><?= sanitise($meta['label']) ?></span>
                  <span class="admin-user-item__metric"><?= sanitise($formatCreated((string)$u['created_at'])) ?></span>
                  <span class="admin-user-item__toggle">Edit account</span>
                </div>
              </summary>

              <div class="admin-user-item__panel">
                <form method="POST" class="admin-user-edit-form">
                  <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">

                  <div class="admin-user-edit-grid">
                    <div class="form-group" style="margin-bottom:0;">
                      <label for="username_<?= (int)$u['user_id'] ?>">Username</label>
                      <input type="text" id="username_<?= (int)$u['user_id'] ?>" name="username" maxlength="50" value="<?= sanitise($u['username']) ?>" required>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                      <label for="email_<?= (int)$u['user_id'] ?>">Email</label>
                      <input type="email" id="email_<?= (int)$u['user_id'] ?>" name="email" maxlength="100" value="<?= sanitise($u['email']) ?>" required>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                      <label for="role_<?= (int)$u['user_id'] ?>">Role</label>
                      <select id="role_<?= (int)$u['user_id'] ?>" name="role" <?= (int)$u['user_id'] === currentUserId() ? 'disabled' : '' ?>>
                        <option value="participant" <?= $u['role'] === 'participant' ? 'selected' : '' ?>>participant</option>
                        <option value="moderator" <?= $u['role'] === 'moderator' ? 'selected' : '' ?>>moderator</option>
                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                      </select>
                      <?php if ((int)$u['user_id'] === currentUserId()): ?>
                        <input type="hidden" name="role" value="<?= sanitise($u['role']) ?>">
                      <?php endif; ?>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                      <label for="password_<?= (int)$u['user_id'] ?>">New password</label>
                      <input type="password" id="password_<?= (int)$u['user_id'] ?>" name="new_password" placeholder="Leave blank to keep current password">
                    </div>
                  </div>

                  <div class="admin-user-item__footer">
                    <p class="meta-copy">Created <?= sanitise($formatCreated((string)$u['created_at'])) ?></p>
                    <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
                  </div>
                </form>

                <div class="admin-user-item__danger">
                  <?php if ((int)$u['user_id'] === currentUserId()): ?>
                    <span class="badge badge-grey">Current admin account</span>
                  <?php else: ?>
                    <form method="POST">
                      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this user?');">Delete user</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </details>
          <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
          <nav class="admin-user-pagination" aria-label="User directory pagination">
            <?php if ($currentPage > 1): ?>
              <a href="<?= sanitise($buildUserManagementUrl(['page' => $currentPage - 1])) ?>" class="btn btn-outline btn-sm">Previous</a>
            <?php else: ?>
              <span class="btn btn-outline btn-sm admin-user-pagination__disabled">Previous</span>
            <?php endif; ?>

            <span class="inline-pill-note">Page <?= $currentPage ?> of <?= $totalPages ?></span>

            <?php if ($currentPage < $totalPages): ?>
              <a href="<?= sanitise($buildUserManagementUrl(['page' => $currentPage + 1])) ?>" class="btn btn-outline btn-sm">Next</a>
            <?php else: ?>
              <span class="btn btn-outline btn-sm admin-user-pagination__disabled">Next</span>
            <?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
