<?php
// login.php
// Bootstrap único de sessão (o mesmo de auth_check/processa_login). Antes daqui
// o login iniciava a sessão com os padrões do PHP e podia coletar sessões que o
// resto do sistema considerava válidas, invalidando o token CSRF.
require_once __DIR__ . '/auth_session.php';

// O formulário carrega um token CSRF: não pode ser cacheado por proxy (Varnish
// na Cloudways) nem pelo navegador, senão reentrega um token velho e todo login
// falha com "Sessão expirada ou requisição inválida".
enviarCabecalhosSemCache();

// Se o usuário já está logado, redireciona para a página principal
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: index.php'); // Ou relatorio.php, ou a página que você preferir
    exit;
}

// Gera o token CSRF se não existir
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Verifica se há erro (vindo do processa_login.php)
$error_message = '';
if (isset($_GET['error'])) {
    if ($_GET['error'] == 1) {
        $error_message = "Usuário ou senha incorretos. Tente novamente.";
    } elseif ($_GET['error'] == 2) {
        $error_message = "Erro de sistema. Tente novamente mais tarde.";
    } elseif ($_GET['error'] == 3) {
        $error_message = "Sessão expirada ou requisição inválida. Tente novamente.";
    }
}

// Pega a URL de redirecionamento, se houver
$redirect_url = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';

?>
<?php
$pageTitle = 'Login';
$bodyClass = 'login-page';
$noMenu = true;
require_once 'head.php';
?>
    <style>
        body.login-page {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .login-card {
            max-width: 400px;
            width: 100%;
        }
    </style>
    <div class="card login-card shadow">
        <div class="card-body p-4">
            <h3 class="card-title text-center mb-4">Acesso Restrito</h3>
            <form action="processa_login.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo $error_message; ?></div>
                <?php endif; ?>
                
                <div class="mb-3">
                    <label for="email" class="form-label">Usuário / Email</label>
                    <input type="text" class="form-control" id="email" name="email" value="admin" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Senha</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect_url); ?>">
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Entrar</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
