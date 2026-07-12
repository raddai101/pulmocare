<?php

declare(strict_types=1);

function mail_send(string $to, string $subject, string $htmlBody): bool
{
    $from    = env('MAIL_FROM', 'noreply@pulmocare.local');
    $fromName= env('MAIL_FROM_NAME', 'PulmoCare IA');
    $headers = implode("\r\n", [
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "From: {$fromName} <{$from}>",
        "X-Mailer: PulmoCare-Mailer/1.0",
    ]);
    return mail($to, $subject, $htmlBody, $headers);
}

function mail_reset_password(string $to, string $name, string $token): bool
{
    $url     = env('APP_URL') . '/auth/forgot-password.php?token=' . urlencode($token);
    $subject = '🔐 Réinitialisation de votre mot de passe — PulmoCare';
    $body    = <<<HTML
    <div style="font-family:sans-serif;max-width:560px;margin:auto;padding:32px">
        <h2 style="color:#1e40af">PulmoCare IA</h2>
        <p>Bonjour Dr. <strong>{$name}</strong>,</p>
        <p>Vous avez demandé la réinitialisation de votre mot de passe. Cliquez sur le bouton ci-dessous :</p>
        <a href="{$url}" style="display:inline-block;margin:16px 0;padding:12px 24px;background:#1e40af;color:#fff;border-radius:6px;text-decoration:none">
            Réinitialiser mon mot de passe
        </a>
        <p style="color:#6b7280;font-size:13px">Ce lien expire dans 1 heure. Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.</p>
    </div>
    HTML;
    return mail_send($to, $subject, $body);
}

function mail_welcome(string $to, string $name): bool
{
    $subject = '✅ Bienvenue sur PulmoCare IA — Compte en cours de validation';
    $body    = <<<HTML
    <div style="font-family:sans-serif;max-width:560px;margin:auto;padding:32px">
        <h2 style="color:#1e40af">PulmoCare IA</h2>
        <p>Bonjour Dr. <strong>{$name}</strong>,</p>
        <p>Votre compte a bien été créé. Il sera activé après validation par un administrateur.</p>
        <p>Vous recevrez un email de confirmation dès que votre compte sera approuvé.</p>
        <p style="color:#6b7280;font-size:13px">L'équipe PulmoCare</p>
    </div>
    HTML;
    return mail_send($to, $subject, $body);
}

function mail_detection_report(string $to, string $name, array $detection): bool
{
    $badge   = ucfirst($detection['result_type']);
    $date    = html_format_date($detection['created_at']);
    $subject = "📋 Rapport d'analyse CT Scan — {$detection['patient_nom']} {$detection['patient_prenom']}";
    $body    = <<<HTML
    <div style="font-family:sans-serif;max-width:600px;margin:auto;padding:32px">
        <h2 style="color:#1e40af">PulmoCare IA — Rapport d'analyse</h2>
        <p>Dr. <strong>{$name}</strong>,</p>
        <table style="width:100%;border-collapse:collapse;margin:16px 0">
            <tr><td style="padding:8px;border:1px solid #e5e7eb">Patient</td>
                <td style="padding:8px;border:1px solid #e5e7eb">{$detection['patient_nom']} {$detection['patient_prenom']}</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb">Date</td>
                <td style="padding:8px;border:1px solid #e5e7eb">{$date}</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb">Résultat</td>
                <td style="padding:8px;border:1px solid #e5e7eb"><strong>{$badge}</strong></td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb">Confiance</td>
                <td style="padding:8px;border:1px solid #e5e7eb">{$detection['confidence_score']}%</td></tr>
        </table>
        <p style="color:#6b7280;font-size:12px">Ce rapport est généré automatiquement. Consultez la plateforme pour plus de détails.</p>
    </div>
    HTML;
    return mail_send($to, $subject, $body);
}
