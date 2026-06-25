<?php
require_once 'auth_check.php';
require_once 'db_connection.php';

$id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
$usuario = null;
$is_edit = false;

if ($id) {
    try {
        $stmt = $pdo->prepare("SELECT id, email FROM usuarios WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario) {
            $is_edit = true;
        } else {
            header("Location: listar_usuarios.php?status=error&msg=" . urlencode("Usuário não encontrado."));
            exit;
        }
    } catch (PDOException $e) {
        header("Location: listar_usuarios.php?status=error&msg=" . urlencode("Erro ao buscar usuário: " . $e->getMessage()));
        exit;
    }
}
?>
<?php
$pageTitle = $is_edit ? 'Editar Usuário' : 'Novo Usuário';
require_once 'head.php';
?>

    <div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><?php echo $is_edit ? 'Alterar Senha do Usuário' : 'Criar Novo Usuário'; ?></h4>
                    </div>
                    <div class="card-body">
                        <form action="salvar_usuario.php" method="POST" id="formUsuario" data-no-loading="1">
                            <?php if ($is_edit): ?>
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($usuario['id']); ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="email" class="form-label">E-mail / Usuário</label>
                                <input type="text" class="form-control" id="email" name="email" 
                                    value="<?php echo $is_edit ? htmlspecialchars($usuario['email']) : ''; ?>" 
                                    <?php echo $is_edit ? 'readonly' : 'required'; ?>>
                                <?php if ($is_edit): ?>
                                    <div class="form-text">O e-mail do usuário não pode ser alterado.</div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="senha" class="form-label"><?php echo $is_edit ? 'Nova Senha' : 'Senha'; ?></label>
                                <input type="password" class="form-control" id="senha" name="senha" required minlength="6">
                                <div class="form-text">A senha deve ter pelo menos 6 caracteres.</div>
                            </div>

                            <div class="mb-4">
                                <label for="confirmar_senha" class="form-label">Confirmar Senha</label>
                                <input type="password" class="form-control" id="confirmar_senha" name="confirmar_senha" required minlength="6">
                                <div id="senhaError" class="invalid-feedback">As senhas não coincidem.</div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="listar_usuarios.php" class="btn btn-outline-secondary">Cancelar</a>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Salvar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('formUsuario').addEventListener('submit', function(e) {
            var senha = document.getElementById('senha').value;
            var confirmar = document.getElementById('confirmar_senha').value;
            
            if (senha !== confirmar) {
                e.preventDefault();
                document.getElementById('confirmar_senha').classList.add('is-invalid');
            } else {
                document.getElementById('confirmar_senha').classList.remove('is-invalid');
            }
        });
        
        document.getElementById('confirmar_senha').addEventListener('input', function() {
            this.classList.remove('is-invalid');
        });
    </script>
</body>
</html>
