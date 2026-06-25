<?php require_once 'auth_check.php'; ?>
<?php
require_once 'db_connection.php';

$pageTitle = 'Novo Lead';
$lead = [
    'id' => null,
    'empresa' => '',
    'nome_contato' => '',
    'telefone' => '',
    'origem' => 'receptivo',
    'estagio' => 'novo',
    'responsavel_id' => '',
    'data_visita_agendada' => '',
    'observacoes' => '',
];
$editMode = false;

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $editMode = true;
    $pageTitle = 'Editar Lead';
    try {
        $stmt = $pdo->prepare('SELECT * FROM leads WHERE id = :id');
        $stmt->execute([':id' => (int)$_GET['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            header('Location: listar_leads.php?status=error&msg=' . urlencode('Lead não encontrado.'));
            exit;
        }
        $lead = array_merge($lead, $row);
        if (!empty($lead['data_visita_agendada'])) {
            try {
                $lead['data_visita_agendada'] = (new DateTime($lead['data_visita_agendada']))->format('Y-m-d\TH:i');
            } catch (Exception $e) {
                $lead['data_visita_agendada'] = '';
            }
        }
    } catch (PDOException $e) {
        die('Erro ao carregar lead: ' . htmlspecialchars($e->getMessage()));
    }
}

try {
    $usuarios = $pdo->query('SELECT id, email FROM usuarios ORDER BY email')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $usuarios = [];
}

$alertStatus = $_GET['status'] ?? '';
$alertMessage = trim($_GET['msg'] ?? '');
?>
<?php
require_once 'head.php';
?>
    <style>
        .form-card {
            background: #fff; border: 1px solid #e3e8ef; border-radius: 14px;
            padding: 24px; max-width: 720px; margin: 0 auto;
        }
        .form-label-strong {
            font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em;
            color: #6c757d; font-weight: 600; margin-bottom: 6px;
        }
    </style>
    <div class="container px-3 px-md-4 mt-4" style="max-width: 1100px;">
        <div class="page-toolbar">
            <h1><i class="bi bi-person-plus-fill text-info"></i> <?php echo htmlspecialchars($pageTitle); ?></h1>
            <a href="listar_leads.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Voltar</a>
        </div>

        <?php if ($alertStatus === 'error' && $alertMessage !== ''): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($alertMessage); ?></div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="salvar_lead.php" autocomplete="off">
                <?php if ($editMode): ?>
                    <input type="hidden" name="id" value="<?php echo (int)$lead['id']; ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label-strong">Empresa *</label>
                        <input type="text" name="empresa" class="form-control" required
                               value="<?php echo htmlspecialchars($lead['empresa']); ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label-strong">Origem</label>
                        <select name="origem" class="form-select">
                            <option value="receptivo" <?php echo $lead['origem'] === 'receptivo' ? 'selected' : ''; ?>>Receptivo (veio até nós)</option>
                            <option value="ativo" <?php echo $lead['origem'] === 'ativo' ? 'selected' : ''; ?>>Ativo (prospecção)</option>
                        </select>
                    </div>

                    <div class="col-md-7">
                        <label class="form-label-strong">Nome do contato *</label>
                        <input type="text" name="nome_contato" class="form-control" required
                               value="<?php echo htmlspecialchars($lead['nome_contato']); ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label-strong" for="telefone">Telefone</label>
                        <input type="tel" id="telefone" name="telefone" class="form-control"
                               value="<?php echo htmlspecialchars($lead['telefone']); ?>"
                               placeholder="(00) 00000-0000">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-strong">Estágio</label>
                        <select name="estagio" class="form-select">
                            <option value="novo" <?php echo $lead['estagio'] === 'novo' ? 'selected' : ''; ?>>Novo lead</option>
                            <option value="visita_agendada" <?php echo $lead['estagio'] === 'visita_agendada' ? 'selected' : ''; ?>>Visita agendada</option>
                            <option value="visita_feita" <?php echo $lead['estagio'] === 'visita_feita' ? 'selected' : ''; ?>>Visita feita</option>
                            <option value="aprovado" <?php echo $lead['estagio'] === 'aprovado' ? 'selected' : ''; ?>>Aprovado / aguardando operação</option>
                            <?php if ($editMode && in_array($lead['estagio'], ['perdido', 'convertido'], true)): ?>
                                <option value="perdido" <?php echo $lead['estagio'] === 'perdido' ? 'selected' : ''; ?>>Perdido</option>
                                <option value="convertido" <?php echo $lead['estagio'] === 'convertido' ? 'selected' : ''; ?>>Convertido em cliente</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-strong">Responsável</label>
                        <select name="responsavel_id" class="form-select">
                            <option value="">— Sem responsável —</option>
                            <?php foreach ($usuarios as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>"
                                    <?php echo (string)$lead['responsavel_id'] === (string)$u['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['email']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-strong">Visita agendada para</label>
                        <input type="datetime-local" name="data_visita_agendada" class="form-control"
                               value="<?php echo htmlspecialchars($lead['data_visita_agendada'] ?? ''); ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label-strong">Observações</label>
                        <textarea name="observacoes" class="form-control" rows="3"><?php echo htmlspecialchars($lead['observacoes']); ?></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                    <a href="listar_leads.php" class="btn btn-outline-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg"></i> <?php echo $editMode ? 'Salvar alterações' : 'Cadastrar lead'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.inputmask/5.0.9/jquery.inputmask.min.js" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            $('#telefone').inputmask({ mask: "(99) 9999[9]-9999", greedy: false, clearIncomplete: true, placeholder: "_" });
        });
    </script>
</body>
</html>
