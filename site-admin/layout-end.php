    </main>
  </div>
</div>

<!-- نافذة التأكيد (حذف · عمليات مش بترجع) -->
<div class="ad-ov ad-confirm" data-confirm-modal hidden>
  <div class="ad-ov-bg" data-confirm-no></div>
  <div class="ad-modal" role="alertdialog" aria-modal="true" aria-labelledby="ad-cf-t" aria-describedby="ad-cf-b">
    <span class="ad-modal-ic"><?= sa_icon('trash', 24, 1.8) ?></span>
    <h2 id="ad-cf-t">متأكد؟</h2>
    <p id="ad-cf-b">العملية دي مش هترجع.</p>
    <div class="ad-modal-a"><button type="button" class="ad-btn ad-danger" data-confirm-yes>نعم، احذف</button><button type="button" class="ad-btn ad-sec" data-confirm-no>إلغاء</button></div>
  </div>
</div>

<!-- اختيار من مكتبة الوسائط -->
<div class="ad-ov" data-media-modal hidden>
  <div class="ad-ov-bg" data-media-close></div>
  <aside class="ad-drawer wide" role="dialog" aria-modal="true" aria-labelledby="ad-mm-t">
    <span class="ad-handle" aria-hidden="true"></span>
    <header class="ad-dh"><h2 id="ad-mm-t">مكتبة الوسائط</h2><button type="button" class="ad-ib" data-media-close aria-label="إغلاق"><?= sa_icon('x', 20) ?></button></header>
    <div class="ad-db"><div class="ad-media-grid sm" data-media-grid><p class="ad-hint">بيحمّل...</p></div></div>
  </aside>
</div>
<div class="ad-toasts" aria-live="polite" data-toasts></div>
<script>window.SA = <?= json_encode(['csrf' => s_csrf(), 'media' => 'media.php?json=1'], JSON_UNESCAPED_UNICODE) ?>;</script>
</body>
</html>
