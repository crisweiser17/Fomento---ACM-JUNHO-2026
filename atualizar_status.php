<?php require_once 'auth_check.php'; ?><?php
// Removido: Linhas de ini_set e error_reporting para evitar que Notices/Warnings quebrem o JSON.

// Define o cabeçalho como JSON O MAIS CEDO POSSÍVEL
header('Content-Type: application/json');

require_once 'db_connection.php';
require_once 'funcoes_calculo_central.php';
require_once 'functions.php';

// --- Funções de Formatação e Estilo (Incluídas diretamente) ---
if (!function_exists('formatHtmlStatus')) {
    function formatHtmlStatus($status, $data_recebimento = null) {
        // Pills suaves (mesmo esquema da listagem): verde = recebido, vermelho = problema, resto neutro.
        $badgeClass = 's-neutro'; $tooltip = '';
        switch ($status) {
            case 'Em Aberto': $badgeClass = 's-neutro'; $tooltip = 'Aguardando ação ou recebimento'; break;
            case 'Recebido':
                $badgeClass = 's-recebido';
                $tooltip = 'Recebimento confirmado';
                // Se tiver data de recebimento, incluir no tooltip
                if (!empty($data_recebimento)) {
                    if (!function_exists('formatHtmlDate')) {
                        function formatHtmlDate($value) {
                            if(!$value) return '-';
                            try {
                                return (new DateTime($value))->format('d/m/Y');
                            } catch(Exception $e){
                                return '-';
                            }
                        }
                    }
                    $dataFormatada = formatHtmlDate($data_recebimento);
                    $tooltip .= ' em ' . $dataFormatada;
                }
                break;
            case 'Parcialmente Compensado': $badgeClass = 's-neutro'; $tooltip = 'Parcialmente compensado'; break;
            case 'Problema': $badgeClass = 's-problema'; $tooltip = 'Problema no recebimento'; break;
        }
        
        // Se for "Recebido" e tiver data, usar tooltip customizado
        if ($status === 'Recebido' && !empty($data_recebimento)) {
            return '<div class="tooltip-wrapper" style="position: relative; display: inline-block;">
                        <span class="status-pill ' . $badgeClass . '">' . htmlspecialchars($status) . '</span>
                        <span class="tooltip-text" style="visibility: hidden; width: auto; min-width: 220px; max-width: 300px; background-color: #000 !important; color: #fff !important; text-align: center; border-radius: 6px; padding: 10px 15px; position: absolute; z-index: 1000; bottom: 125%; left: 50%; transform: translateX(-50%); opacity: 0; transition: opacity 0.2s; font-size: 0.75rem; white-space: normal; box-shadow: 0 2px 8px rgba(0,0,0,0.5); border: 1px solid #333;">' . htmlspecialchars($tooltip) . '</span>
                    </div>
                    <style>
                        .tooltip-wrapper:hover .tooltip-text { visibility: visible; opacity: 1; }
                        .tooltip-text::after { content: ""; position: absolute; top: 100%; left: 50%; margin-left: -5px; border-width: 5px; border-style: solid; border-color: #000 transparent transparent transparent; }
                    </style>';
        }
        
        // Para outros casos, usar tooltip padrão
        return '<span class="status-pill ' . $badgeClass . '" title="' . htmlspecialchars($tooltip) . '">' . htmlspecialchars($status) . '</span>';
    }
}
if (!function_exists('getTableRowClass')) {
     function getTableRowClass($status, $data_vencimento = null) {
        // Sem cor de fundo por status: as linhas usam zebra; urgência/estado vão em pills.
        return '';
    }
}
// --- Fim das Funções ---

// Resposta padrão
$response = ['success' => false, 'message' => 'Erro desconhecido inicial.'];

// Verifica Método e Dados
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Método inválido.';
    echo json_encode($response);
    exit; // Termina o script
}
if (!isset($_POST['id']) || !isset($_POST['status']) || !is_numeric($_POST['id'])) {
    $response['message'] = 'Dados inválidos.';
    echo json_encode($response);
    exit; // Termina o script
}

// Dados
$recebivel_id = (int)$_POST['id'];
$new_status = trim($_POST['status']);
$valor_recebido = isset($_POST['valor_recebido']) && is_numeric($_POST['valor_recebido']) ? (float)$_POST['valor_recebido'] : null;
$allowed_statuses = ['Em Aberto', 'Recebido', 'Problema', 'Parcialmente Compensado'];

// Valida Status
if (!in_array($new_status, $allowed_statuses)) {
     $response['message'] = 'Status inválido: ' . htmlspecialchars($new_status);
     echo json_encode($response);
    exit; // Termina o script
}

// Tenta atualizar
try {
    $pdo->beginTransaction();

    // Se o status for "Recebido", também atualiza a data_recebimento para a data atual
    if ($new_status === 'Recebido') {
        $sql = "UPDATE recebiveis SET status = :status, data_recebimento = CURDATE(), valor_recebido = :valor_recebido WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':status', $new_status, PDO::PARAM_STR);
        $stmt->bindParam(':valor_recebido', $valor_recebido, PDO::PARAM_STR);
        $stmt->bindParam(':id', $recebivel_id, PDO::PARAM_INT);
    } else {
        // Se não for "Recebido", limpa a data_recebimento e o valor_recebido
        $sql = "UPDATE recebiveis SET status = :status, data_recebimento = NULL, valor_recebido = NULL WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':status', $new_status, PDO::PARAM_STR);
        $stmt->bindParam(':id', $recebivel_id, PDO::PARAM_INT);
    }
    
    $success = $stmt->execute();

    if ($success && $stmt->rowCount() > 0) {
        // Buscar a data de recebimento atualizada, operacao_id e data_vencimento para mostrar no status/ações e atualizar cor da linha
        $stmt_data = $pdo->prepare("SELECT data_recebimento, operacao_id, data_vencimento, valor_original, valor_recebido FROM recebiveis WHERE id = :id");
        $stmt_data->bindParam(':id', $recebivel_id, PDO::PARAM_INT);
        $stmt_data->execute();
        $result = $stmt_data->fetch(PDO::FETCH_ASSOC);
        $data_recebimento = $result['data_recebimento'] ?? null;
        $operacao_id = $result['operacao_id'] ?? null;
        $data_vencimento = $result['data_vencimento'] ?? null;
        $valor_original = (float)($result['valor_original'] ?? 0);
        $valor_pago = (float)($result['valor_recebido'] ?? 0);
        
        // Recalcular totais da operação se houver valor extra (juros/mora)
        if ($operacao_id) {
            $delta_lucro = 0;
            if ($new_status === 'Recebido') {
                $old_valor = $valor_pago > 0 ? $valor_pago : $valor_original;
                $novo_valor = $valor_recebido !== null ? $valor_recebido : $valor_original;
                $delta_lucro = $novo_valor - $old_valor;
            } else {
                // Revertendo status para Em Aberto/Problema
                if ($valor_pago > $valor_original) {
                    $delta_lucro = -($valor_pago - $valor_original);
                }
            }
            
            if ($delta_lucro != 0) {
                $stmt_op = $pdo->prepare("UPDATE operacoes SET total_lucro_liquido_calc = total_lucro_liquido_calc + :delta WHERE id = :op_id");
                $stmt_op->execute([':delta' => $delta_lucro, ':op_id' => $operacao_id]);
            }
        }
        
        $pdo->commit();
        
        // Sucesso
        $newStatusHtml = formatHtmlStatus($new_status, $data_recebimento);
        ob_start();
        
        $dias_p_vencimento = calcularDiasParaVencimento($data_vencimento);
        $valor_exibicao = $valor_original;
        if ($dias_p_vencimento < 0 && $new_status !== 'Recebido' && $new_status !== 'Compensado' && $new_status !== 'Totalmente Compensado') {
            $calc = calcularValorCorrigido($valor_original, $data_vencimento);
            $valor_exibicao = $calc['valor_corrigido'];
        }
        
        $btn_data_attrs = 'data-id="' . $recebivel_id . '" data-status="Recebido" data-valor-original="' . $valor_original . '" data-valor-corrigido="' . $valor_exibicao . '"';

        if ($new_status === 'Em Aberto') {
            ?>
            <button class="btn btn-success action-btn update-status-btn" <?php echo $btn_data_attrs; ?> title="Marcar como Recebido"><i class="bi bi-check-lg"></i></button>
            <button class="btn btn-danger action-btn update-status-btn" data-id="<?php echo $recebivel_id; ?>" data-status="Problema" title="Marcar com Problema"><i class="bi bi-exclamation-triangle-fill"></i></button>
            <?php
        } elseif ($new_status === 'Parcialmente Compensado') {
            ?>
            <button class="btn btn-success action-btn update-status-btn" <?php echo $btn_data_attrs; ?> title="Marcar como Recebido"><i class="bi bi-check-lg"></i></button>
            <button class="btn btn-danger action-btn update-status-btn" data-id="<?php echo $recebivel_id; ?>" data-status="Problema" title="Marcar com Problema"><i class="bi bi-exclamation-triangle-fill"></i></button>
            <?php
        } elseif ($new_status === 'Problema') {
            ?>
            <button class="btn btn-success action-btn update-status-btn" <?php echo $btn_data_attrs; ?> title="Marcar como Recebido"><i class="bi bi-check-lg"></i></button>
            <button class="btn btn-secondary action-btn update-status-btn" data-id="<?php echo $recebivel_id; ?>" data-status="Em Aberto" title="Reverter para Em Aberto"><i class="bi bi-arrow-counterclockwise"></i></button>
            <?php
        } elseif ($new_status === 'Recebido') {
            ?>
            <button class="btn btn-secondary action-btn update-status-btn" data-id="<?php echo $recebivel_id; ?>" data-status="Em Aberto" title="Reverter para Em Aberto"><i class="bi bi-arrow-counterclockwise"></i></button>
            <?php
        }
        ?>
        <a href="detalhes_operacao.php?id=<?php echo htmlspecialchars($operacao_id); ?>" class="btn btn-primary action-btn" title="Visualizar Operação"><i class="bi bi-eye"></i></a>
        <?php
        $newActionsHtml = ob_get_clean();
        $newRowClass = getTableRowClass($new_status, $data_vencimento);

        $response = [
            'success' => true,
            'message' => 'Status atualizado com sucesso para ' . htmlspecialchars($new_status) . '.',
            'newStatusHtml' => $newStatusHtml,
            'newActionsHtml' => $newActionsHtml,
            'newRowClass' => $newRowClass
        ];

    } elseif ($success && $stmt->rowCount() == 0) {
         $response['message'] = 'Nenhuma linha afetada (ID ' . $recebivel_id . ' não encontrado ou status já era ' . htmlspecialchars($new_status) . ').';
         $pdo->rollBack();
    } else {
        $response['message'] = 'Falha ao executar UPDATE.';
        $pdo->rollBack();
    }

} catch (PDOException $e) {
     if ($pdo->inTransaction()) { $pdo->rollBack(); }
     error_log("PDO Error: " . $e->getMessage());
     $response['message'] = 'Erro no Banco de Dados (PDO).';
} catch (Exception $e) {
     if ($pdo->inTransaction()) { $pdo->rollBack(); }
     error_log("General Error: " . $e->getMessage());
     $response['message'] = 'Erro Geral no Servidor.';
}

// Envia a resposta JSON FINAL e termina o script
echo json_encode($response);
exit;
?>
