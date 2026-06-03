<?php
// Logger paramétrable. Niveaux : error (0) > warn (1) > info (2) > debug (3).
// CONFIG.log.level (default 'error') filtre ce qui est écrit.
// CONFIG.log.path (default 'data/app.log') indique le fichier de destination.
//
// Format une ligne : `2026-06-03T14:23:45+02:00 LEVEL message {context JSON}`
// Le context JSON est facultatif et omis s'il est vide.
//
// Usage :
//   log_error('SMTP connect failed', ['host' => $h, 'port' => $p, 'err' => $e]);
//   log_warn('Geocoder backend HTTP 403', ['query' => $q]);
//   log_info('Manager validated', ['id' => $mid]);
//   log_debug('SQL exec', ['stmt' => $sql]);
//
// Côté code appelant, à utiliser dans tout catch silencieux pour rendre
// l'erreur visible côté sysop sans casser le flux utilisateur·rice.

const LOG_LEVELS = [
    'error' => 0,
    'warn'  => 1,
    'info'  => 2,
    'debug' => 3,
];

function logger_threshold(): int {
    $lvl = (string)($GLOBALS['CONFIG']['log']['level'] ?? 'error');
    return LOG_LEVELS[$lvl] ?? 0;
}

function logger_path(): string {
    $p = (string)($GLOBALS['CONFIG']['log']['path'] ?? '');
    if ($p !== '') return $p;
    $dbp = (string)($GLOBALS['CONFIG']['db_path'] ?? '');
    $dir = $dbp !== '' ? dirname($dbp) : __DIR__ . '/../data';
    return $dir . '/app.log';
}

function mm_log(string $level, string $msg, array $context = []): void {
    $lv = LOG_LEVELS[$level] ?? 0;
    if ($lv > logger_threshold()) return; // niveau trop verbeux pour la config
    $path = logger_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        if (!is_dir($dir)) return; // tant pis, on évite de crasher
    }
    $line = date('c') . ' ' . strtoupper($level) . ' '
          . str_replace(["\r", "\n"], ' ', $msg);
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
}

function log_error(string $msg, array $context = []): void { mm_log('error', $msg, $context); }
function log_warn (string $msg, array $context = []): void { mm_log('warn',  $msg, $context); }
function log_info (string $msg, array $context = []): void { mm_log('info',  $msg, $context); }
function log_debug(string $msg, array $context = []): void { mm_log('debug', $msg, $context); }

/**
 * Formate un Throwable pour le context. Inclut classe, message, fichier, ligne.
 * On n'inclut pas la trace par défaut (trop verbeux pour error/warn).
 */
function log_throwable(Throwable $e): array {
    return [
        'class' => get_class($e),
        'msg'   => $e->getMessage(),
        'file'  => basename($e->getFile()) . ':' . $e->getLine(),
    ];
}

/**
 * Notifie l'admin par email d'une exception catchée silencieusement.
 * - Logge toujours en error
 * - Envoie un email à CONFIG.admin.email si configuré
 * - Anti-spam : 1 seule fois par (classe d'exception, message) toutes les
 *   heures (clé hashée stockée dans data/error-throttle/)
 * - Ne lance jamais d'exception elle-même (évite la cascade en cas de
 *   problème SMTP)
 *
 * @param Throwable $e
 * @param string $context_msg  description courte ("SMTP send failed", "Swap apply")
 * @param array  $context_data infos supplémentaires (to, request_id, etc)
 */
function notify_admin_error(Throwable $e, string $context_msg, array $context_data = []): void {
    $ctx = array_merge($context_data, log_throwable($e));
    log_error($context_msg, $ctx);

    // Toggle global : CONFIG.log.email_errors (défaut true). Permet de couper
    // l'envoi d'emails d'erreur en dev / pendant les migrations / quand on
    // bricolante, sans toucher au code.
    $email_enabled = $GLOBALS['CONFIG']['log']['email_errors'] ?? true;
    if (!$email_enabled) return;

    $admin_email = trim((string)($GLOBALS['CONFIG']['admin']['email'] ?? ''));
    if ($admin_email === '' || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        log_warn('admin.email not configured, error not emailed', ['msg' => $context_msg]);
        return;
    }

    // Anti-spam : marqueur fichier touch par (classe + message) sur 1h.
    $throttle_key = hash('sha256', get_class($e) . '|' . $e->getMessage() . '|' . $context_msg);
    $dbp = (string)($GLOBALS['CONFIG']['db_path'] ?? '');
    $dir = ($dbp !== '' ? dirname($dbp) : __DIR__ . '/../data') . '/error-throttle';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $marker = $dir . '/' . substr($throttle_key, 0, 16);
    if (is_file($marker) && (time() - filemtime($marker)) < 3600) {
        return; // déjà notifié dans la dernière heure
    }
    @touch($marker);

    $app_url = rtrim((string)($GLOBALS['CONFIG']['app_url'] ?? 'MMIrelay'), '/');
    $subject = '[MMIrelay] ⚠️ Erreur attrapée : ' . substr($context_msg, 0, 80);
    $body  = "Une exception a été attrapée par MMIrelay :\n\n";
    $body .= "  Contexte : $context_msg\n";
    $body .= "  Classe   : " . get_class($e) . "\n";
    $body .= "  Message  : " . $e->getMessage() . "\n";
    $body .= "  Fichier  : " . $e->getFile() . ':' . $e->getLine() . "\n";
    if ($context_data) {
        $body .= "  Context  : " . json_encode($context_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
    $body .= "\n  Trace (tronquée) :\n" . substr($e->getTraceAsString(), 0, 2000) . "\n\n";
    $body .= "URL app : $app_url\n";
    $body .= "\n— Anti-spam : message envoyé 1 fois par heure pour ce type d'erreur.\n";

    // Envoi direct via smtp_send pour éviter la récursion (send_mail catcherait
    // l'exception et nous rappellerait, boucle infinie en cas de panne SMTP).
    $cfg = $GLOBALS['CONFIG']['smtp'] ?? [];
    if (empty($cfg['host'])) {
        log_warn('SMTP host missing, error email skipped', ['msg' => $context_msg]);
        return;
    }
    try {
        smtp_send($cfg, $admin_email, $subject, $body);
    } catch (Throwable $send_e) {
        // SMTP est cassé aussi : on ne peut plus rien faire de mieux que logger.
        log_error('Failed to email admin about error', [
            'original' => $context_msg,
            'send_err' => $send_e->getMessage(),
        ]);
    }
}
