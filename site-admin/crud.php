<?php
/**
 * محرّك CRUD عام لأقسام الموقع — Website OS
 * كل صفحة إدارة بتعرّف: الجدول + الحقول، والباقي بيتعمل هنا (نفس الإجراءات القديمة + الجديدة):
 *   save · toggle · delete · reorder (القديمة) + toggle_ajax · reorder_ajax · move · duplicate (الجديدة)
 *   كل عملية بتتسجّل في «سجل النشاط»، والصفحة محمية بصلاحية القسم ($cfg['perm']).
 *
 * تعريف الحقل:
 *   ['name'=>'title','label'=>'العنوان','type'=>'text|textarea|number|date|url|select|checkbox|image|icon','req'=>true,
 *    'options'=>[...], 'hint'=>'...', 'max'=>255]
 * نوع image بيستخدم عمودين: {name}_path (رفع) و {name}_url (لينك)
 *
 * إعدادات الصفحة الإضافية (اختيارية):
 *   perm · icon · en (عنوان إنجليزي) · view (table|cards) · card (دالة رسم الكارت) · filters ([col => [label, [val=>label]]])
 *   title_col (العمود اللي بيظهر في السجل) · search_ph
 */
require_once __DIR__ . '/auth.php';
sa_require();
// أعمدة الرئيسية الجديدة (الشارة · النقاط · كود الخصم …) لازم تكون موجودة قبل أي حفظ
require_once dirname(__DIR__) . '/site/home.php';
s_home_upgrade();

/** اسم العنصر للسجل */
function crud_label(array $cfg, ?array $row): string
{
    if (!$row) return '';
    $c = $cfg['title_col'] ?? ($cfg['list_cols'][0][0] ?? 'title');
    $v = trim((string) ($row[$c] ?? $row['title'] ?? $row['name'] ?? ''));
    return $v !== '' ? '«' . mb_substr($v, 0, 80) . '»' : '#' . (int) ($row['id'] ?? 0);
}

function crud_run(array $cfg): array
{
    $table  = $cfg['table'];
    $fields = $cfg['fields'];
    $self   = basename($_SERVER['SCRIPT_NAME']);
    $sec    = $cfg['log_section'] ?? ($cfg['perm'] ?? $table);
    $hasSort = (bool) array_filter($fields, fn($f) => $f['name'] === 'sort_order') || in_array($table, ['site_gallery'], true);
    $toggleCol = $cfg['toggle_col'] ?? 'is_active';

    if (!empty($cfg['perm'])) sa_require_perm($cfg['perm']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ajax = sa_is_ajax();
        $ajax ? sa_check_csrf_json() : s_check_csrf();
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
                    // لينك خارجي (أو من مكتبة الوسائط)
                    $urlCol = $n . '_url';
                    $link = trim((string) ($_POST[$urlCol] ?? ''));
                    if ($link !== '' && !preg_match('~^(https?://|/)~i', $link)) {
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
                    // الترتيب: عنصر جديد من غير ترتيب ← آخر القائمة
                    $v = (int) ($_POST[$n] ?? 0);
                    if ($n === 'sort_order' && !$id && $v === 0) {
                        $v = (int) (s_one("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM `{$table}`")['n'] ?? 1);
                    }
                    if (isset($f['min'])) $v = max((int) $f['min'], $v);
                    if (isset($f['maxv'])) $v = min((int) $f['maxv'], $v);
                    $cols[] = "`{$n}`";
                    $vals[] = $v;
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
                if (($f['type'] ?? '') === 'select' && isset($f['options']) && !array_key_exists($v, $f['options'])) {
                    $v = (string) array_key_first($f['options']);
                }
                $max = (int) ($f['max'] ?? 1000);
                $cols[] = "`{$n}`";
                $vals[] = $v !== '' ? mb_substr($v, 0, $max) : (!empty($f['not_null']) ? '' : null);
            }

            if ($id > 0) {
                $set = implode(', ', array_map(fn($c) => $c . ' = ?', $cols));
                $vals[] = $id;
                s_run("UPDATE `{$table}` SET {$set} WHERE id = ?", $vals);
                sa_log('update', $sec, 'تعديل ' . ($cfg['item'] ?? 'عنصر') . ' ' . crud_label($cfg, s_one("SELECT * FROM `{$table}` WHERE id = ?", [$id])) . ' في «' . $cfg['title'] . '»', $id);
                if (empty($_SESSION['site_flash'])) s_flash('success', 'تم الحفظ ✓');
            } else {
                $ph = implode(', ', array_fill(0, count($cols), '?'));
                $newId = s_insert("INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES ({$ph})", $vals);
                if ($newId) {
                    sa_log('create', $sec, 'إضافة ' . ($cfg['item'] ?? 'عنصر') . ' ' . crud_label($cfg, s_one("SELECT * FROM `{$table}` WHERE id = ?", [$newId])) . ' في «' . $cfg['title'] . '»', $newId);
                    if (empty($_SESSION['site_flash'])) s_flash('success', 'تمت الإضافة ✓');
                } else {
                    s_flash('danger', 'تعذّر الحفظ — راجع البيانات (ممكن الاسم مكرر).');
                }
            }
            s_redirect('site-admin/' . $self);
        }

        /* ─── إظهار/إخفاء ─── */
        if ($action === 'toggle' || $action === 'toggle_ajax') {
            $row = s_one("SELECT * FROM `{$table}` WHERE id = ?", [$id]);
            if (!$row) { $ajax ? sa_json(['ok' => false, 'error' => 'العنصر مش موجود']) : s_redirect('site-admin/' . $self); }
            $on = $action === 'toggle_ajax' && isset($_POST['on']) ? (int) !empty($_POST['on']) : 1 - (int) $row[$toggleCol];
            s_run("UPDATE `{$table}` SET `{$toggleCol}` = ? WHERE id = ?", [$on, $id]);
            sa_log('toggle', $sec, ($on ? 'إظهار ' : 'إخفاء ') . ($cfg['item'] ?? 'عنصر') . ' ' . crud_label($cfg, $row) . ' في «' . $cfg['title'] . '»', $id);
            if ($ajax) sa_json(['ok' => true, 'on' => $on, 'message' => $on ? 'ظاهر في الموقع ✓' : 'اتخفى من الموقع']);
            s_redirect('site-admin/' . $self);
        }

        /* ─── نسخ ─── */
        if ($action === 'duplicate') {
            $row = s_one("SELECT * FROM `{$table}` WHERE id = ?", [$id]);
            if ($row) {
                unset($row['id'], $row['created_at'], $row['updated_at']);
                foreach ($fields as $f) {
                    if (($f['type'] ?? '') === 'image') $row[$f['name'] . '_path'] = null; // الملف مايتشاركش — اللينك بس
                }
                $uniq = $cfg['unique_col'] ?? null;
                $tc = $cfg['title_col'] ?? ($cfg['list_cols'][0][0] ?? null);
                if ($tc && isset($row[$tc]) && is_string($row[$tc])) $row[$tc] = mb_substr($row[$tc] . ' (نسخة)', 0, 240);
                if ($uniq && isset($row[$uniq]) && $uniq !== $tc) $row[$uniq] .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
                if (array_key_exists($toggleCol, $row)) $row[$toggleCol] = 0; // النسخة بتبدأ مخفية
                if (array_key_exists('sort_order', $row)) $row['sort_order'] = (int) (s_one("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM `{$table}`")['n'] ?? 1);
                $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
                $newId = s_insert("INSERT INTO `{$table}` (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
                if ($newId) {
                    sa_log('duplicate', $sec, 'نسخ ' . ($cfg['item'] ?? 'عنصر') . ' ' . crud_label($cfg, s_one("SELECT * FROM `{$table}` WHERE id = ?", [$id])) . ' في «' . $cfg['title'] . '»', $newId);
                    s_flash('success', 'اتعملت نسخة (مخفية لحد ما تظهرها) ✓');
                    s_redirect('site-admin/' . $self . '?edit=' . $newId);
                }
                s_flash('danger', 'تعذّر النسخ');
            }
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
                sa_log('delete', $sec, 'حذف ' . ($cfg['item'] ?? 'عنصر') . ' ' . crud_label($cfg, $row) . ' من «' . $cfg['title'] . '»', $id);
                s_flash('success', 'تم الحذف');
            }
            s_redirect('site-admin/' . $self);
        }

        /* ─── ترتيب (الفورم القديم: أرقام) ─── */
        if ($action === 'reorder') {
            foreach (($_POST['order'] ?? []) as $rid => $ord) {
                s_run("UPDATE `{$table}` SET sort_order = ? WHERE id = ?", [(int) $ord, (int) $rid]);
            }
            sa_log('reorder', $sec, 'إعادة ترتيب «' . $cfg['title'] . '»');
            s_flash('success', 'تم حفظ الترتيب ✓');
            s_redirect('site-admin/' . $self);
        }

        /* ─── ترتيب بالسحب / الأسهم (الترتيب الجديد كامل) ─── */
        if ($action === 'reorder_ajax') {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
            foreach ($ids as $i => $rid) {
                s_run("UPDATE `{$table}` SET sort_order = ? WHERE id = ?", [$i + 1, $rid]);
            }
            sa_log('reorder', $sec, 'إعادة ترتيب «' . $cfg['title'] . '»');
            sa_json(['ok' => true, 'message' => 'تم حفظ الترتيب ✓']);
        }

        /* ─── تحريك عنصر خطوة (من غير JS) ─── */
        if ($action === 'move') {
            $ids = array_map('intval', array_column(s_all("SELECT id FROM `{$table}` ORDER BY sort_order, id"), 'id'));
            $pos = array_search($id, $ids, true);
            $to = $pos === false ? false : $pos + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
            if ($pos !== false && isset($ids[$to])) {
                [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
                foreach ($ids as $i => $rid) s_run("UPDATE `{$table}` SET sort_order = ? WHERE id = ?", [$i + 1, $rid]);
                sa_log('reorder', $sec, 'إعادة ترتيب «' . $cfg['title'] . '»');
            }
            s_redirect('site-admin/' . $self);
        }
    }

    $editing = null;
    if (!empty($_GET['edit'])) {
        $editing = s_one("SELECT * FROM `{$table}` WHERE id = ?", [(int) $_GET['edit']]);
    }
    $order = $cfg['order'] ?? 'sort_order, id';
    $rows = s_all("SELECT * FROM `{$table}` ORDER BY {$order}");

    return ['rows' => $rows, 'editing' => $editing, 'new' => !empty($_GET['new']), 'sortable' => $hasSort && ($cfg['order'] ?? 'sort_order, id') === 'sort_order, id'];
}

/** رسم فورم الإضافة/التعديل (درج جانبي على الديسكتوب · شيت من تحت على الموبايل) */
function crud_form(array $cfg, ?array $editing, bool $open = false): void
{
    $fields = $cfg['fields'];
    $hasImage = (bool) array_filter($fields, fn($f) => ($f['type'] ?? '') === 'image');
    $title = $editing ? 'تعديل ' . ($cfg['item'] ?? '') . ' ' . crud_label($cfg, $editing) : ($cfg['add_label'] ?? 'إضافة جديد');
    echo sa_drawer_open('crud', $title, $open || (bool) $editing, $hasImage ? 'enctype="multipart/form-data"' : '');
    echo s_csrf_field() . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">';
    echo '<div class="ad-form one">';
    foreach ($fields as $f) {
        $type = $f['type'] ?? 'text';
        if ($f['name'] === 'sort_order' && !empty($cfg['hide_sort_field'])) continue;
        $val = $editing ? ($editing[$f['name']] ?? '') : ($type === 'checkbox' ? !empty($f['default']) : ($f['default'] ?? ''));
        if ($f['name'] === 'sort_order') $f['hint'] = $f['hint'] ?? 'سيبه 0 للإضافة في الآخر — أو رتّب بالسحب من القائمة.';
        echo sa_field($f, $val, $editing);
    }
    echo '</div>';
    echo sa_drawer_close($editing ? 'حفظ التعديلات' : 'إضافة');
}

/** نموذج POST صغير لزرار إجراء */
function crud_action_form(int $id, string $action, string $icon, string $label, string $extra = '', string $confirm = '', string $cls = ''): string
{
    return '<form method="POST"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . s_csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="id" value="' . $id . '">' . $extra
        . '<button type="submit" class="ad-ib ' . e($cls) . '" title="' . e($label) . '" aria-label="' . e($label) . '">' . sa_icon($icon, 17) . '</button></form>';
}

/** أزرار العنصر: فوق/تحت · تعديل · نسخ · حذف */
function crud_row_actions(array $cfg, array $r, bool $sortable, int $i, int $n): string
{
    $id = (int) $r['id'];
    $h = '<div class="ad-acts-in">';
    if ($sortable) {
        $h .= '<button type="button" class="ad-ib" data-move="up" aria-label="لفوق" title="لفوق"' . ($i === 0 ? ' aria-disabled="true"' : '') . '>' . sa_icon('up', 17) . '</button>';
        $h .= '<button type="button" class="ad-ib" data-move="down" aria-label="لتحت" title="لتحت"' . ($i === $n - 1 ? ' aria-disabled="true"' : '') . '>' . sa_icon('down', 17) . '</button>';
    }
    $h .= '<a class="ad-ib" href="?edit=' . $id . '" title="تعديل" aria-label="تعديل">' . sa_icon('edit', 17) . '</a>';
    if (empty($cfg['no_duplicate'])) $h .= crud_action_form($id, 'duplicate', 'copy', 'نسخ');
    $h .= crud_action_form($id, 'delete', 'trash', 'حذف', '', 'هتحذف ' . ($cfg['item'] ?? 'العنصر') . ' ' . crud_label($cfg, $r) . ' نهائيًا — متأكد؟', 'del');
    return $h . '</div>';
}

/** خلية قيمة في الجدول */
function crud_cell(array $c, array $r): string
{
    $v = (string) ($r[$c[0]] ?? '');
    if ($c[0] === 'icon') return function_exists('s_icon') ? '<span class="ad-thumb ph" style="width:38px;height:38px;border-radius:11px">' . s_icon($v ?: 'sparkle', 18) . '</span>' : e($v);
    if (($c[3] ?? '') === 'date') return $v ? e(date('Y/m/d', strtotime($v))) : '—';
    if ($v === '') return '<span class="t-num">—</span>';
    $max = (int) ($c[2] ?? 60);
    return e(mb_substr($v, 0, $max)) . (mb_strlen($v) > $max ? '…' : '');
}

/** رسم قائمة العناصر (جدول أو كروت) */
function crud_table(array $cfg, array $rows, bool $sortable = true): void
{
    $cols = $cfg['list_cols'];
    $imgField = null;
    foreach ($cfg['fields'] as $f) {
        if (($f['type'] ?? '') === 'image') { $imgField = $f['name']; break; }
    }
    $toggleCol = $cfg['toggle_col'] ?? 'is_active';
    $hasToggle = $rows ? array_key_exists($toggleCol, $rows[0]) : true;
    $n = count($rows);

    // شريط البحث والفلاتر
    $filters = '';
    if ($hasToggle) {
        $filters .= '<select class="ad-filter" data-filter-select="st" aria-label="الحالة"><option value="">كل الحالات</option><option value="on">ظاهر</option><option value="off">مخفي</option></select>';
    }
    foreach (($cfg['filters'] ?? []) as $col => [$lbl, $opts]) {
        $filters .= '<select class="ad-filter" data-filter-select="f-' . e($col) . '" aria-label="' . e($lbl) . '"><option value="">' . e($lbl) . ': الكل</option>';
        foreach ($opts as $ov => $ol) $filters .= '<option value="' . e($ov) . '">' . e($ol) . '</option>';
        $filters .= '</select>';
    }
    echo sa_search_bar($cfg['search_ph'] ?? ('ابحث في ' . $cfg['title'] . '...'), $n, $cfg['unit'] ?? 'عنصر',
        sa_btn($cfg['add_label'] ?? 'إضافة', 'pri', '?new=1', 'plus', ['data-open-drawer' => 'crud']), $filters, (string) ($_GET['q'] ?? ''));

    if (!$rows) {
        echo sa_empty($cfg['icon'] ?? 'grid', 'لسه مفيش ' . ($cfg['list_label'] ?? 'عناصر'), $cfg['empty_hint'] ?? 'ابدأ بأول عنصر — هيظهر في الموقع على طول.',
            sa_btn($cfg['add_label'] ?? 'إضافة', 'pri', '?new=1', 'plus', ['data-open-drawer' => 'crud']));
        return;
    }

    $dataAttrs = function (array $r) use ($toggleCol, $cfg): string {
        $a = ' data-row data-id="' . (int) $r['id'] . '" data-st="' . (!isset($r[$toggleCol]) || $r[$toggleCol] ? 'on' : 'off') . '"';
        foreach (array_keys($cfg['filters'] ?? []) as $col) $a .= ' data-f-' . e($col) . '="' . e((string) ($r[$col] ?? '')) . '"';
        return $a;
    };

    /* ── كروت ── */
    if (($cfg['view'] ?? 'table') === 'cards' && isset($cfg['card']) && is_callable($cfg['card'])) {
        echo '<div class="ad-cards"' . ($sortable ? ' data-sortable' : '') . '>';
        foreach ($rows as $i => $r) {
            $off = isset($r[$toggleCol]) && !$r[$toggleCol];
            echo '<article class="ad-ic sa-in' . ($off ? ' off' : '') . '"' . $dataAttrs($r) . '>';
            echo ($cfg['card'])($r);
            echo '<div class="ad-ic-f">';
            if ($sortable) echo '<span class="ad-ib ad-grab" data-grab title="اسحب لإعادة الترتيب" aria-hidden="true">' . sa_icon('grip', 17) . '</span>';
            if ($hasToggle) echo sa_switch('t' . (int) $r['id'], !$off, '', ['data-toggle-id' => (string) (int) $r['id'], 'aria-label' => 'إظهار في الموقع']);
            echo '<span class="ad-sp"></span>' . crud_row_actions($cfg, $r, $sortable, $i, $n) . '</div></article>';
        }
        echo '</div>';
    } else {
        /* ── جدول ── */
        echo '<div class="ad-table-w"><table class="ad-table"><thead><tr>';
        if ($sortable) echo '<th style="width:36px"><span class="sr-only">ترتيب</span></th>';
        if ($imgField) echo '<th style="width:60px"><span class="sr-only">صورة</span></th>';
        foreach ($cols as $c) echo '<th>' . e($c[1]) . '</th>';
        if ($hasToggle) echo '<th>الظهور</th>';
        echo '<th style="text-align:left">إجراءات</th></tr></thead><tbody' . ($sortable ? ' data-sortable' : '') . '>';
        foreach ($rows as $i => $r) {
            $off = isset($r[$toggleCol]) && !$r[$toggleCol];
            echo '<tr class="' . ($off ? 'off' : '') . '"' . $dataAttrs($r) . '>';
            if ($sortable) echo '<td class="ad-grab-td"><span class="ad-ib ad-grab" data-grab title="اسحب لإعادة الترتيب">' . sa_icon('grip', 17) . '</span></td>';
            if ($imgField) {
                $im = s_img($r, $imgField . '_path', $imgField . '_url');
                echo '<td class="t-img">' . ($im ? '<img src="' . e($im) . '" class="ad-thumb" alt="" loading="lazy">' : '<span class="ad-thumb ph">' . sa_icon('image', 18) . '</span>') . '</td>';
            }
            $first = true;
            foreach ($cols as $c) {
                if ($c[0] === 'icon') { echo '<td class="t-hide-m">' . crud_cell($c, $r) . '</td>'; continue; }
                $cls = $first ? 't-first' : (!empty($c[4]) ? 't-hide-m' : '');
                echo '<td class="' . $cls . '" data-l="' . e($c[1]) . '">' . ($first ? '<span class="t-main">' . crud_cell($c, $r) . '</span>' : crud_cell($c, $r)) . '</td>';
                $first = false;
            }
            if ($hasToggle) {
                echo '<td class="t-chip" data-l="الظهور">' . sa_switch('t' . (int) $r['id'], !$off, '', ['data-toggle-id' => (string) (int) $r['id'], 'aria-label' => 'إظهار في الموقع']) . '</td>';
            }
            echo '<td class="ad-acts">' . crud_row_actions($cfg, $r, $sortable, $i, $n) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    if ($sortable) echo '<p class="ad-foot-hint">' . sa_icon('grip', 14) . 'اسحب العنصر من المقبض لإعادة الترتيب، أو استخدم الأسهم — الترتيب بيتحفظ تلقائيًا.</p>';
}

/** صفحة إدارة كاملة في سطر واحد */
function crud_page(array $cfg): void
{
    $res = crud_run($cfg);
    $__t = $cfg['title'];
    include __DIR__ . '/layout.php';
    echo sa_page_head($cfg['icon'] ?? 'grid', $cfg['title'], $cfg['en'] ?? '', $cfg['intro'] ?? '');
    if (!empty($cfg['before_list']) && is_callable($cfg['before_list'])) ($cfg['before_list'])($res);
    crud_table($cfg, $res['rows'], $res['sortable']);
    crud_form($cfg, $res['editing'], $res['new']);
    include __DIR__ . '/layout-end.php';
}
