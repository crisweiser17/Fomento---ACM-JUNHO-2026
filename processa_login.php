<?php
// processa_login.php - CORRIGIDO PARA CLOUDWAYS/NGINX E BANCO DE DADOS
// Bootstrap único de sessão, compartilhado com auth_check.php e login.php.
require_once __DIR__ . '/auth_session.php';
enviarCabecalhosSemCache();

require_once 'db_connection.php'; // Inclui conexão com o banco

// URL padrão para redirecionar após login
$redirect_url_default = 'index.php'; // Ou relatorio.php
$redirect_url = isset($_POST['redirect']) && !empty($_POST['redirect']) ? $_POST['redirect'] : $redirect_url_default;

// Verifica se a requisição é POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Verifica o Token CSRF
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $redirect_param = isset($_POST['redirect']) ? '?redirect=' . urlencode($_POST['redirect']) . '&error=3' : '?error=3';
        header("Location: login.php" . $redirect_param);
        exit;
    }

    if (isset($_POST['password'])) {
        $senha_digitada = $_POST['password'];
    $email = isset($_POST['email']) ? $_POST['email'] : 'admin'; // Padrão admin se não vier o campo email

    try {
        // Busca o hash da senha no banco
        $stmt = $pdo->prepare("SELECT id, senha_hash FROM usuarios WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $usuario = $stmt->fetch();

        // Verifica se o usuário existe e a senha bate com o hash
        if ($usuario && password_verify($senha_digitada, $usuario['senha_hash'])) {
            // Senha correta! Regenera o ID da sessão por segurança
            session_regenerate_id(true);

            // Define as variáveis de sessão
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $usuario['id'];
            $_SESSION['user_email'] = $email;
            $_SESSION['login_timestamp'] = time();
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            // FORÇA a gravação da sessão
            session_write_close();
            session_start();

            // Redireciona para a página de onde o usuário veio ou para a padrão
            header("Location: " . $redirect_url);
            exit;
        } else {
            // Senha incorreta - redireciona de volta para login com erro
            $redirect_param = isset($_POST['redirect']) ? '?redirect=' . urlencode($_POST['redirect']) . '&error=1' : '?error=1';
            header("Location: login.php" . $redirect_param);
            exit;
        }
    } catch (PDOException $e) {
            error_log("Erro no login: " . $e->getMessage());
            header("Location: login.php?error=2"); // Erro de banco
            exit;
        }
    } else {
        // Senha não enviada
        header("Location: login.php");
        exit;
    }
} else {
    // Se não foi POST, redireciona para login
    header("Location: login.php");
    exit;
}
?>
