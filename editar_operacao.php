<?php
require_once 'auth_check.php';
require_once 'db_connection.php';
require_once 'funcoes_recalculo.php';

// Tipos aceitos pela coluna recebiveis.tipo_recebivel
const TIPOS_RECEBIVEL_VALIDOS = [
    'duplicata',
    'cheque',
    'nota_promissoria',
    'boleto',
    'fatura',
    'nota_fiscal',
    'parcela_emprestimo',
    'outros',
];

// Validar ID da operação
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: listar_operacoes.php?status=error&msg=" . urlencode("ID da operação inválido."));
    exit;
}
$operacao_id = (int)$_GET['id'];

// Buscar dados da operação
$operacao = null;
$recebiveis = [];
$sacados = [];
$error_message = null;

try {
    // Buscar operação
    $sql_op = "SELECT o.*, 
                      COALESCE(c.empresa, c.nome, (SELECT COALESCE(sac.empresa, sac.nome) FROM recebiveis r2 JOIN clientes sac ON r2.sacado_id = sac.id WHERE r2.operacao_id = o.id LIMIT 1)) AS cedente_nome 
               FROM operacoes o 
               LEFT JOIN clientes c ON o.cedente_id = c.id 
               WHERE o.id = :id";
    $stmt_op = $pdo->prepare($sql_op);
    $stmt_op->bindParam(':id', $operacao_id, PDO::PARAM_INT);
    $stmt_op->execute();
    $operacao = $stmt_op->fetch(PDO::FETCH_ASSOC);
    
    if (!$operacao) {
        header("Location: listar_operacoes.php?status=error&msg=" . urlencode("Operação não encontrada."));
        exit;
    }
    
    // Buscar recebíveis da operação
    $sql_rec = "SELECT r.*, COALESCE(s.empresa, s.nome) AS sacado_nome
                FROM recebiveis r
                LEFT JOIN sacados s ON r.sacado_id = s.id
                WHERE r.operacao_id = :operacao_id
                ORDER BY r.data_vencimento ASC";
    $stmt_rec = $pdo->prepare($sql_rec);
    $stmt_rec->bindParam(':operacao_id', $operacao_id, PDO::PARAM_INT);
    $stmt_rec->execute();
    $recebiveis = $stmt_rec->fetchAll(PDO::FETCH_ASSOC);

    // Buscar lista de sacados para os selects
    $stmt_sacados = $pdo->query(
        "SELECT id, COALESCE(empresa, nome) AS nome FROM sacados ORDER BY COALESCE(empresa, nome) ASC"
    );
    $sacados = $stmt_sacados->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Erro ao buscar dados: " . htmlspecialchars($e->getMessage());
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Atualizar tipo de pagamento e observações da operação
        // IMPORTANTE: Não atualizamos data_operacao para preservar a data original
        $tipo_pagamento = $_POST['tipo_pagamento'] ?? '';
        $notas = $_POST['notas'] ?? '';
        
        $sql_update_op = "UPDATE operacoes SET tipo_pagamento = :tipo_pagamento, notas = :notas WHERE id = :id";
        $stmt_update_op = $pdo->prepare($sql_update_op);
        $stmt_update_op->bindParam(':tipo_pagamento', $tipo_pagamento);
        $stmt_update_op->bindParam(':notas', $notas);
        $stmt_update_op->bindParam(':id', $operacao_id, PDO::PARAM_INT);
        $stmt_update_op->execute();
        
        // Atualizar sacados dos recebíveis
        if (isset($_POST['recebivel_sacado']) && is_array($_POST['recebivel_sacado'])) {
            foreach ($_POST['recebivel_sacado'] as $recebivel_id => $sacado_id) {
                $sacado_id = !empty($sacado_id) ? $sacado_id : null;
                
                $sql_update_rec = "UPDATE recebiveis SET sacado_id = :sacado_id WHERE id = :recebivel_id AND operacao_id = :operacao_id";
                $stmt_update_rec = $pdo->prepare($sql_update_rec);
                $stmt_update_rec->bindParam(':sacado_id', $sacado_id, PDO::PARAM_INT);
                $stmt_update_rec->bindParam(':recebivel_id', $recebivel_id, PDO::PARAM_INT);
                $stmt_update_rec->bindParam(':operacao_id', $operacao_id, PDO::PARAM_INT);
                $stmt_update_rec->execute();
            }
        }
        
        // Atualizar tipos dos recebíveis
        if (isset($_POST['recebivel_tipo']) && is_array($_POST['recebivel_tipo'])) {
            foreach ($_POST['recebivel_tipo'] as $recebivel_id => $tipo_recebivel) {
                $tipo_recebivel = trim($tipo_recebivel);
                if (!in_array($tipo_recebivel, TIPOS_RECEBIVEL_VALIDOS, true)) {
                    $tipo_recebivel = 'fatura'; // Default
                }

                $sql_update_tipo = "UPDATE recebiveis SET tipo_recebivel = :tipo_recebivel WHERE id = :recebivel_id AND operacao_id = :operacao_id";
                $stmt_update_tipo = $pdo->prepare($sql_update_tipo);
                $stmt_update_tipo->bindParam(':tipo_recebivel', $tipo_recebivel, PDO::PARAM_STR);
                $stmt_update_tipo->bindParam(':recebivel_id', $recebivel_id, PDO::PARAM_INT);
                $stmt_update_tipo->bindParam(':operacao_id', $operacao_id, PDO::PARAM_INT);
                $stmt_update_tipo->execute();
            }
        }

        // Atualizar valor e vencimento dos títulos.
        // Percorre os recebíveis vindos do banco (não do POST) para garantir que
        // só títulos desta operação sejam alterados.
        $data_operacao = new DateTime($operacao['data_operacao']);
        $data_operacao->setTime(0, 0, 0);

        $valores_post     = $_POST['recebivel_valor'] ?? [];
        $vencimentos_post = $_POST['recebivel_vencimento'] ?? [];

        $sql_update_titulo = "UPDATE recebiveis SET valor_original = :valor, data_vencimento = :vencimento
                              WHERE id = :recebivel_id AND operacao_id = :operacao_id";
        $stmt_update_titulo = $pdo->prepare($sql_update_titulo);

        foreach ($recebiveis as $recebivel) {
            $recebivel_id = (int) $recebivel['id'];
            if (!isset($valores_post[$recebivel_id], $vencimentos_post[$recebivel_id])) {
                continue;
            }

            $valor = filter_var($valores_post[$recebivel_id], FILTER_VALIDATE_FLOAT);
            if ($valor === false || $valor <= 0) {
                throw new InvalidArgumentException(
                    "Valor inválido no título #{$recebivel_id}. Informe um valor maior que zero."
                );
            }

            $vencimento = DateTime::createFromFormat('Y-m-d', trim($vencimentos_post[$recebivel_id]));
            if ($vencimento === false) {
                throw new InvalidArgumentException("Vencimento inválido no título #{$recebivel_id}.");
            }
            $vencimento->setTime(0, 0, 0);

            if ($vencimento < $data_operacao) {
                throw new InvalidArgumentException(
                    "O vencimento do título #{$recebivel_id} (" . $vencimento->format('d/m/Y') . ") é anterior "
                    . "à data da operação (" . $data_operacao->format('d/m/Y') . ")."
                );
            }

            $stmt_update_titulo->execute([
                ':valor'        => $valor,
                ':vencimento'   => $vencimento->format('Y-m-d'),
                ':recebivel_id' => $recebivel_id,
                ':operacao_id'  => $operacao_id,
            ]);
        }

        // Valores/vencimentos mudaram: refaz VP, IOF, líquido, dias e os totais.
        recalcularOperacaoPersistida($pdo, $operacao_id);

        $pdo->commit();
        header("Location: detalhes_operacao.php?id=$operacao_id&status=updated");
        exit;

    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = htmlspecialchars($e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erro ao atualizar operação {$operacao_id}: " . $e->getMessage());
        $error_message = "Erro ao atualizar a operação. Nenhuma alteração foi salva.";
    }
}
?>
<?php
$pageTitle = 'Editar Operação #' . $operacao_id;
require_once 'head.php';
?>

    <div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">
        <div class="page-toolbar">
            <div class="toolbar-main">
                <h1><i class="bi bi-pencil-square text-info"></i> Editar Operação <span class="id-pill"><i class="bi bi-hash"></i><?php echo htmlspecialchars($operacao_id); ?></span></h1>
            </div>
            <div class="toolbar-actions">
                <a href="detalhes_operacao.php?id=<?php echo $operacao_id; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar para Detalhes
                </a>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <?php if ($operacao): ?>
            <form method="POST">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Informações da Operação</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><strong><?php echo (($operacao['tipo_operacao'] ?? 'antecipacao') == 'emprestimo') ? 'Tomador do Empréstimo:' : 'Cedente:'; ?></strong></label>
                                    <?php
                                    $linkClienteId = $operacao['cedente_id'] ?? null;
                                    if (!$linkClienteId && isset($recebiveis) && count($recebiveis) > 0) {
                                        $linkClienteId = $recebiveis[0]['sacado_id'] ?? null;
                                    }
                                    ?>
                                    <div class="form-control-plaintext">
                                        <?php if ($linkClienteId): ?>
                                            <a href="visualizar_cliente.php?id=<?php echo htmlspecialchars($linkClienteId); ?>" title="Ver Perfil" class="text-decoration-none fw-semibold">
                                                <?php echo htmlspecialchars($operacao['cedente_nome'] ?? 'N/A'); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($operacao['cedente_nome'] ?? 'N/A'); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label"><strong>Data da Operação:</strong></label>
                                    <div class="form-control-plaintext"><?php echo htmlspecialchars(date('d/m/Y', strtotime($operacao['data_operacao']))); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label"><strong>Tipo de Operação:</strong></label>
                                    <div class="form-control-plaintext">
                                        <?php 
                                        if (($operacao['tipo_operacao'] ?? 'antecipacao') == 'emprestimo') {
                                            echo '<span class="badge bg-warning text-dark"><i class="bi bi-cash-coin"></i> Empréstimo</span>';
                                        } else {
                                            echo '<span class="badge bg-success text-white"><i class="bi bi-arrow-return-left"></i> Antecipação</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="tipo_pagamento" class="form-label"><strong>Tipo de Pagamento:</strong></label>
                                    <select id="tipo_pagamento" name="tipo_pagamento" class="form-select" required>
                                        <option value="direto" <?php echo ($operacao['tipo_pagamento'] === 'direto') ? 'selected' : ''; ?>>Pagamento Direto (Devedor Notificado)</option>
                                        <option value="escrow" <?php echo ($operacao['tipo_pagamento'] === 'escrow') ? 'selected' : ''; ?>>Pagamento via Conta Escrow</option>
                                        <option value="indireto" <?php echo ($operacao['tipo_pagamento'] === 'indireto') ? 'selected' : ''; ?>><?php echo (($operacao['tipo_operacao'] ?? 'antecipacao') == 'emprestimo') ? 'Pagamento Indireto (Repasse via Tomador)' : 'Pagamento Indireto (Repasse via Cedente)'; ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="notas" class="form-label"><strong>Observações:</strong></label>
                                    <textarea id="notas" name="notas" class="form-control" rows="3"><?php echo htmlspecialchars($operacao['notas'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Títulos da Operação</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recebiveis)): ?>
                            <div class="alert alert-info">Nenhum recebível encontrado para esta operação.</div>
                        <?php else: ?>
                            <div class="alert alert-warning d-flex align-items-center">
                                <i class="bi bi-calculator me-2"></i>
                                <div>
                                    Alterar <strong>valor</strong> ou <strong>vencimento</strong> recalcula automaticamente
                                    o valor presente, IOF, líquido, prazo e os totais da operação
                                    (taxa de <?php echo number_format(((float)$operacao['taxa_mensal']) * 100, 2, ',', '.'); ?>% a.m.).
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID Recebível</th>
                                            <th>Vencimento</th>
                                            <th>Valor Original</th>
                                            <th>Sacado (Devedor)</th>
                                            <th>Tipo Recebível</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recebiveis as $recebivel): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($recebivel['id']); ?></td>
                                                <td>
                                                    <input type="date"
                                                           name="recebivel_vencimento[<?php echo $recebivel['id']; ?>]"
                                                           value="<?php echo htmlspecialchars($recebivel['data_vencimento']); ?>"
                                                           min="<?php echo htmlspecialchars(date('Y-m-d', strtotime($operacao['data_operacao']))); ?>"
                                                           class="form-control" required>
                                                </td>
                                                <td>
                                                    <div class="input-group">
                                                        <span class="input-group-text">R$</span>
                                                        <input type="number"
                                                               name="recebivel_valor[<?php echo $recebivel['id']; ?>]"
                                                               value="<?php echo htmlspecialchars(number_format((float)$recebivel['valor_original'], 2, '.', '')); ?>"
                                                               step="0.01" min="0.01"
                                                               class="form-control" required>
                                                    </div>
                                                </td>
                                                <td>
                                                    <select name="recebivel_sacado[<?php echo $recebivel['id']; ?>]" class="form-select">
                                                        <option value="">-- Selecione Sacado --</option>
                                                        <?php foreach ($sacados as $sacado): ?>
                                                            <option value="<?php echo $sacado['id']; ?>" 
                                                                    <?php echo ($recebivel['sacado_id'] == $sacado['id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($sacado['nome']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select name="recebivel_tipo[<?php echo $recebivel['id']; ?>]" class="form-select">
                                                        <option value="duplicata" <?php echo (($recebivel['tipo_recebivel'] ?? 'duplicata') == 'duplicata') ? 'selected' : ''; ?>>Duplicata</option>
                                                        <option value="cheque" <?php echo (($recebivel['tipo_recebivel'] ?? '') == 'cheque') ? 'selected' : ''; ?>>Cheque</option>
                                                        <option value="nota_promissoria" <?php echo (($recebivel['tipo_recebivel'] ?? '') == 'nota_promissoria') ? 'selected' : ''; ?>>Nota Promissória</option>
                                                        <option value="boleto" <?php echo (($recebivel['tipo_recebivel'] ?? '') == 'boleto') ? 'selected' : ''; ?>>Boleto</option>
                                                        <option value="fatura" <?php echo (($recebivel['tipo_recebivel'] ?? '') == 'fatura') ? 'selected' : ''; ?>>Fatura</option>
                                                        <option value="nota_fiscal" <?php echo (($recebivel['tipo_recebivel'] ?? '') == 'nota_fiscal') ? 'selected' : ''; ?>>Nota Fiscal</option>
                                                        <option value="parcela_emprestimo" <?php echo (($recebivel['tipo_recebivel'] ?? '') == 'parcela_emprestimo') ? 'selected' : ''; ?>>Parcela de Empréstimo</option>
                                                        <option value="outros" <?php echo (($recebivel['tipo_recebivel'] ?? '') == 'outros') ? 'selected' : ''; ?>>Outros</option>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="detalhes_operacao.php?id=<?php echo $operacao_id; ?>" class="btn btn-outline-secondary spacer">
                        <i class="bi bi-x-lg"></i> Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Salvar Alterações
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>