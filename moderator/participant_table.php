<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('moderator', 'admin');

$pdo = getPDO();
$search = trim($_GET['q'] ?? '');

$totalParticipants = (int)$pdo->query(
    'SELECT COUNT(*) FROM users WHERE role = "participant"'
)->fetchColumn();

$activeParticipants = (int)$pdo->query(
    'SELECT COUNT(*)
     FROM users u
     WHERE u.role = "participant"
       AND (
         EXISTS (
           SELECT 1
           FROM activity_logs al
           WHERE al.user_id = u.user_id
             AND al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         )
         OR EXISTS (
           SELECT 1
           FROM daily_checkins dc
           WHERE dc.user_id = u.user_id
             AND dc.checkin_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         )
       )'
)->fetchColumn();

$averagePoints = (int)round((float)$pdo->query(
    'SELECT COALESCE(AVG(points), 0) FROM users WHERE role = "participant"'
)->fetchColumn());

$highestStreak = (int)$pdo->query(
    'SELECT COALESCE(MAX(streak), 0) FROM users WHERE role = "participant"'
)->fetchColumn();

$sql = '
    SELECT u.user_id, u.username, u.email, u.points, u.streak, u.created_at,
           COUNT(DISTINCT CASE WHEN al.status = "approved" THEN al.log_id END) AS approved_logs,
           COUNT(DISTINCT ub.badge_id) AS badge_count,
           MAX(CASE WHEN al.status = "approved" THEN al.created_at END) AS last_approved_at,
           MAX(dc.checkin_date) AS last_checkin
    FROM users u
    LEFT JOIN activity_logs al ON al.user_id = u.user_id
    LEFT JOIN user_badges ub ON ub.user_id = u.user_id
    LEFT JOIN daily_checkins dc ON dc.user_id = u.user_id
    WHERE u.role = "participant"
';

$params = [];
if ($search !== '') {
    $sql .= ' AND (u.username LIKE ? OR u.email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$sql .= '
    GROUP BY u.user_id, u.username, u.email, u.points, u.streak, u.created_at
    ORDER BY u.points DESC, approved_logs DESC, u.username ASC
';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$participants = $stmt->fetchAll() ?: [];

$formatDate = static function (?string $value): string {
    if (!$value) {
        return 'No activity yet';
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return (string)$value;
    }
};

$resolveLastActivity = static function (array $participant): ?string {
    $approved = $participant['last_approved_at'] ?? null;
    $checkin = !empty($participant['last_checkin']) ? $participant['last_checkin'] . ' 00:00:00' : null;

    if ($approved && $checkin) {
        return $approved >= $checkin ? $approved : $checkin;
    }

    return $approved ?: $checkin;
};

$pageTitle = 'Participants';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell admin-analytics-shell admin-participant-shell">
  <div class="section-header admin-analytics-header">
    <div>
      <h1 class="section-header__title">Participant table</h1>
      <p class="section-header__text">A read-only moderator view of participant accounts, with points, approved activity logs, badges, streaks, and latest activity all in one place.</p>
    </div>
    <span class="badge badge-blue"><?= count($participants) ?> shown</span>
  </div>

  <div class="dashboard-grid admin-dashboard-kpis admin-participant-summary">
    <article class="stat-widget admin-kpi-card">
      <span class="stat-widget__icon">Participants</span>
      <span class="stat-widget__value"><?= $totalParticipants ?></span>
      <span class="stat-widget__label">Registered participant accounts</span>
    </article>

    <article class="stat-widget stat-widget--info admin-kpi-card">
      <span class="stat-widget__icon">Active</span>
      <span class="stat-widget__value"><?= $activeParticipants ?></span>
      <span class="stat-widget__label">Active in the last 30 days</span>
    </article>

    <article class="stat-widget admin-kpi-card">
      <span class="stat-widget__icon">Average</span>
      <span class="stat-widget__value"><?= number_format($averagePoints) ?></span>
      <span class="stat-widget__label">Average participant points</span>
    </article>

    <article class="stat-widget stat-widget--accent admin-kpi-card">
      <span class="stat-widget__icon">Streak</span>
      <span class="stat-widget__value"><?= $highestStreak ?></span>
      <span class="stat-widget__label">Highest current streak</span>
    </article>
  </div>

  <section class="card admin-data-card admin-participant-page-card">
    <div class="admin-card-header admin-participant-toolbar">
      <div>
        <h2 class="card-title">Full participant list</h2>
        <p class="admin-card-copy">Search by username or email to quickly review participant momentum before moderating logs or checking challenge progress.</p>
      </div>
      <div class="admin-participant-toolbar__actions">
        <form method="GET" class="admin-participant-search">
          <label class="sr-only" for="moderator_participant_search">Search participants</label>
          <input type="text" id="moderator_participant_search" name="q" value="<?= sanitise($search) ?>" placeholder="Search username or email">
          <button type="submit" class="btn btn-primary">Search</button>
          <a href="<?= BASE_URL ?>/moderator/participant_table.php" class="btn btn-outline">Reset</a>
        </form>
        <a href="<?= BASE_URL ?>/moderator/review_submissions.php" class="inline-pill-note">Open review queue</a>
      </div>
    </div>

    <?php if (empty($participants)): ?>
      <p class="empty-state__text">No participants match this filter.</p>
    <?php else: ?>
      <div class="table-wrap admin-participant-table-wrap">
        <table class="data-table admin-participant-table admin-participant-table--full">
          <thead>
            <tr>
              <th>Rank</th>
              <th>Participant</th>
              <th>Email</th>
              <th>Points</th>
              <th>Logs</th>
              <th>Badges</th>
              <th>Streak</th>
              <th>Last activity</th>
              <th>Joined</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($participants as $index => $participant): ?>
              <?php
                $lastActivity = $resolveLastActivity($participant);
                $isActive = $lastActivity !== null && strtotime($lastActivity) >= strtotime('-30 days');
              ?>
              <tr>
                <td>#<?= $index + 1 ?></td>
                <td class="admin-participant-table__user">
                  <strong><?= sanitise($participant['username']) ?></strong>
                  <span class="badge <?= $isActive ? 'badge-green' : 'badge-grey' ?>"><?= $isActive ? 'active' : 'quiet' ?></span>
                </td>
                <td><?= sanitise($participant['email']) ?></td>
                <td><?= number_format((int)$participant['points']) ?> pts</td>
                <td><?= (int)$participant['approved_logs'] ?></td>
                <td><?= (int)$participant['badge_count'] ?></td>
                <td><?= (int)$participant['streak'] ?></td>
                <td><?= sanitise($formatDate($lastActivity)) ?></td>
                <td><?= sanitise($formatDate((string)$participant['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
