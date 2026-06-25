<?php
// installer.php - Script Interativo para instalação do banco de dados

// Verifica se o sistema já está instalado
$force_reinstall = isset($_POST['reinstall']) && $_POST['reinstall'] === '1';

if (file_exists(__DIR__ . '/db_connection.php') && !$force_reinstall) {
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador do Sistema</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="theme.css">
    <style>
        body { background-color: #f8f9fa; }
        .install-container { max-width: 600px; margin: 50px auto; }
        .card-header { background-color: #007cba; color: white; }
    </style>
</head>
<body>
    <div class="container install-container">
        <div class="card shadow-sm">
            <div class="card-header text-center py-3">
                <h2 class="h4 mb-0"><i class="bi bi-shield-check"></i> Instalação Concluída</h2>
            </div>
            <div class="card-body p-5 text-center">
                <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                <h3 class="mt-3">Sistema já instalado</h3>
                <p class="text-muted mt-3 mb-4">O arquivo de conexão com o banco de dados já existe. A instalação inicial não pode ser refeita para proteger seus dados.</p>
                <div class="d-grid gap-3">
                    <a href="update.php" class="btn btn-primary btn-lg"><i class="bi bi-arrow-clockwise"></i> Atualizar Banco de Dados</a>
                    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-right"></i> Ir para o Login</a>
                    <form method="POST" action="installer.php" class="d-grid">
                        <input type="hidden" name="reinstall" value="1">
                        <button type="submit" class="btn btn-outline-danger"><i class="bi bi-arrow-repeat"></i> Reinstalar / Corrigir Configuração</button>
                    </form>
                </div>
            </div>
            <div class="card-footer text-center text-muted py-3">
                <small>&copy; <?php echo date('Y'); ?> Factor System. Todos os direitos reservados.</small>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

set_time_limit(300); // 5 minutos de timeout
ini_set('memory_limit', '512M');

$step = 1;
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = $_POST['db_host'] ?? 'localhost';
    $db_user = $_POST['db_user'] ?? 'root';
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = $_POST['db_name'] ?? 'factor_db';
    $charset = 'utf8mb4';

    try {
        // Passo 1: Conectar ao banco de dados (já deve ter sido criado no painel da Cloudways/Hospedagem)
        $dsn = "mysql:host=$db_host;port=3306;dbname=$db_name;charset=$charset";
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // Passo 2: Importando estrutura e dados
        $sql_file = __DIR__ . '/banco_dump.sql.s';
        
        if (!file_exists($sql_file)) {
            throw new Exception("Arquivo de dump SQL não encontrado: $sql_file");
        }
        
        $sql_content = file_get_contents($sql_file);
        
        if ($sql_content === false) {
            throw new Exception("Erro ao ler o arquivo SQL");
        }

        // Dividir em statements individuais
        $statements = array_filter(
            array_map('trim', explode(';', $sql_content)),
            function($stmt) {
                return !empty($stmt) && !preg_match('/^\s*(--|\/\*|#)/', $stmt);
            }
        );
        
        $executed = 0;
        foreach ($statements as $statement) {
            // Ignorar comandos DROP DATABASE e CREATE DATABASE que vêm no dump do Adminer, 
            // pois já criamos o banco com o nome personalizado acima.
            if (stripos($statement, 'DROP DATABASE') !== false || stripos($statement, 'CREATE DATABASE') !== false || stripos($statement, 'USE `') !== false) {
                continue;
            }
            $pdo->exec($statement);
            $executed++;
        }

        // Passo 2.5: Garantir criação da tabela de usuários (Caso falhe pelo dump)
        $sqlUsuarios = "CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            senha_hash VARCHAR(255) NOT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sqlUsuarios);

        // Verifica se existe algum usuário, senão cria o admin padrão
        $stmtCheck = $pdo->query("SELECT COUNT(*) FROM usuarios");
        if ($stmtCheck->fetchColumn() == 0) {
            $senhaPadrao = password_hash('Qazwsx123@', PASSWORD_DEFAULT);
            $pdo->exec("INSERT INTO usuarios (email, senha_hash) VALUES ('admin', '$senhaPadrao')");
        }

        // Passo 3: Criando arquivo de conexão
        $db_connection_content = "<?php\n// db_connection.php - Gerado automaticamente pelo installer.php\n\n";
        $db_connection_content .= "\$db_host = '$db_host';\n";
        $db_connection_content .= "\$db_name = '$db_name';\n";
        $db_connection_content .= "\$db_user = '$db_user';\n";
        $db_connection_content .= "\$db_pass = '$db_pass';\n";
        $db_connection_content .= "\$charset = '$charset';\n\n";
        $db_connection_content .= "// Opções do PDO\n";
        $db_connection_content .= "\$options = [\n";
        $db_connection_content .= "    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n";
        $db_connection_content .= "    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n";
        $db_connection_content .= "    PDO::ATTR_EMULATE_PREPARES   => false,\n";
        $db_connection_content .= "];\n\n";
        $db_connection_content .= "// Data Source Name (DSN)\n";
        $db_connection_content .= "\$dsn = \"mysql:host=\$db_host;port=3306;dbname=\$db_name;charset=\$charset\";\n\n";
        $db_connection_content .= "try {\n";
        $db_connection_content .= "    \$pdo = new PDO(\$dsn, \$db_user, \$db_pass, \$options);\n";
        $db_connection_content .= "} catch (\\PDOException \$e) {\n";
        $db_connection_content .= "    error_log(\"Erro de Conexão BD: \" . \$e->getMessage());\n";
        $db_connection_content .= "    die(\"Erro ao conectar com o banco de dados. Tente novamente mais tarde.\");\n";
        $db_connection_content .= "}\n";
        $db_connection_content .= "?>";
        
        file_put_contents(__DIR__ . '/db_connection.php', $db_connection_content);
        
        $step = 2; // Instalação concluída
        $message = "Instalação concluída com sucesso! Banco de dados estruturado e arquivo de conexão gerado.";
        $messageType = "success";

    } catch (Exception $e) {
        $message = "Erro durante a instalação: " . htmlspecialchars($e->getMessage());
        $messageType = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador do Sistema</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="theme.css">
    <style>
        body { background-color: #f8f9fa; }
        .install-container { max-width: 600px; margin: 50px auto; }
        .card-header { background-color: #007cba; color: white; }
    </style>
</head>
<body>
    <div class="container install-container">
        <div class="card shadow-sm">
            <div class="card-header text-center py-3">
                <h2 class="h4 mb-0"><i class="bi bi-hdd-network"></i> Instalador do Sistema (MySQL)</h2>
            </div>
            <div class="card-body p-4">
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                    <?php if ($force_reinstall): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i> <strong>Atenção:</strong>
                            Você está no modo de <strong>reinstalação forçada</strong>. O arquivo de configuração atual será sobrescrito e o banco de dados será recriado a partir do dump. Certifique-se de que deseja prosseguir.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle-fill"></i> <strong>Atenção:</strong>
                            Crie um banco de dados vazio no painel da sua hospedagem (ex: Cloudways) antes de prosseguir. Este instalador irá carregar a estrutura inicial nele (<code>banco_dump.sql.s</code>).
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="installer.php">
                        <?php if ($force_reinstall): ?>
                            <input type="hidden" name="reinstall" value="1">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="confirm_reinstall" required>
                                <label class="form-check-label" for="confirm_reinstall">
                                    Entendo que isso sobrescreverá a configuração atual e desejo continuar.
                                </label>
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label for="db_host" class="form-label">Servidor MySQL (Host)</label>
                            <input type="text" class="form-control" id="db_host" name="db_host" value="localhost" required>
                            <div class="form-text">Geralmente é 'localhost' ou o IP do servidor do banco.</div>
                        </div>
                        <div class="mb-3">
                            <label for="db_user" class="form-label">Usuário do Banco</label>
                            <input type="text" class="form-control" id="db_user" name="db_user" value="root" required>
                        </div>
                        <div class="mb-3">
                            <label for="db_pass" class="form-label">Senha do Banco</label>
                            <input type="password" class="form-control" id="db_pass" name="db_pass">
                            <div class="form-text">Deixe em branco se for um servidor local sem senha (como XAMPP/WAMP padrão).</div>
                        </div>
                        <div class="mb-3">
                            <label for="db_name" class="form-label">Nome do Banco de Dados</label>
                            <input type="text" class="form-control" id="db_name" name="db_name" value="factor_db" required>
                            <div class="form-text">Nome do banco de dados que você já criou no painel da sua hospedagem.</div>
                        </div>
                        
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-play-circle"></i> Iniciar Instalação</button>
                        </div>
                    </form>
                <?php elseif ($step === 2): ?>
                    <div class="text-center mb-4">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                        <h3 class="mt-3">Tudo Pronto!</h3>
                        <p class="text-muted">O banco de dados foi configurado e o sistema está pronto para uso.</p>
                    </div>
                    
                    <div class="alert alert-danger">
                        <h5><i class="bi bi-shield-lock-fill"></i> Segurança: Ação Necessária</h5>
                        <p class="mb-0">Para proteger seu sistema, <strong>exclua este arquivo (<code>installer.php</code>)</strong> do servidor imediatamente.</p>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <a href="index.php" class="btn btn-success btn-lg"><i class="bi bi-box-arrow-in-right"></i> Acessar o Sistema</a>
                    </div>
                <?php endif; ?>
                
            </div>
            <div class="card-footer text-center text-muted py-3">
                <small>&copy; <?php echo date('Y'); ?> Factor System. Todos os direitos reservados.</small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
