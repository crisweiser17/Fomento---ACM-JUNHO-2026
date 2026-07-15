<?php
/**
 * redefinir_senha.php
 * -----------------------------------------------------------------------------
 * Utilitário one-off para redefinir a senha de um usuário direto no servidor.
 *
 * Necessário porque o hash gravado em `usuarios` pode não corresponder a
 * nenhuma senha conhecida (o login ficou muito tempo desativado por bypass,
 * então a senha nunca era verificada de verdade).
 *
 * Uso:
 *   1. Suba este arquivo na raiz do sistema (ao lado de db_connection.php).
 *   2. Acesse https://SEU-DOMINIO/redefinir_senha.php
 *   3. Informe o e-mail/login e a nova senha e confirme.
 *   4. APAGUE este arquivo do servidor imediatamente.
 *
 * A senha NÃO fica escrita neste arquivo — é digitada no formulário e gravada
 * como hash bcrypt. Nada é registrado em log.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/db_connection.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mensagem = null;
$erro     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = trim($_POST['email'] ?? '');
        $senha = (string) ($_POST['senha'] ?? '');

        if ($email === '') {
            throw new InvalidArgumentException('Informe o e-mail/login.');
        }
        if (strlen($senha) < 4) {
            throw new InvalidArgumentException('A senha precisa ter ao menos 4 caracteres.');
        }

        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException("Usuário \"{$email}\" não encontrado.");
        }

        $hash = password_hash($senha, PASSWORD_BCRYPT);
        $upd  = $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE email = ?');
        $upd->execute([$hash, $email]);

        // Confere que o hash gravado realmente valida a senha informada.
        $stmt = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if (!password_verify($senha, (string) $stmt->fetchColumn())) {
            throw new RuntimeException('A senha foi gravada mas não validou. Nada foi confirmado.');
        }

        $mensagem = "Senha de \"{$email}\" redefinida com sucesso. Já pode fazer login. "
                  . 'APAGUE este arquivo do servidor agora.';
    } catch (InvalidArgumentException $e) {
        $erro = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Erro ao redefinir senha: ' . $e->getMessage());
        $erro = 'Erro ao redefinir a senha.';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Redefinir senha</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 26rem; margin: 3rem auto; padding: 0 1rem; }
        label { display: block; margin: 1rem 0 .25rem; font-weight: 600; }
        input { width: 100%; padding: .5rem; font-size: 1rem; }
        button { margin-top: 1.25rem; padding: .6rem 1.2rem; font-size: 1rem; cursor: pointer; }
        .ok { background: #e6f4ea; border: 1px solid #34a853; padding: .75rem; }
        .erro { background: #fce8e6; border: 1px solid #d93025; padding: .75rem; }
        .aviso { background: #fef7e0; border: 1px solid #f9ab00; padding: .75rem; margin-top: 1.5rem; }
    </style>
</head>
<body>
    <h1>Redefinir senha</h1>

    <?php if ($mensagem): ?>
        <p class="ok"><?php echo htmlspecialchars($mensagem); ?></p>
    <?php endif; ?>
    <?php if ($erro): ?>
        <p class="erro"><?php echo htmlspecialchars($erro); ?></p>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <label for="email">E-mail / login</label>
        <input type="text" id="email" name="email" value="admin" required>

        <label for="senha">Nova senha</label>
        <input type="password" id="senha" name="senha" required>

        <button type="submit">Redefinir</button>
    </form>

    <p class="aviso">
        <strong>Apague este arquivo do servidor</strong> assim que terminar. Enquanto ele
        existir, qualquer pessoa que souber a URL pode trocar a senha de acesso.
    </p>
</body>
</html>
