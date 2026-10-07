<?php
/**
 * محرّك CRUD عام لأقسام الموقع
 * كل صفحة إدارة بتعرّف: الجدول + الحقول، والباقي بيتعمل هنا
 *
 * تعريف الحقل:
 *   ['name'=>'title','label'=>'العنوان','type'=>'text|textarea|number|date|url|select|checkbox|image|icon','req'=>true,
 *    'options'=>[...], 'hint'=>'...', 'max'=>255]
 * نوع image بيستخدم عمودين: {name}_path (رفع) و {name}_url (لينك)
 */
require_once __DIR__ . '/auth.php';
sa_require();
// أعمدة الرئيسية الجديدة (الشارة · النقاط · كود الخصم …) لازم تكون موجودة قبل أي حفظ
require_once dirname(__DIR__) . '/site/home.php';
s_home_upgrade();

function crud_run(array $cfg): array
{
    $table  = $cfg['table'];
    $fields = $cfg['fields'];
    $self   = basename($_SERVER['SCRIPT_NAME']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        s_check_csrf();
        s_decode_b64();
        $action = (string) ($_POST['action'] ?? '');
        $id     = (int) ($_POST['id'] ?? 0);

        /* ─── حفظ (إضافة أو تعديل) ─── */
        if ($action === 'save') {
            $cols = [];
            $vals = [];
            $existing = $id ? s_one("SELECT * FROM `{$table}` WHERE id = ?", [$id]) : null;

            foreach ($fields as $f) {
                $n = $f['name'];
                $type = $f['type'] ?? 'text';

                if ($type === 'image') {
                    // لينك خارجي
                    $urlCol = $n . '_url';
                    $link = trim((string) ($_POST[$urlCol] ?? ''));
                    if ($link !== '' && !preg_match('~^https?://~i', $link)) {
                        $link = 'https://' . ltrim($link, '/');
                    }
                    $cols[] = "`{$urlCol}`";
                    $vals[] = $link !== '' ? mb_substr($link, 0, 700) : null;

                    // ملف مرفوع
                    $pathCol = $n . '_path';
                    $newPath = $existing[$pathCol] ?? null;
                    if (!empty($_FILES[$n]['name'])) {
                        $up = s_upload($_FILES[$n], $table);
                        if ($up['ok']) {
                            if ($newPath) s_delete_upload($newPath);
                            $newPath = $up['path'];
                        } else {
                            s_flash('danger', 'الصورة: ' . $up['error']);
                        }
                    }
                    if (!empty($_POST['__clear_' . $n])) {
                        if ($newPath) s_delete_upload($newPath);
                        $newPath = null;
                    }
                    $cols[] = "`{$pathCol}`";
                    $vals[] = $newPath;
                    continue;
                }

                if ($type === 'checkbox') {
                    $cols[] = "`{$n}`";
                    $vals[] = !empty($_POST[$n]) ? 1 : 0;
                    continue;
                }

                if ($type === 'number') {
                    $cols[] = "`{$n}`";
                    $vals[] = (int) ($_POST[$n] ?? 0);
                    continue;
                }

                if ($type === 'date') {
                    $v = trim((string) ($_POST[$n] ?? ''));
                    $cols[] = "`{$n}`";
                    $vals[] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
                    continue;
                }

                $v = trim((string) ($_POST[$n] ?? ''));
                if (($f['type'] ?? '') === 'url' && $v !== '' && !preg_match('~^(https?://|/|#|mailto:|tel:)~i', $v)) {
                    $v = 'https://' . ltrim($v, '/');
                }
                $max = (int) ($f['max'] ?? 1000);
                $cols[] = "`{$n}`";
                $vals[] = $v !== '' ? mb_substr($v, 0, $max) : null;
            }

            if ($id > 0) {
                $set = implode(', ', array_map(fn($c) => $c . ' = ?', $cols));
                $vals[] = $id;
                s_run("UPDATE `{$table}` SET {$set} WHERE id = ?", $vals);
                s_flash('success', 'تم الحفظ ✓');
            } else {
                $ph = implode(', ', array_fill(0, count($cols), '?'));
                s_insert("INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES ({$ph})", $vals);
                s_flash('success', 'تمت الإضافة ✓');
            }
            s_redirect('site-admin/' . $self);
        }

        /* ─── إظهار/إخفاء ─── */
        if ($action === 'toggle') {
            $col = $cfg['toggle_col'] ?? 'is_active';
            s_run("UPDATE `{$table}` SET `{$col}` = 1 - `{$col}` WHERE id = ?", [$id]);
            s_redirect('site-admin/' . $self);
        }

        /* ─── حذف ─── */
        if ($action === 'delete') {
            $row = s_one("SELECT * FROM `{$table}` WHERE id = ?", [$id]);
            if ($row) {
                foreach ($fields as $f) {
                    if (($f['type'] ?? '') === 'image' && !empty($row[$f['name'] . '_path'])) {
                        s_delete_upload($row[$f['name'] . '_path']);
                    }
                }
                s_run("DELETE FROM `{$table}` WHERE id = ?", [$id]);
                s_flash('success', 'تم الحذف');
            }
            s_redirect('site-admin/' . $self);
        }

        /* ─── ترتيب ─── */
        if ($action === 'reorder') {
            foreach (($_POST['order'] ?? []) as $rid => $ord) {
                s_run("UPDATE `{$table}` SET sort_order = ? WHERE id = ?", [(int) $ord, (int) $rid]);
            }
            s_flash('success', 'تم حفظ الترتيب ✓');
            s_redirect('site-admin/' . $self);
        }
    }

    $editing = null;
    if (!empty($_GET['edit'])) {
        $editing = s_one("SELECT * FROM `{$table}` WHERE id = ?", [(int) $_GET['edit']]);
    }
    $order = $cfg['order'] ?? 'sort_order, id';
    $rows = s_all("SELECT * FROM `{$table}` ORDER BY {$order}");

    return ['rows' => $rows, 'editing' => $editing];
}

/** رسم فورم الإضافة/التعديل */
function crud_form(array $cfg, ?array $editing): void
{
    $fields = $cfg['fields'];
    $self = basename($_SERVER['SCRIPT_NAME']);
    $hasImage = (bool) array_filter($fields, fn($f) => ($f['type'] ?? '') === 'image');
    ?>
    <div class="card">
        <h3><?= $editing ? '✎ تعديل' : '＋ ' . e($cfg['add_label'] ?? 'إضافة جديد') ?></h3>
        <form method="POST" data-safe-post <?= $hasImage ? 'enctype="multipart/form-data"' : '' ?>>
            <?= s_csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="row">
            <?php foreach ($fields as $f):
                $n = $f['name'];
                $type = $f['type'] ?? 'text';
                $val = $editing[$n] ?? ($f['default'] ?? '');
                $wide = in_array($type, ['textarea', 'image'], true) || !empty($f['wide']);
                ?>
                <div class="f" style="<?= $wide ? 'grid-column:1/-1' : '' ?>">
                    <label><?= e($f['label']) ?><?= !empty($f['req']) ? ' *' : '' ?></label>

                    <?php if ($type === 'textarea'): ?>
                        <textarea name="<?= e($n) ?>" rows="<?= (int) ($f['rows'] ?? 3) ?>"
                                  placeholder="<?= e($f['ph'] ?? '') ?>" <?= !empty($f['req']) ? 'required' : '' ?>><?= e($val) ?></textarea>

                    <?php elseif ($type === 'select'): ?>
                        <select name="<?= e($n) ?>">
                            <?php foreach (($f['options'] ?? []) as $ov => $ol): ?>
                                <option value="<?= e($ov) ?>" <?= (string) $val === (string) $ov ? 'selected' : '' ?>><?= e($ol) ?></option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($type === 'checkbox'): ?>
                        <label style="display:flex;gap:8px;align-items:center;font-weight:400;cursor:pointer">
                            <input type="checkbox" name="<?= e($n) ?>" value="1"
                                   <?= ($editing ? !empty($val) : !empty($f['default'])) ? 'checked' : '' ?>
                                   style="width:auto">
                            <span><?= e($f['cb_label'] ?? 'مفعّل') ?></span>
                        </label>

                    <?php elseif ($type === 'image'): ?>
                        <?php
                            $curPath = $editing[$n . '_path'] ?? '';
                            $curUrl  = $editing[$n . '_url'] ?? '';
                            $preview = $curUrl ?: ($curPath ? SITE_UPLOAD_URL . '/' . $curPath : '');
                        ?>
                        <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
                            <?php if ($preview): ?>
                                <img src="<?= e($preview) ?>" class="thumb" style="width:70px;height:70px" alt="">
                            <?php endif; ?>
                            <div style="flex:1;min-width:220px">
                                <input type="file" name="<?= e($n) ?>" accept="image/*" style="font-size:12.5px;margin-bottom:7px">
                                <input type="text" name="<?= e($n) ?>_url" dir="ltr" value="<?= e($curUrl) ?>"
                                       placeholder="أو حط لينك صورة مباشر https://...">
                                <div class="hint">ارفع صورة أو حط لينك — اللي مرفوع له الأولوية</div>
                                <?php if ($curPath): ?>
                                    <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:12px;margin-top:5px">
                                        <input type="checkbox" name="__clear_<?= e($n) ?>" value="1" style="width:auto"> امسح الصورة المرفوعة
                                    </label>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php elseif ($type === 'icon'): ?>
                        <input type="text" name="<?= e($n) ?>" value="<?= e($val) ?>" placeholder="◈ 🎨 💡 ⚡ 🚀"
                               style="max-width:130px;font-size:19px;text-align:center">
                        <div class="hint">اسم أيقونة من الطقم الجديد: pen · clock · calendar · shuffle · wallet · brain · search · bulb · image · megaphone · send · layers · sparkle · gift — أو إيموجي زي ◈ 🎨 💡 🚀</div>

                    <?php else: ?>
                        <input type="<?= $type === 'number' ? 'number' : ($type === 'date' ? 'date' : 'text') ?>"
                               name="<?= e($n) ?>" value="<?= e($val) ?>"
                               <?= $type === 'url' ? 'dir="ltr"' : '' ?>
                               placeholder="<?= e($f['ph'] ?? '') ?>" <?= !empty($f['req']) ? 'required' : '' ?>>
                    <?php endif; ?>

                    <?php if (!empty($f['hint'])): ?><div class="hint"><?= e($f['hint']) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>

            <button class="btn"><?= $editing ? '💾 حفظ التعديلات' : '＋ إضافة' ?></button>
            <?php if ($editing): ?>
                <a href="<?= e($self) ?>" class="btn g">إلغاء</a>
            <?php endif; ?>
        </form>
    </div>
    <?php
}

/** رسم جدول العناصر */
function crud_table(array $cfg, array $rows): void
{
    $self = basename($_SERVER['SCRIPT_NAME']);
    $cols = $cfg['list_cols'];
    $imgField = null;
    foreach ($cfg['fields'] as $f) {
        if (($f['type'] ?? '') === 'image') { $imgField = $f['name']; break; }
    }
    ?>
    <div class="card">
        <h3><?= e($cfg['list_label'] ?? 'العناصر') ?> (<?= count($rows) ?>)</h3>
        <?php if (!$rows): ?>
            <p style="color:var(--dim);font-size:14px">مفيش عناصر لسه — أضف أول واحد من فوق 👆</p>
        <?php else: ?>
        <form method="POST" data-safe-post>
            <?= s_csrf_field() ?>
            <input type="hidden" name="action" value="reorder">
            <div class="tw">
            <table>
                <thead><tr>
                    <?php if ($imgField): ?><th></th><?php endif; ?>
                    <?php foreach ($cols as $c): ?><th><?= e($c[1]) ?></th><?php endforeach; ?>
                    <th>ترتيب</th><th>الحالة</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr style="<?= isset($r['is_active']) && !$r['is_active'] ? 'opacity:.5' : '' ?>">
                        <?php if ($imgField):
                            $im = s_img($r, $imgField . '_path', $imgField . '_url'); ?>
                            <td><?php if ($im): ?><img src="<?= e($im) ?>" class="thumb" alt=""><?php endif; ?></td>
                        <?php endif; ?>
                        <?php foreach ($cols as $c):
                            $v = (string) ($r[$c[0]] ?? '');
                            ?>
                            <td><?= e(mb_substr($v, 0, (int) ($c[2] ?? 60))) ?><?= mb_strlen($v) > (int) ($c[2] ?? 60) ? '…' : '' ?></td>
                        <?php endforeach; ?>
                        <td><input type="number" name="order[<?= (int) $r['id'] ?>]" value="<?= (int) ($r['sort_order'] ?? 0) ?>" style="width:66px;padding:5px"></td>
                        <td>
                            <?php if (isset($r['is_active'])): ?>
                                <span class="chip <?= $r['is_active'] ? 'on' : '' ?>"><?= $r['is_active'] ? 'ظاهر' : 'مخفي' ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <a href="?edit=<?= (int) $r['id'] ?>" class="btn g s">✎</a>
                            <?php if (isset($r['is_active'])): ?>
                                <button type="submit" form="tg-<?= (int) $r['id'] ?>" class="btn g s"><?= $r['is_active'] ? '🚫' : '👁' ?></button>
                            <?php endif; ?>
                            <button type="submit" form="dl-<?= (int) $r['id'] ?>" class="btn d s">🗑</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <button class="btn g" style="margin-top:12px">↕ حفظ الترتيب</button>
        </form>

        <?php foreach ($rows as $r): ?>
            <form id="tg-<?= (int) $r['id'] ?>" method="POST" style="display:none">
                <?= s_csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            </form>
            <form id="dl-<?= (int) $r['id'] ?>" method="POST" style="display:none" onsubmit="return confirm('حذف نهائي؟')">
                <?= s_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            </form>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}

/** صفحة إدارة كاملة في سطر واحد */
function crud_page(array $cfg): void
{
    $res = crud_run($cfg);
    $__t = $cfg['title'];
    include __DIR__ . '/layout.php';
    if (!empty($cfg['intro'])) {
        echo '<div class="card" style="background:rgba(15,60,201,.06);border-color:rgba(15,60,201,.2)">' . e($cfg['intro']) . '</div>';
    }
    crud_form($cfg, $res['editing']);
    crud_table($cfg, $res['rows']);
    include __DIR__ . '/layout-end.php';
}
