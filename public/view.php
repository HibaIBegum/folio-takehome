<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$token = $_GET['token'] ?? '';
$slug  = $_GET['slug'] ?? '';

$stmt = db()->prepare('
    SELECT d.*, s.recipient_email
    FROM shares s
    JOIN documents d ON d.id = s.document_id
    WHERE s.token = ?
    AND d.slug = ?
');
$stmt->execute([$token, $slug]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found');
    ?>
    <div class="centered-message">
        <h1>Share link not found</h1>
        <p>The link you used is invalid or has been removed.</p>
    </div>
    <?php
    render_footer();
    exit;
}

// publish_at is stored as UTC; compare against current UTC time
if (!empty($doc['publish_at'])) {
    $publishAt = new DateTimeImmutable($doc['publish_at'], new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($now < $publishAt) {
        render_header('Not yet available');
        ?>
        <div class="centered-message">
            <h1>Not yet available</h1>
            <p>This document has not been published yet. Please check back later.</p>
        </div>
        <?php
        render_footer();
        exit;
    }
}

render_header($doc['title']);
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<p class="meta">Shared with <?= h($doc['recipient_email']) ?></p>

<pre class="doc-body"><?= h($doc['body']) ?></pre>

<?php render_footer(); ?>
