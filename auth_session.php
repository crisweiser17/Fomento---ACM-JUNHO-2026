<?php
/**
 * auth_session.php
 *
 * Bootstrap único de timezone + sessão.
 *
 * Precisa ser o MESMO em todas as páginas: quando login.php iniciava a sessão
 * com os padrões do PHP (gc_maxlifetime=1440, gc_probability=1%) enquanto
 * auth_check.php/processa_login.php usavam 86400 e gc desligado, o próprio
 * login podia coletar sessões que o resto do sistema considerava válidas —
 * derrubando o token CSRF e causando "Sessão expirada ou requisição inválida".
 */

require_once __DIR__ . '/timezones.php';

// --- Timezone global do sistema (configurável em config.json)
$authTzConfigPath = __DIR__ . '/config.json';
$authConfiguredTz = null;
if (file_exists($authTzConfigPath)) {
    $authCfg = json_decode(file_get_contents($authTzConfigPath), true);
    if (is_array($authCfg) && isset($authCfg['timezone'])) {
        $authConfiguredTz = (string) $authCfg['timezone'];
    }
}
date_default_timezone_set(resolveTimezone($authConfiguredTz));

// --- Sessão
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 86400); // 24 horas
    ini_set('session.gc_probability', 0);     // sem coleta automática
    ini_set('session.cookie_lifetime', 86400);
    ini_set('session.cookie_secure', 0);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);

    session_start();
}

/**
 * Impede cache de páginas com sessão/CSRF.
 *
 * Sem isso, proxies (a Cloudways usa Varnish na frente do nginx) e o próprio
 * navegador guardam o HTML do login e reentregam um token CSRF antigo, o que
 * derruba todo login com "requisição inválida".
 */
function enviarCabecalhosSemCache(): void
{
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}
