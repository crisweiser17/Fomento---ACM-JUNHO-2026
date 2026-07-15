<?php
// auth_check.php

// Timezone + sessão: bootstrap único, compartilhado com login.php e
// processa_login.php (configurações divergentes derrubavam o token CSRF).
require_once __DIR__ . '/auth_session.php';

// Ignora verificação de autenticação para CLI e recursos estáticos
$skip_auth = false;

// Verifica se está rodando via CLI
if (php_sapi_name() === 'cli') {
    $skip_auth = true;
    $current_uri = ''; // Inicializa como string vazia para CLI
} else {
    // Apenas o caminho: a query string não pode influenciar a liberação de
    // recursos estáticos (senão "pagina.php?x=.js" burlaria a autenticação).
    $current_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
}

// Lista de padrões de URL que não precisam de autenticação
$patterns_to_skip = [
    '/^\/\@vite\//', // Recursos do Vite
    '/\.css$/',      // Arquivos CSS
    '/\.js$/',       // Arquivos JavaScript
    '/\.png$/',      // Imagens PNG
    '/\.jpg$/',      // Imagens JPG
    '/\.jpeg$/',     // Imagens JPEG
    '/\.gif$/',      // Imagens GIF
    '/\.svg$/',      // Imagens SVG
    '/\.ico$/',      // Ícones
    '/\.woff2?$/',   // Fontes
    '/\.ttf$/',      // Fontes
    '/\.eot$/',      // Fontes
    '/\.otf$/'       // Fontes
];

// Verifica se a URL atual corresponde a algum dos padrões
foreach ($patterns_to_skip as $pattern) {
    if (preg_match($pattern, $current_uri)) {
        $skip_auth = true;
        break;
    }
}

// Verifica se a variável de sessão 'loggedin' não está definida ou não é true
if (!$skip_auth && (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true)) {
    // Se não estiver logado e não for CLI, redireciona para a página de login
    if (php_sapi_name() !== 'cli') {
        $redirect_url = urlencode($_SERVER['REQUEST_URI']);
        header("Location: login.php?redirect=" . $redirect_url);
        exit;
    }
}

// Define variáveis de sessão para testes CLI
if (php_sapi_name() === 'cli') {
    $_SESSION['loggedin'] = true;
    $_SESSION['user_id'] = 1;
}

// Se chegou até aqui, o usuário está logado ou a URL não precisa de autenticação. O script da página pode continuar.
?>
