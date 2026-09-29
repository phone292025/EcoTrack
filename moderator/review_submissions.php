<?php
require_once __DIR__ . '/../includes/bootstrap.php';

requireRole('moderator', 'admin');

$pdo = getPDO();
$reviewerId = currentUserId();
$isAdmin = currentRole() === 'admin';

const REVIEW_PER_PAGE = 20;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    flashResult(reviewSubmission(
        $reviewerId,
        $isAdmin,
        (int)($_POST['log_id'] ?? 0),
        (string)($_POST['submission_action'] ?? ''),
        (string)($_POST['review_note'] ?? '')
    ));

    redirectToSelf($_SERVER['QUERY_STRING'] ?? '');
}

$flash = takeFlash();

/* ---------------------------------------------------------------
 * Pending queue (paginated — the queue can get long)
 * -------------------------------------------------------------*/
$pendingTotal = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs WHERE status = "pending"')->fetchColumn();
$totalPages = max(1, (int)ceil($pendingTotal / REVIEW_PER_PAGE));
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * REVIEW_PER_PAGE;

$pendingStmt = $pdo->prepare(
    'SELECT al.*, u.username, c.name AS cat_name
     FROM activity_logs al
     JOIN users u ON u.user_id = al.user_id
     JOIN categories c ON c.cat_id = al.cat_id
     WHERE al.status = "pending"
     ORDER BY al.created_at ASC
     LIMIT :lim OFFSET :off'
);
$pendingStmt->bindValue(':lim', REVIEW_PER_PAGE, PDO::PARAM_INT);
$pendingStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$pendingStmt->execute();
$pending = $pendingStmt->fetchAll() ?: [];

$flagged = [];
if ($isAdmin) {
    $flagged = $pdo->query(
        'SELECT al.*, u.username, c.name AS cat_name, flagger.username AS flagged_by_name
         FROM activity_logs al
         JOIN users u ON u.user_id = al.user_id
         JOIN categories c ON c.cat_id = al.cat_id
         LEFT JOIN users flagger ON flagger.user_id = al.flagged_by
         WHERE al.status = "flagged"
         ORDER BY al.created_at ASC'
    )->fetchAll() ?: [];
}

$resultsFrom = $pendingTotal > 0 ? $offset + 1 : 0;
$resultsTo = $pendingTotal > 0 ? min($offset + REVIEW_PER_PAGE, $pendingTotal) : 0;

$pageTitle = 'Review submissions';
require_once __DIR__ . '/../layout/header.php';

/** Render one submission card with its moderation form. */
function renderSubmission(array $row, bool $isAdmin, bool $isFlaggedQueue): void
{
    $statusClass = $isFlaggedQueue ? 'badge-red' : 'badge-amber';
    $statusLabel = $isFlaggedQueue ? 'flagged' : 'pending';
    ?>
    <div class="submission-card review-entry">
      <div class="card-header-row">
        <div class="card-header-row__content">
          <p class="submission-card__who">
            <strong><?= sanitise($row['username']) ?></strong> &middot;
            <?= sanitise($row['cat_name']) ?> &middot;
            <?= (int)$row['points'] ?> pts
          </p>
          <p class="meta-copy">
            <?php if ($isFlaggedQueue): ?>
              Flagged by <?= sanitise($row['flagged_by_name'] ?? 'moderator') ?> &middot;
            <?php endif; ?>
            <?= sanitise($row['created_at']) ?>
          </p>
        </div>
        <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
      </div>

      <p class="submission-card__description"><?= nl2br(sanitise($row['description'])) ?></p>

      <?php if (!empty($row['review_note'])): ?>
        <p class="submission-card__prior-note">
          Earlier note: <?= sanitise($row['review_note']) ?>
        </p>
      <?php endif; ?>

      <?php if (!empty($row['evidence'])): ?>
        <div class="submission-evidence">
          <img src="<?= sanitise(evidenceUrl($row['evidence'])) ?>"
               alt="Evidence image from <?= sanitise($row['username']) ?>"
               loading="lazy" decoding="async">
          <a href="<?= sanitise(evidenceUrl($row['evidence'])) ?>"
             target="_blank" rel="noopener">Open full image</a>
        </div>
      <?php endif; ?>

      <form method="POST" class="submission-actions">
        <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
        <input type="hidden" name="log_id" value="<?= (int)$row['log_id'] ?>">

        <div class="form-group submission-actions__note">
          <label for="note_<?= (int)$row['log_id'] ?>">
            Note to participant <span class="field-optional">(required when rejecting)</span>
          </label>
          <input type="text" id="note_<?= (int)$row['log_id'] ?>" name="review_note"
                 maxlength="<?= REVIEW_NOTE_MAX ?>" placeholder="Explain what was missing or unclear">
        </div>

        <div class="submission-actions__buttons">
          <button type="submit" name="submission_action" value="approve" class="btn btn-primary btn-sm">
            <?= $isFlaggedQueue ? 'Approve flagged' : 'Approve' ?>
          </button>
          <button type="submit" name="submission_action" value="reject" class="btn btn-outline btn-sm"
                  data-requires-note="note_<?= (int)$row['log_id'] ?>">
            <?= $isFlaggedQueue ? 'Reject flagged' : 'Reject' ?>
          </button>
          <?php if (!$isAdmin && !$isFlaggedQueue): ?>
            <button type="submit" name="submission_action" value="flag" class="btn btn-danger btn-sm">Flag for admin</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
    <?php
}
?>

<div class="container page-shell review-page">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Submission review</h1>
    </div>
    <div class="badge-group">
      <span class="badge badge-amber"><?= $pendingTotal ?> pending</span>
      <?php if ($isAdmin): ?>
        <span class="badge badge-red"><?= count($flagged) ?> flagged</span>
      <?php endif; ?>
    </div>
  </div>

  <?php renderFlash($flash); ?>

  <div class="panel-stack">
    <div class="card review-panel">
      <div class="card-header-row">
        <div class="card-header-row__content">
          <h2 class="card-title">Pending submissions</h2>
        </div>
        <?php if ($pendingTotal > 0): ?>
          <span class="inline-pill-note">
            Showing <?= $resultsFrom ?>&ndash;<?= $resultsTo ?> of <?= $pendingTotal ?>
          </span>
        <?php endif; ?>
      </div>

      <?php if (empty($pending)): ?>
        <p class="card-copy">No pending submissions right now.</p>
      <?php else: ?>
        <?php foreach ($pending as $row): ?>
          <?php renderSubmission($row, $isAdmin, false); ?>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
          <nav class="pagination" aria-label="Pending submissions pages">
            <?php if ($currentPage > 1): ?>
              <a class="btn btn-sm btn-outline" href="?page=<?= $currentPage - 1 ?>">Previous</a>
            <?php endif; ?>
            <span class="pagination__status">Page <?= $currentPage ?> of <?= $totalPages ?></span>
            <?php if ($currentPage < $totalPages): ?>
              <a class="btn btn-sm btn-outline" href="?page=<?= $currentPage + 1 ?>">Next</a>
            <?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
      <div class="card review-panel">
        <h2 class="card-title">Flagged submissions</h2>
        <?php if (empty($flagged)): ?>
          <p class="card-copy">No flagged submissions right now.</p>
        <?php else: ?>
          <?php foreach ($flagged as $row): ?>
            <?php renderSubmission($row, $isAdmin, true); ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
