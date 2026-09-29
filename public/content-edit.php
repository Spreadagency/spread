<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();
$id = (int) ($_GET['id'] ?? 0);
$content = get_user_content($id, (int) $user['id']);

if (!$content) {
    flash_set('danger', 'المحتوى غير موجود');
    redirect('content-history.php');
}

$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $newText = trim($_POST['generated_text'] ?? '');
    $newHashtags = trim($_POST['hashtags'] ?? '');
    $newCta = trim($_POST['cta'] ?? '');
    $editNote = trim($_POST['edit_note'] ?? '');

    if ($newText !== $content['generated_text'] ||
        $newHashtags !== ($content['hashtags'] ?? '') ||
        $newCta !== ($content['cta'] ?? '')) {

        // Save current as version (before changes)
        save_content_version(
            $id,
            $content['generated_text'],
            $content['hashtags'],
            $content['cta'],
            'edited',
            'إصدار سابق قبل التعديل'
        );

        // Update main
        db_run(
            'UPDATE contents SET generated_text=?, hashtags=?, cta=?, status=?, updated_at=NOW() WHERE id=?',
            [$newText, $newHashtags, $newCta, 'edited', $id]
        );

        if ($editNote) {
            db_run(
                'INSERT INTO content_notes (content_id, user_id, note) VALUES (?, ?, ?)',
                [$id, $user['id'], $editNote]
            );
        }

        flash_set('success', 'تم حفظ التعديل بنجاح ✓');
    } else {
        flash_set('info', 'لم يتم إجراء أي تغيير');
    }
    redirect('content-view.php?id=' . $id);
}

$active = 'history';
$page_title = 'تعديل منشور #' . $id;
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head with-actions">
            <div>
                <a href="<?= url('content-view.php?id=' . $id) ?>" class="text-mute" style="font-size:13px">← إلغاء</a>
                <h1>تعديل منشور #<?= $id ?> ✎</h1>
                <div class="sub">عدّل النص والهاشتاجات والـ CTA — التعديل اليدوي مجاني (مش هيخصم كريدت)</div>
            </div>
        </div>

        <div class="alert info">
            ℹ بنحفظ نسخة من الإصدار الحالي قبل التعديل، فتقدر ترجع لها في أي وقت.
        </div>

        <form method="POST">
            <?= csrf_field() ?>

            <div class="card">
                <div class="field">
                    <label>نص المنشور</label>
                    <textarea name="generated_text" class="textarea auto" rows="10" required><?= e($content['generated_text']) ?></textarea>
                </div>

                <div class="field">
                    <label>الهاشتاجات</label>
                    <textarea name="hashtags" class="textarea auto" rows="2"><?= e($content['hashtags'] ?? '') ?></textarea>
                </div>

                <div class="field">
                    <label>Call-to-Action</label>
                    <input type="text" name="cta" class="input" value="<?= e($content['cta'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>ملاحظة عن التعديل (اختياري)</label>
                    <input type="text" name="edit_note" class="input" placeholder="مثال: قصرت المقدمة، عدلت النبرة...">
                </div>

                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn lg">✓ حفظ التعديل</button>
                    <a href="<?= url('content-view.php?id=' . $id) ?>" class="btn ghost lg">إلغاء</a>
                </div>
            </div>
        </form>
    </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
