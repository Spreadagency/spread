<?php
/**
 * زرار جوجل — يتضمّن في login.php و register.php
 * $googleIntent = 'login' | 'register'
 */
if (!function_exists('google_enabled')) {
    require_once __DIR__ . '/../includes/google-auth.php';
}
if (!google_enabled()) {
    return;
}
$__gi = ($googleIntent ?? 'login') === 'register' ? 'register' : 'login';
$__ref = function_exists('referral_active_code') ? referral_active_code() : '';
$__gurl = url('auth/google.php?intent=' . $__gi . ($__ref ? '&ref=' . urlencode($__ref) : ''));
?>
<div class="gauth">
    <a href="<?= e($__gurl) ?>" class="gbtn">
        <svg viewBox="0 0 48 48" width="19" height="19" aria-hidden="true">
            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24s.92 7.54 2.56 10.78l7.97-6.19z"/>
            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        <span><?= $__gi === 'register' ? 'التسجيل بحساب جوجل' : 'الدخول بحساب جوجل' ?></span>
    </a>
    <div class="gsep"><span>أو</span></div>
</div>

<style>
.gauth { margin-bottom: 18px; }
.gbtn {
    display: flex; align-items: center; justify-content: center; gap: 11px;
    width: 100%; padding: 12px 18px; border-radius: 12px;
    border: 1.5px solid var(--line, #e3e1dd); background: #fff; color: #1f1f1f;
    font-family: inherit; font-size: 14.5px; font-weight: 600; text-decoration: none;
    transition: background .25s, border-color .25s, box-shadow .25s;
}
.gbtn:hover { background: #f7f8fa; border-color: #c9c7c3; box-shadow: 0 2px 10px -4px rgba(0,0,0,.18); }
.gbtn svg { flex: none; }
.gsep { display: flex; align-items: center; gap: 12px; margin: 16px 0 0; color: var(--mute, #8b8983); font-size: 12.5px; }
.gsep::before, .gsep::after { content: ""; flex: 1; height: 1px; background: var(--line, #e3e1dd); }
</style>
