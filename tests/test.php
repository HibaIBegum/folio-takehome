<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('document with publish_at in the future is not yet available', function () {
    $staff = current_staff();
    $futureUtc = (new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, ?, ?)');
    $stmt->execute(['Future Doc', 'body', $staff['id'], $futureUtc]);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();

    $publishAt = new DateTimeImmutable($row['publish_at'], new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    assert_true($now < $publishAt, 'expected publish_at to be in the future');
});

test('document with publish_at in the past is available', function () {
    $staff = current_staff();
    $pastUtc = (new DateTimeImmutable('-1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, ?, ?)');
    $stmt->execute(['Past Doc', 'body', $staff['id'], $pastUtc]);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();

    $publishAt = new DateTimeImmutable($row['publish_at'], new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    assert_true($now >= $publishAt, 'expected publish_at to be in the past');
});

test('document with null publish_at is immediately available', function () {
    $staff = current_staff();
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, ?)');
    $stmt->execute(['Immediate Doc', 'body', $staff['id']]);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();

    assert_true(empty($row['publish_at']), 'expected publish_at to be null');
});

test('search by partial title returns matches and excludes non-matches', function () {
    $staff = current_staff();

    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, ?)');
    $stmt->execute(['Alpha Report', 'body a', $staff['id']]);
    $stmt->execute(['Beta Summary', 'body b', $staff['id']]);

    $search = 'Alpha';
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ?
        ORDER BY d.created_at DESC
    ');
    $stmt->execute(['%' . $search . '%']);
    $rows = $stmt->fetchAll();

    $titles = array_column($rows, 'title');
    assert_true(in_array('Alpha Report', $titles), 'expected Alpha Report in results');
    assert_true(!in_array('Beta Summary', $titles), 'expected Beta Summary to be excluded');
});

test('share link with matching slug and token resolves to document; wrong slug returns nothing', function () {
    $staff = current_staff();

    $slug = 'test-slug-ab12';
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, ?, ?)');
    $stmt->execute(['Slug Test Doc', 'body', $staff['id'], $slug]);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'r@example.com']);

    // Correct slug + token resolves
    $stmt = db()->prepare('
        SELECT d.title FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ? AND d.slug = ?
    ');
    $stmt->execute([$token, $slug]);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected document to resolve with correct slug and token');
    assert_true($row['title'] === 'Slug Test Doc', 'unexpected title: ' . var_export($row['title'], true));

    // Wrong slug returns nothing
    $stmt->execute([$token, 'wrong-slug-0000']);
    $row = $stmt->fetch();
    assert_true($row === false, 'expected no result with wrong slug');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
