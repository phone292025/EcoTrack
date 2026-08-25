<?php
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$uid = currentUserId();
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
        } else {
            try {
                $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ?')
                    ->execute([$email, $uid]);
                setFlash('success', 'Profile updated.');
            } catch (PDOException $e) {
                if (isDuplicateKeyError($e)) {
                    setFlash('error', 'That email address is already in use.');
                } else {
                    error_log('[EcoTrack profile] ' . $e->getMessage());
                    setFlash('error', 'Could not update your profile right now.');
                }
            }
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare('SELECT password FROM users WHERE user_id = ?');
        $stmt->execute([$uid]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            setFlash('error', 'Your current password is not correct.');
        } elseif (strlen($new) < 8) {
            setFlash('error', 'New password must be at least 8 characters.');
        } elseif (!preg_match('/[A-Z]/', $new) || !preg_match('/[0-9]/', $new)) {
            setFlash('error', 'New password must include an uppercase letter and a number.');
        } elseif ($new !== $confirm) {
            setFlash('error', 'New passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            setFlash('success', 'Password changed.');
        }
    } elseif ($action === 'update_avatar') {
        if (empty($_FILES['avatar']['name'])
            || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            setFlash('error', 'Choose an image to upload.');
        } else {
            $stored = handleFileUpload($_FILES['avatar'], 'avatars');

            if ($stored === null) {
                setFlash('error', 'That file could not be used. Use a JPG, PNG, GIF or WebP under 5 MB.');
            } else {
                $previous = $pdo->prepare('SELECT avatar FROM users WHERE user_id = ?');
                $previous->execute([$uid]);
                $oldAvatar = (string)$previous->fetchColumn();

                $pdo->prepare('UPDATE users SET avatar = ? WHERE user_id = ?')
                    ->execute([$stored, $uid]);

                // Remove the replaced file so the folder does not grow forever.
                if ($oldAvatar !== '' && preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/i', $oldAvatar)) {
                    @unlink(__DIR__ . '/../uploads/avatars/' . $oldAvatar);
                }

                setFlash('success', 'Profile picture updated.');
            }
        }
    }

    redirectToSelf();
}

$flash = takeFlash();

$user           = getUserById($uid);
$badges         = getUserBadges($uid);
$impact         = getEcoImpactSummary($uid);
$co2Data        = getCO2Savings($uid);
$history        = getUserActivityLog($uid, 1, 50);
$challengeStats = getUserChallengeStats($uid);

$earnedBadges = 0;
foreach ($badges as $badge) {
    if (!empty($badge['earned'])) {
        $earnedBadges++;
    }
}

$pageTitle   = 'Profile';
$needsCharts = true;
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell profile-page">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Your profile</h1>
    </div>
    <span class="badge badge-blue"><?= (int)($user['points'] ?? 0) ?> pts</span>
  </div>

  <?php foreach ($flash['error'] as $message): ?>
    <div class="flash-message flash-error" role="alert"><?= sanitise($message) ?></div>
  <?php endforeach; ?>
  <?php foreach ($flash['success'] as $message): ?>
    <div class="flash-message flash-success" role="status"><?= sanitise($message) ?></div>
  <?php endforeach; ?>

  <div class="dashboard-grid profile-stats-grid">
    <div class="stat-widget">
      <span class="stat-widget__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-4 0-8 2-8 5v3h16v-3c0-3-4-5-8-5Z"/></svg></span>
      <span class="stat-widget__value stat-widget__value--wrap"><?= sanitise($user['username'] ?? '') ?></span>
      <span class="stat-widget__label stat-widget__label--wrap"><?= sanitise($user['email'] ?? '') ?></span>
    </div>
    <div class="stat-widget stat-widget--accent">
      <span class="stat-widget__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 2s5 5.2 5 9.5A5 5 0 0 1 7 11.5C7 7.2 12 2 12 2Z"/></svg></span>
      <span class="stat-widget__value"><?= (int)($user['streak'] ?? 0) ?></span>
      <span class="stat-widget__label">Current day streak</span>
    </div>
    <div class="stat-widget stat-widget--info">
      <span class="stat-widget__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/></svg></span>
      <span class="stat-widget__value"><?= (int)($challengeStats['completed'] ?? 0) ?></span>
      <span class="stat-widget__label">Challenges completed</span>
    </div>
  </div>

  <div class="split-layout split-layout--profile profile-layout">
    <div class="panel-stack profile-layout__main">
      <div class="card profile-chart-card">
        <h2 class="card-title">Carbon footprint graph</h2>
        <div class="chart-container profile-chart-card__chart">
          <canvas id="co2Chart" aria-label="Carbon footprint savings graph"></canvas>
        </div>
        <div class="profile-chart-card__impact">
          <div class="profile-impact-grid">
            <div class="profile-impact-stat">
              <span class="profile-impact-stat__label">KG CO2 saved</span>
              <span class="profile-impact-stat__value"><?= sanitise((string)$impact['co2_kg']) ?></span>
            </div>
            <div class="profile-impact-stat">
              <span class="profile-impact-stat__label">Bottles avoided</span>
              <span class="profile-impact-stat__value"><?= sanitise((string)$impact['plastic_bottles']) ?></span>
            </div>
            <div class="profile-impact-stat">
              <span class="profile-impact-stat__label">Tree-years</span>
              <span class="profile-impact-stat__value"><?= sanitise((string)$impact['trees_equivalent']) ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <h2 class="card-title">Account settings</h2>

        <div class="profile-avatar-row">
          <div class="profile-avatar">
            <?php if (!empty($user['avatar'])): ?>
              <img src="<?= BASE_URL ?>/uploads/avatars/<?= sanitise($user['avatar']) ?>"
                   alt="Your profile picture" width="72" height="72">
            <?php else: ?>
              <span class="profile-avatar__initial" aria-hidden="true">
                <?= sanitise(strtoupper(substr((string)($user['username'] ?? '?'), 0, 1))) ?>
              </span>
            <?php endif; ?>
          </div>
          <form method="POST" enctype="multipart/form-data" class="profile-avatar-form">
            <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
            <input type="hidden" name="action" value="update_avatar">
            <div class="form-group">
              <label for="avatar">Profile picture</label>
              <input type="file" id="avatar" name="avatar"
                     accept="image/jpeg,image/png,image/gif,image/webp">
              <small class="field-hint">JPG, PNG, GIF or WebP. Max 5 MB.</small>
            </div>
            <button type="submit" class="btn btn-outline btn-sm">Upload picture</button>
          </form>
        </div>

        <div class="profile-forms">
          <form method="POST" class="profile-form">
            <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
            <input type="hidden" name="action" value="update_profile">
            <h3 class="profile-form__title">Contact details</h3>
            <div class="form-group">
              <label for="profile_username">Username</label>
              <input type="text" id="profile_username" value="<?= sanitise($user['username'] ?? '') ?>" disabled>
              <small class="field-hint">Usernames cannot be changed. Ask an administrator if you need this updated.</small>
            </div>
            <div class="form-group">
              <label for="profile_email">Email address</label>
              <input type="email" id="profile_email" name="email"
                     value="<?= sanitise($user['email'] ?? '') ?>" required>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Save details</button>
          </form>

          <form method="POST" class="profile-form">
            <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
            <input type="hidden" name="action" value="change_password">
            <h3 class="profile-form__title">Change password</h3>
            <div class="form-group">
              <label for="current_password">Current password</label>
              <input type="password" id="current_password" name="current_password"
                     autocomplete="current-password" required>
            </div>
            <div class="form-group">
              <label for="new_password">New password</label>
              <input type="password" id="new_password" name="new_password"
                     autocomplete="new-password" required>
              <small class="field-hint">Min 8 characters, one uppercase letter, one number.</small>
            </div>
            <div class="form-group">
              <label for="confirm_password">Confirm new password</label>
              <input type="password" id="confirm_password" name="confirm_password"
                     autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Change password</button>
          </form>
        </div>
      </div>
    </div>

    <div class="panel-stack profile-layout__side">
      <div class="card profile-history-card">
        <div class="card-header-row profile-history-card__header">
          <div>
            <h2 class="card-title profile-history-card__title">Activity history</h2>
          </div>
          <span class="inline-pill-note"><?= count($history) ?> recent</span>
        </div>
        <?php if (empty($history)): ?>
          <p class="card-copy">No activity history yet.</p>
        <?php else: ?>
          <div class="profile-history-card__scroller">
            <ul class="activity-list activity-list--profile">
              <?php foreach ($history as $row): ?>
                <li class="activity-list__item">
                  <span class="activity-list__body">
                    <strong><?= sanitise($row['cat_name']) ?></strong>
                    <span class="activity-list__description"><?= sanitise($row['description']) ?></span>
                    <span class="activity-list__status status-<?= sanitise($row['status']) ?>"><?= sanitise($row['status']) ?></span>
                    <?php if ($row['status'] === 'rejected' && !empty($row['review_note'])): ?>
                      <span class="activity-list__note">Moderator note: <?= sanitise($row['review_note']) ?></span>
                    <?php endif; ?>
                  </span>
                  <span class="activity-list__points"><?= (int)$row['points'] ?> pts</span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card profile-badges-card">
    <div class="card-header-row">
      <div class="card-header-row__content">
        <h2 class="card-title">Badge gallery</h2>
      </div>
      <span class="inline-pill-note"><?= $earnedBadges ?> of <?= count($badges) ?> earned</span>
    </div>
    <div class="badge-gallery badge-gallery--profile">
      <?php foreach ($badges as $badge): ?>
        <?php $isEarned = !empty($badge['earned']); ?>
        <div class="badge-item <?= $isEarned ? '' : 'badge-item--locked' ?>">
          <div class="badge-item__art">
            <?php if (!empty($badge['icon'])): ?>
              <img src="<?= BASE_URL ?>/assets/img/<?= sanitise($badge['icon']) ?>"
                   alt="" width="64" height="64" loading="lazy">
            <?php endif; ?>
            <?php if (!$isEarned): ?>
              <span class="badge-item__lock" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M17 9V7a5 5 0 0 0-10 0v2H5v12h14V9h-2Zm-8-2a3 3 0 0 1 6 0v2H9V7Z"/></svg>
              </span>
            <?php endif; ?>
          </div>
          <div class="badge-item__name"><?= sanitise($badge['name']) ?></div>
          <div class="badge-item__hint">
            <?php if ($isEarned): ?>
              <?= sanitise($badge['description'] ?? '') ?>
            <?php else: ?>
              <?= sanitise(describeBadgeCriteria($badge['criteria'])) ?>
            <?php endif; ?>
          </div>
          <?php if ($isEarned && !empty($badge['earned_at'])): ?>
            <div class="badge-item__earned">Earned <?= sanitise(date('M j, Y', strtotime((string)$badge['earned_at']))) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
const CO2_DATA = <?= json_encode($co2Data, JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/charts.js"></script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
