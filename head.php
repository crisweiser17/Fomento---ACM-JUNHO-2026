<?php
/**
 * head.php — Cabeçalho HTML compartilhado.
 *
 * Centraliza <head> (CDNs, theme.css, style.css), abre <body> e inclui o
 * navbar (menu.php). Substitui o <head> duplicado em cada tela.
 *
 * Variáveis opcionais (definir ANTES do require):
 *   $pageTitle  string  Título da aba (default: app_name do config).
 *   $bodyClass  string  Classes extras no <body> (ex.: 'sim-page-body').
 *                       'app-page' é sempre aplicado.
 *   $headExtra  string  HTML cru injetado no fim do <head> (CSS/JS da tela).
 *   $withChart  bool    Carrega Chart.js + plugin datalabels (default false).
 *   $noMenu     bool    Não renderiza o navbar (ex.: login/installer).
 */

$pageTitle = $pageTitle ?? null;
$bodyClass = trim('app-page ' . ($bodyClass ?? ''));
$headExtra = $headExtra ?? '';
$withChart = $withChart ?? false;
$noMenu    = $noMenu ?? false;

// Título: usa o informado ou cai no app_name do config.json
$headConfigPath = __DIR__ . '/config.json';
$headAppCfg = file_exists($headConfigPath) ? (json_decode(file_get_contents($headConfigPath), true) ?: []) : [];
$resolvedTitle = $pageTitle !== null
    ? $pageTitle
    : ($headAppCfg['app_name'] ?? 'FACTOR');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($resolvedTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php if ($withChart): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <?php endif; ?>
    <link rel="stylesheet" href="theme.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="app.js" defer></script>
    <?php echo $headExtra; ?>
</head>
<body class="<?php echo htmlspecialchars($bodyClass); ?>">
<?php if (!$noMenu) { require __DIR__ . '/menu.php'; } ?>
