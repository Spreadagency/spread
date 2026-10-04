<?php
/**
 * Spread AI — Mailer
 * 
 * Uses PHP's built-in mail() for MVP. For production, replace with PHPMailer + SMTP.
 */

require_once __DIR__ . '/config.php';

/**
 * Send an email
 */
function send_mail(string $to, string $subject, string $htmlBody, string $altBody = ''): bool
{
    $boundary = md5(uniqid('', true));

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_FROM,
        'X-Mailer: SpreadAI/1.0',
    ];

    $altBody = $altBody ?: strip_tags($htmlBody);

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $altBody . "\r\n\r\n";

    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";

    $body .= "--$boundary--";

    // Encode subject in UTF-8
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    if (defined('MAIL_DRIVER') && MAIL_DRIVER === 'log') {
        // Dev mode: log emails to file instead of sending
        if (!is_dir(LOGS_PATH)) {
            @mkdir(LOGS_PATH, 0755, true);
        }
        // Metadata only: bodies contain verification/reset tokens
        $logFile = LOGS_PATH . '/mail.log';
        @file_put_contents(
            $logFile,
            date('Y-m-d H:i:s') . " — TO: $to | SUBJECT: $subject\n",
            FILE_APPEND
        );
        return true;
    }

    return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}

/**
 * Send verification email
 */
function send_verification_email(string $name, string $email, string $token): bool
{
    $verifyUrl = APP_URL . '/verify-email.php?token=' . urlencode($token);
    $subject = 'فعّل حسابك — Spread AI';
    $body = build_email_template(
        'مرحبًا ' . htmlspecialchars($name) . ' 👋',
        'يا أهلًا بك في Spread AI! اضغط على الزر اللي تحت لتفعيل حسابك والبدء في إنشاء المحتوى.',
        'تفعيل الحساب',
        $verifyUrl
    );
    return send_mail($email, $subject, $body);
}

/**
 * Send password reset email
 */
function send_reset_email(string $name, string $email, string $token): bool
{
    $resetUrl = APP_URL . '/reset-password.php?token=' . urlencode($token);
    $subject = 'إعادة تعيين كلمة المرور — Spread AI';
    $body = build_email_template(
        'مرحبًا ' . htmlspecialchars($name),
        'استلمنا طلب لإعادة تعيين كلمة المرور لحسابك. اضغط على الزر التالي خلال ساعة لإعادة تعيين كلمة المرور.',
        'إعادة تعيين كلمة المرور',
        $resetUrl
    );
    return send_mail($email, $subject, $body);
}

/**
 * Email template (HTML)
 */
function build_email_template(string $heading, string $message, string $btnLabel, string $btnUrl): string
{
    return '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background: #f3f1fb; padding: 24px; margin: 0;">
  <div style="max-width: 540px; margin: 0 auto; background: #fff; border-radius: 24px; padding: 32px; box-shadow: 0 8px 24px rgba(76,63,168,0.08);">
    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px; justify-content: center;">
      <div style="width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #7c6df2, #a89bff); color: #fff; display: inline-block; line-height: 48px; text-align: center; font-weight: bold; font-size: 20px;">S</div>
    </div>
    <h2 style="margin: 0 0 12px; color: #1e1b3a; font-size: 22px; text-align: right;">' . $heading . '</h2>
    <p style="color: #4a4670; line-height: 1.8; font-size: 14px; text-align: right;">' . $message . '</p>
    <div style="text-align: center; margin: 28px 0;">
      <a href="' . htmlspecialchars($btnUrl) . '" style="background: #7c6df2; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 12px; font-weight: 500; display: inline-block;">' . htmlspecialchars($btnLabel) . '</a>
    </div>
    <p style="color: #8b88a8; font-size: 12px; margin: 20px 0 0; text-align: right;">لو الزر مش شغال، انسخ الرابط ده في المتصفح:<br><span style="word-break: break-all; color: #7c6df2;">' . htmlspecialchars($btnUrl) . '</span></p>
    <hr style="border: none; border-top: 1px solid #ece9f7; margin: 24px 0;">
    <p style="color: #8b88a8; font-size: 11px; text-align: center; margin: 0;">Spread AI · منصة المحتوى بالذكاء الاصطناعي</p>
  </div>
</body>
</html>';
}
