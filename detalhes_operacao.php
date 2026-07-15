<?php require_once 'auth_check.php'; ?>
<?php
// --- APENAS PARA DEBUG - REMOVER/COMENTAR EM PRODUÇÃO ---
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// --- FIM DEBUG ---

require_once 'db_connection.php'; // Conexão $pdo
require_once 'funcoes_compensacao.php'; // Funções de compensação
require_once 'funcoes_calculo_central.php'; // Funções de cálculo centralizadas

// --- Constantes e Funções de Formatação e IOF ---
define('IOF_ADICIONAL_RATE', 0.0038);
define('IOF_DIARIA_RATE', 0.000082);

if (!function_exists('formatHtmlCurrency')) {
    function formatHtmlCurrency($value) {
        return 'R$ ' . number_format($value ?? 0, 2, ',', '.') . '';
    }
}

if (!function_exists('formatHtmlDate')) {
    function formatHtmlDate($value) {
        if (!$value) return '-';
        try {
            return (new DateTime($value))->format('d/m/Y');
        } catch (Exception $e) {
            return '-';
        }
    }
}

if (!function_exists('formatHtmlStatus')) {
    function formatHtmlStatus($status, $data_recebimento = null, $saldo_aberto = null, $operacao_compensadora = null) {
        // Dieta de cor (igual à tela de Recebíveis): pills suaves. Cor só onde
        // comunica desfecho — verde = recebido, vermelho = problema. Os demais
        // estados ficam neutros para não poluir a coluna Status.
        $pillClass = 's-neutro';
        $tooltip = 'Aguardando ação ou recebimento';
        switch ($status) {
            case 'Em Aberto':
                $pillClass = 's-neutro';
                $tooltip = 'Aguardando ação ou recebimento';
                break;
            case 'Parcialmente Compensado':
                $pillClass = 's-neutro';
                $tooltip = 'Parcialmente compensado por outra operação';
                break;
            case 'Compensado':
                $pillClass = 's-neutro';
                $tooltip = 'Totalmente compensado por outra operação';
                break;
            case 'Recebido':
                $pillClass = 's-recebido';
                $tooltip = 'Recebimento confirmado';
                if (!empty($data_recebimento)) {
                    $tooltip .= ' em ' . formatHtmlDate($data_recebimento);
                }
                break;
            case 'Problema':
                $pillClass = 's-problema';
                $tooltip = 'Problema no recebimento';
                break;
        }

        // Se for "Recebido" e tiver data, usar tooltip customizado
        if ($status === 'Recebido' && !empty($data_recebimento)) {
            return '<div class="tooltip-wrapper">
                        <span class="status-pill ' . $pillClass . '">' . htmlspecialchars($status) . '</span>
                        <span class="tooltip-text">' . htmlspecialchars($tooltip) . '</span>
                    </div>';
        }

        // Se for "Parcialmente Compensado", mostrar pill + saldo em aberto
        if ($status === 'Parcialmente Compensado' && $saldo_aberto !== null && $saldo_aberto > 0) {
            $pillContent = htmlspecialchars($status);

            // Se tiver operação compensadora, criar link
            if ($operacao_compensadora !== null && $operacao_compensadora > 0) {
                $pillContent = '<a href="detalhes_operacao.php?id=' . $operacao_compensadora . '"
                                   class="text-decoration-none"
                                   style="color: inherit;"
                                   title="Ver operação #' . $operacao_compensadora . ' que compensou este recebível">' .
                                   htmlspecialchars($status) . '</a>';
            }

            return '<div>
                        <span class="status-pill ' . $pillClass . '">' . $pillContent . '</span>
                        <br><small class="text-muted">Saldo: ' . formatHtmlCurrency($saldo_aberto) . '</small>
                    </div>';
        }

        // Para outros casos, usar tooltip padrão
        return '<span class="status-pill ' . $pillClass . '" title="' . htmlspecialchars($tooltip) . '">' . htmlspecialchars($status) . '</span>';
    }
}

if (!function_exists('getTableRowClass')) {
    function getTableRowClass($status, $data_vencimento = null) {
        // Igual à tela de Recebíveis: linhas não recebem cor de fundo por status
        // (zebra cuida da leitura). Status e urgência são comunicados pelos pills
        // nas colunas Status e Vencimento, evitando o "mural vermelho".
        return '';
    }
}

if (!function_exists('formatHtmlSimNao')) {
    function formatHtmlSimNao($value) {
        return $value ? 'Sim' : 'Não';
    }
}


// --- Validar ID da Operação ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: listar_operacoes.php?status=error&msg=" . urlencode("ID da operação inválido."));
    exit;
}
$operacao_id = (int)$_GET['id'];

// --- Buscar Dados da Operação e Sacado ---
$operacao = null;
$error_message = null;
try {
    $sql_op = "SELECT
                   o.*,
                   COALESCE(c.empresa, c.nome, (SELECT COALESCE(sac.empresa, sac.nome) FROM recebiveis r2 JOIN clientes sac ON r2.sacado_id = sac.id WHERE r2.operacao_id = o.id LIMIT 1)) AS cedente_nome,
                   c.empresa AS sacado_empresa,
                   c.representante_estado_civil AS cedente_representante_estado_civil,
                   o.cedente_id
               FROM
                   operacoes o
               LEFT JOIN clientes c ON o.cedente_id = c.id
               WHERE
                   o.id = :id";
    $stmt_op = $pdo->prepare($sql_op);
    $stmt_op->bindParam(':id', $operacao_id, PDO::PARAM_INT);
    $stmt_op->execute();
    $operacao = $stmt_op->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "Erro ao buscar dados da operação: " . htmlspecialchars($e->getMessage());
}

if (!$operacao && !isset($error_message)) {
    $error_message = "Operação com ID " . $operacao_id . " não encontrada.";
}

// --- Variáveis para Exibição (Totais) ---
$totalOriginalCalculado = 0;
$totalLiquidoPagoCalculado = (float)($operacao['total_liquido_pago_calc'] ?? 0); // Do banco
$totalLucroLiquidoCalculado = (float)($operacao['total_lucro_liquido_calc'] ?? 0); // Do banco
$totalRecebidoCalculado = 0; // Soma do valor_recebido para títulos com status='Recebido'
$capitalRecuperadoCalculado = 0; // Soma do valor_liquido_pago dos títulos já recebidos
$totalIOFTeoricoCalculado = 0; // Acumula o IOF calculado em cada título
$percentualLucroLiquido = 0; // Calculado no final
$dataOperacaoDT = null;

// --- Buscar Recebíveis Associados e Recalcular Dados ---
$recebiveis_para_exibir = []; // Array que conterá os recebíveis com os dados recalculados para exibição na tabela

// Dados para o GRÁFICO - agora mais complexos para refletir saída/entrada/lucro
$chartDataRaw = []; // Armazena todos os pontos de dados por mês/tipo

if ($operacao && !isset($error_message)) {
    try {
        $sql_rec = "SELECT r.*, COALESCE(s.empresa, s.nome) as sacado_nome, r.sacado_id, s.representante_estado_civil as sacado_representante_estado_civil
                   FROM recebiveis r
                   LEFT JOIN clientes s ON r.sacado_id = s.id
                   WHERE r.operacao_id = :operacao_id
                   ORDER BY r.data_vencimento ASC";
        $stmt_rec = $pdo->prepare($sql_rec);
        $stmt_rec->bindParam(':operacao_id', $operacao_id, PDO::PARAM_INT);
        $stmt_rec->execute();
        $recebiveis_db = $stmt_rec->fetchAll(PDO::FETCH_ASSOC);

        // Obter os flags de IOF e taxa da operação
        $cobrarIOFCliente = filter_var($operacao['cobrar_iof_cliente'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $incorreCustoIOF = filter_var($operacao['incorre_custo_iof'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $taxaMensal = (float)($operacao['taxa_mensal'] ?? 0);

        try {
            $dataOperacaoDT = new DateTime($operacao['data_operacao']);
            $dataOperacaoDT->setTime(0,0,0);
        } catch (Exception $e) {
            $error_message = "Data da operação inválida no banco: " . htmlspecialchars($operacao['data_operacao']);
            $operacao = null;
        }

        if ($operacao) { // Continua apenas se a data da operação for válida
            // Preparar dados para cálculo centralizado
            $titulos_para_calculo = [];
            $minDate = clone $dataOperacaoDT; // Mês da operação
            $maxDate = clone $dataOperacaoDT; // Iniciar com mês da operação
            
            // Preparar títulos no formato esperado pelas funções centralizadas
            foreach ($recebiveis_db as $r) {
                $dataVencimentoTitulo = new DateTime($r['data_vencimento']);
                if ($dataVencimentoTitulo > $maxDate) {
                    $maxDate = $dataVencimentoTitulo;
                }
                
                $titulos_para_calculo[] = [
                    'valor' => (float)$r['valor_original'],
                    'data_vencimento' => $r['data_vencimento']
                ];
            }
            
            // Criar estrutura de meses vazios entre a data da operação e o último vencimento
            $currentDate = clone $minDate;
            $currentDate->modify('first day of this month');
            $endDate = clone $maxDate;
            $endDate->modify('first day of this month');
            
            while ($currentDate <= $endDate) {
                $monthYearKey = $currentDate->format('Y-m');
                $chartDataRaw[$monthYearKey] = [
                    'capital_emprestado' => 0,
                    'capital_retornado' => 0,
                    'lucro' => 0,
                    'displayLabel' => $currentDate->format('M/Y')
                ];
                $currentDate->modify('+1 month');
            }
            
            // Usar função centralizada para calcular totais
            $totais_calculados = calcularTotaisOperacao(
                $titulos_para_calculo,
                $operacao['data_operacao'],
                $taxaMensal,
                $cobrarIOFCliente,
                [] // Compensações serão processadas separadamente
            );
            
            // Atualizar variáveis de totais com valores calculados
            $totalOriginalCalculado = $totais_calculados['total_original'];
            $totalIOFTeoricoCalculado = $totais_calculados['total_iof'];
            // Preferir as colunas _calc do banco (canônicas, já consideram compensação);
            // se estiverem vazias (operações antigas sem cálculo persistido), usar o
            // recálculo dinâmico como fallback para não exibir lucro/margem zerados.
            if ((float)($operacao['total_liquido_pago_calc'] ?? 0) <= 0) {
                $totalLiquidoPagoCalculado = $totais_calculados['total_liquido_pago'];
            }
            if ((float)($operacao['total_lucro_liquido_calc'] ?? 0) <= 0) {
                $totalLucroLiquidoCalculado = $totais_calculados['total_lucro_liquido'];
            }
            
            // Processar cada título para exibição usando dados calculados
            foreach ($recebiveis_db as $index => $r) {
                $detalhe_titulo = $totais_calculados['detalhes_titulos'][$index];
                
                // Adicionar dados calculados ao array do recebível para exibição na tabela "Recebíveis da Operação"
                $r['dias_para_vencimento_calc'] = $detalhe_titulo['dias'];
                $r['valor_liquido_calc_dinamico'] = $detalhe_titulo['valor_liquido_pago'];
                $r['lucro_liquido_calc_dinamico'] = $detalhe_titulo['lucro_liquido'];

                // Acumular total efetivamente recebido e capital recuperado (apenas status='Recebido')
                if (($r['status'] ?? '') === 'Recebido') {
                    if (is_numeric($r['valor_recebido'] ?? null)) {
                        $totalRecebidoCalculado += (float)$r['valor_recebido'];
                    }
                    $capitalRecuperadoCalculado += (float)$detalhe_titulo['valor_liquido_pago'];
                }
                $recebiveis_para_exibir[] = $r;
                
                // --- DADOS PARA O NOVO GRÁFICO ---
                $dataVencimentoTitulo = new DateTime($r['data_vencimento']);
                $monthYearKeyVencimento = $dataVencimentoTitulo->format('Y-m');
                
                // Capital Retornado = valor que foi emprestado para este título específico
                // Lucro = lucro líquido deste título específico
                if (isset($chartDataRaw[$monthYearKeyVencimento])) {
                    $chartDataRaw[$monthYearKeyVencimento]['capital_retornado'] += $detalhe_titulo['valor_liquido_pago'];
                    $chartDataRaw[$monthYearKeyVencimento]['lucro'] += $detalhe_titulo['lucro_liquido'];
                }
            }

            // Adicionar Capital Emprestado (Total Líquido Pago da operação) no mês da operação
            $monthYearKeyOperacao = $dataOperacaoDT->format('Y-m');
            if (isset($chartDataRaw[$monthYearKeyOperacao])) {
                // Importante: o capital emprestado é o TOTAL líquido pago para o cliente na data da operação.
                // Para o gráfico, ele deve ser associado ao mês da operação, como uma "saída".
                $chartDataRaw[$monthYearKeyOperacao]['capital_emprestado'] += $totalLiquidoPagoCalculado;
            }

            // Adicionar compensações como entrada imediata no mês da operação
            if ($operacao['valor_total_compensacao'] > 0) {
                try {
                    $sql_comp_grafico = "SELECT
                                            c.valor_presente_compensacao,
                                            c.data_compensacao
                                         FROM compensacoes c
                                         WHERE c.operacao_principal_id = :operacao_id";
                    $stmt_comp_grafico = $pdo->prepare($sql_comp_grafico);
                    $stmt_comp_grafico->bindParam(':operacao_id', $operacao_id, PDO::PARAM_INT);
                    $stmt_comp_grafico->execute();
                    $compensacoes_grafico = $stmt_comp_grafico->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($compensacoes_grafico as $comp_grafico) {
                        $dataCompensacao = new DateTime($comp_grafico['data_compensacao']);
                        $monthYearKeyCompensacao = $dataCompensacao->format('Y-m');
                        
                        // Adicionar o valor presente da compensação como capital retornado no mês da compensação
                        if (isset($chartDataRaw[$monthYearKeyCompensacao])) {
                            $chartDataRaw[$monthYearKeyCompensacao]['capital_retornado'] += $comp_grafico['valor_presente_compensacao'];
                        } else {
                            // Se o mês da compensação não existe no array, criar
                            $chartDataRaw[$monthYearKeyCompensacao] = [
                                'capital_emprestado' => 0,
                                'capital_retornado' => $comp_grafico['valor_presente_compensacao'],
                                'lucro' => 0,
                                'displayLabel' => $dataCompensacao->format('M/Y')
                            ];
                        }
                    }
                } catch (Exception $e) {
                    // Em caso de erro, continuar sem as compensações
                }
            }


            // Calcular percentual do lucro líquido APÓS o loop, usando os totais recalculados
            if ($totalOriginalCalculado > 0) {
                $percentualLucroLiquido = ($totalLucroLiquidoCalculado / $totalOriginalCalculado) * 100;
            } else {
                $percentualLucroLiquido = 0;
            }

            // Ordenar os dados do gráfico por mês/ano
            ksort($chartDataRaw);

            $chartLabels = []; // Aqui vamos armazenar os labels formatados para o Chart.js
            $chartDataCapitalEmprestado = [];
            $chartDataCapitalRetornado = [];
            $chartDataLucro = [];

            foreach ($chartDataRaw as $monthYearKey => $data) {
                $chartLabels[] = $data['displayLabel']; // AGORA PEGAMOS O LABEL FORMATADO
                $chartDataCapitalEmprestado[] = $data['capital_emprestado'];
                $chartDataCapitalRetornado[] = $data['capital_retornado'];
                $chartDataLucro[] = $data['lucro'];
            }
        }

    } catch (PDOException $e) {
        $error_message = "Erro ao buscar recebíveis da operação: " . htmlspecialchars($e->getMessage());
        $operacao = null;
    } catch (Exception $e) {
        $error_message = "Erro ao processar dados de recebíveis: " . htmlspecialchars($e->getMessage());
        $operacao = null;
    }
}

// --- Buscar Anotações da Operação ---
$anotacoes = [];
if ($operacao && !isset($error_message)) {
    try {
        $sql_anotacoes = "SELECT a.*, u.email as usuario_nome, r.tipo_recebivel as recebivel_tipo, r.data_vencimento as recebivel_vencimento, r.valor_original as recebivel_valor
                          FROM operacao_anotacoes a
                          JOIN usuarios u ON a.usuario_id = u.id
                          LEFT JOIN recebiveis r ON a.recebivel_id = r.id
                          WHERE a.operacao_id = :operacao_id
                          ORDER BY a.data_criacao DESC";
        $stmt_anotacoes = $pdo->prepare($sql_anotacoes);
        $stmt_anotacoes->bindParam(':operacao_id', $operacao_id, PDO::PARAM_INT);
        $stmt_anotacoes->execute();
        $anotacoes = $stmt_anotacoes->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message_anotacoes = "Erro ao buscar anotações: " . htmlspecialchars($e->getMessage());
    }
}
?>
<?php
// <head> + navbar centralizados no head.php (theme.css/style.css/app.js/bootstrap).
// Aqui só entram os assets e estilos REALMENTE específicos desta tela.
$pageTitle = "Detalhes da Operação #" . $operacao_id;
$headExtra = <<<'HTML'
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.11.6/viewer.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        /* Tokens e componentes (page-toolbar, status-pill, pill-tipo, section-card,
           sticky-panel, summary-*, panel-actions, id-pill) vêm do theme.css.
           Mantemos abaixo só o que é exclusivo desta tela. */
        body.app-page { font-size: 0.95rem; }

        /* Barra de recuperação (não existe no theme.css) */
        .progress-recup {
            margin-top: 8px; height: 8px; border-radius: 4px;
            background: rgba(0,0,0,0.08); overflow: hidden;
        }
        .progress-recup-bar {
            height: 100%; background: var(--app-profit);
            border-radius: 4px; transition: width 0.4s ease;
        }
        .progress-breakdown {
            display: flex; justify-content: space-between; gap: 8px;
            margin-top: 8px; font-size: 0.78rem; color: var(--app-neutral);
        }
        .progress-breakdown b { color: var(--app-ink); font-weight: 700; }
        .progress-status { margin-top: 6px; font-size: 0.78rem; font-weight: 600; }
        .progress-status.ok { color: var(--app-profit); }
        .progress-status.pending { color: var(--app-warn); }

        /* Estilos legados preservados */
        .table th { width: auto; }
        .list-group-item strong { display: inline-block; width: 180px; }
        .chart-wrapper { position: relative; min-height: 700px; margin-top: 20px; margin-bottom: 30px; }
        .chart-wrapper canvas { max-width: 100%; max-height: 100%; }
        /* .action-btn agora vem do theme.css (tons suaves, igual à listagem) */

        /* Tooltip customizado */
        .tooltip-wrapper { position: relative; display: inline-block; }
        .tooltip-wrapper .tooltip-text {
            visibility: hidden; width: auto; min-width: 220px; max-width: 300px;
            background-color: #000 !important; color: #fff !important;
            text-align: center; border-radius: 6px; padding: 10px 15px;
            position: absolute; z-index: 1000; bottom: 125%; left: 50%;
            transform: translateX(-50%); opacity: 0; transition: opacity 0.2s;
            font-size: 0.75rem; white-space: normal;
            box-shadow: 0 2px 8px rgba(0,0,0,0.5); border: 1px solid #333;
        }
        .tooltip-wrapper .tooltip-text::after {
            content: ""; position: absolute; top: 100%; left: 50%;
            margin-left: -5px; border-width: 5px; border-style: solid;
            border-color: #000 transparent transparent transparent;
        }
        .tooltip-wrapper:hover .tooltip-text { visibility: visible; opacity: 1; }

        /* Viewer.js 90% */
        .viewer-90-percent,
        .viewer-container.viewer-90-percent {
            width: 90% !important; height: 90% !important;
            top: 5% !important; left: 5% !important;
            border-radius: 8px; box-shadow: 0 0 20px rgba(0,0,0,0.5);
            background-color: transparent !important;
        }
        .viewer-backdrop { background-color: rgba(0, 0, 0, 0.85) !important; }
    </style>
HTML;
require_once 'head.php';
?>

    <div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">

        <?php if (isset($_GET['status']) && $_GET['status'] === 'updated'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill"></i> Operação atualizada com sucesso!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
            <a href="listar_operacoes.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Voltar para Lista</a>
        <?php elseif ($operacao): ?>
            <?php
            $isEmprestimo = ($operacao['tipo_operacao'] ?? 'antecipacao') === 'emprestimo';
            $valorEmprestimoOriginal = (float)($operacao['valor_emprestimo'] ?? 0);
            $temValorEmprestimoOriginal = $isEmprestimo && $valorEmprestimoOriginal > 0;
            $labelParteOperacao = $isEmprestimo ? 'Tomador do Empréstimo:' : 'Cedente:';
            $labelTipoOperacao = $isEmprestimo ? 'Empréstimo' : 'Antecipação';
            $labelTaxa = $isEmprestimo ? 'Taxa de Juros Aplicada:' : 'Taxa de Desconto Aplicada:';
            $labelTotalOriginal = $isEmprestimo ? 'Valor a Receber' : 'Total Original dos Recebíveis:';
            $labelTotalIOF = 'Total IOF (Teórico):';
            $labelIOFCliente = $isEmprestimo ? 'IOF Repassado ao Cliente:' : 'Cobra IOF do Cliente:';
            $labelValorLiberado = $isEmprestimo ? 'Valor Liberado ao Tomador:' : 'Valor Líquido Liberado:';
            $labelResultadoLiquido = $isEmprestimo ? 'Receita/Lucro Líquido da Operação:' : 'Lucro Líquido:';
            $labelPagamento = $isEmprestimo ? 'Forma de Recebimento:' : 'Tipo de Pagamento:';
            ?>

            <?php
            // Determinar status agregado da operação para a toolbar
            $statusAgg = 'Em Aberto';
            if (!empty($recebiveis_para_exibir)) {
                $temProblema = false; $temAberto = false; $temParcial = false; $todosRecebidos = true;
                foreach ($recebiveis_para_exibir as $rEval) {
                    $st = $rEval['status'] ?? '';
                    if ($st === 'Problema') $temProblema = true;
                    if ($st === 'Em Aberto') { $temAberto = true; $todosRecebidos = false; }
                    if ($st === 'Parcialmente Compensado') { $temParcial = true; $todosRecebidos = false; }
                    if (!in_array($st, ['Recebido', 'Compensado'], true)) $todosRecebidos = false;
                }
                if ($temProblema) $statusAgg = 'Com Problema';
                elseif ($temAberto) $statusAgg = 'Em Aberto';
                elseif ($temParcial) $statusAgg = 'Parcialmente Compensada';
                elseif ($todosRecebidos) $statusAgg = 'Concluída';
            }
            $statusPillMap = [
                'Em Aberto' => ['s-aberto', 'Em aberto', 'clock-fill'],
                'Concluída' => ['s-concluida', 'Concluída', 'check-circle-fill'],
                'Parcialmente Compensada' => ['s-parcial', 'Parcial', 'pie-chart-fill'],
                'Com Problema' => ['s-problema', 'Com problema', 'exclamation-triangle-fill'],
            ];
            $sp = $statusPillMap[$statusAgg] ?? ['s-aberto', $statusAgg, 'circle-fill'];
            $cedenteNomeTopo = $operacao['cedente_nome'] ?? ($operacao['cedente_empresa'] ?? '');
            $dataOpFmt = !empty($operacao['data_operacao']) ? date('d/m/Y', strtotime($operacao['data_operacao'])) : '—';
            ?>
            <div class="page-toolbar">
                <div>
                    <h1>
                        <i class="bi bi-receipt text-primary"></i>
                        Operação <span class="text-primary">#<?php echo htmlspecialchars($operacao['id']); ?></span>
                        <?php if ($cedenteNomeTopo): ?>
                            <span class="subtitle">— <?php echo htmlspecialchars($cedenteNomeTopo); ?></span>
                        <?php endif; ?>
                    </h1>
                    <div class="text-muted small mt-1">
                        <?php if ($isEmprestimo): ?>
                            <span class="pill-tipo empr"><i class="bi bi-cash-coin"></i> Empréstimo</span>
                        <?php else: ?>
                            <span class="pill-tipo antecip"><i class="bi bi-arrow-return-left"></i> Antecipação</span>
                        <?php endif; ?>
                        · Registrada em <strong><?php echo $dataOpFmt; ?></strong>
                        · <span class="status-pill <?php echo $sp[0]; ?>"><i class="bi bi-<?php echo $sp[2]; ?>"></i> <?php echo $sp[1]; ?></span>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="listar_operacoes.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Lista
                    </a>
                    <?php if (!$isEmprestimo): ?>
                        <button id="notificarSacadosBtn" class="btn btn-success btn-sm" data-operacao-id="<?php echo htmlspecialchars($operacao['id']); ?>">
                            <i class="bi bi-envelope-fill"></i> Notificar Sacados
                        </button>
                    <?php endif; ?>
                    <button id="editarOperacaoBtn" class="btn btn-warning">
                        <i class="bi bi-pencil-square"></i> Editar
                    </button>
                </div>
            </div>

            <div class="row g-4">
            <div class="col-xl-8">

            <div class="section-card s-data">
                <div class="section-head">
                    <span class="step-num">1</span>
                    <h5 class="mb-0">Dados da Operação</h5>
                </div>
                <div class="section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1"><?php echo $labelParteOperacao; ?></strong>
                            <div class="fs-6">
                                <?php if ($operacao['cedente_id']): ?>
                                    <a href="visualizar_cliente.php?id=<?php echo $operacao['cedente_id']; ?>" title="Ver Perfil" class="text-decoration-none fw-semibold">
                                        <?php echo htmlspecialchars($operacao['cedente_nome'] ?? 'Desconhecido'); ?>
                                    </a>
                                <?php else: ?>
                                    <!-- Fallback para sacado se cedente não existir, com link para o sacado caso possível -->
                                    <?php
                                    $sacadoIdParaLink = null;
                                    if (isset($recebiveis_db) && count($recebiveis_db) > 0) {
                                        $sacadoIdParaLink = $recebiveis_db[0]['sacado_id'] ?? null;
                                    }
                                    ?>
                                    <?php if ($sacadoIdParaLink): ?>
                                        <a href="visualizar_cliente.php?id=<?php echo $sacadoIdParaLink; ?>" title="Ver Perfil" class="text-decoration-none fw-semibold">
                                            <?php echo htmlspecialchars($operacao['cedente_nome'] ?? 'N/A'); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($operacao['cedente_nome'] ?? 'N/A'); ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1">Data Base de Cálculo:</strong>
                            <div class="fs-6"><?php echo htmlspecialchars(isset($operacao['data_operacao']) ? date('d/m/Y', strtotime($operacao['data_operacao'])) : '-'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1">Data de Registro da Operação:</strong>
                            <div class="fs-6"><?php echo htmlspecialchars(isset($operacao['data_operacao']) ? date('d/m/Y H:i', strtotime($operacao['data_operacao'])) : '-'); ?></div>
                        </div>
                         <div class="col-md-6">
                            <strong class="text-muted d-block mb-1"><?php echo $labelTaxa; ?></strong>
                             <div class="fs-6"><?php echo htmlspecialchars(number_format(($operacao['taxa_mensal'] ?? 0) * 100, 2, ',', '.') . '%'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1">Tipo de Operação:</strong>
                            <div>
                                <?php 
                                if ($isEmprestimo) {
                                    echo '<span class="pill-tipo empr"><i class="bi bi-cash-coin"></i> Empréstimo</span>';
                                } else {
                                    echo '<span class="pill-tipo antecip"><i class="bi bi-arrow-return-left"></i> ' . htmlspecialchars($labelTipoOperacao) . '</span>';
                                }
                                ?>
                            </div>
                        </div>
                        <?php if ($isEmprestimo): ?>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1">Possui Garantia?</strong>
                            <div class="fs-6"><?php echo (!empty($operacao['tem_garantia'])) ? 'Sim' : 'Não'; ?></div>
                        </div>
                        <?php if (!empty($operacao['tem_garantia']) && !empty($operacao['descricao_garantia'])): ?>
                        <div class="col-md-12">
                            <strong class="text-muted d-block mb-1">Descrição da Garantia:</strong>
                            <div class="p-2 bg-light rounded border"><?php echo nl2br(htmlspecialchars($operacao['descricao_garantia'])); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1"><?php echo $labelPagamento; ?></strong>
                            <div class="fs-6">
                                <?php 
                                    $tipoPagamento = $operacao['tipo_pagamento'] ?? 'direto';
                                    switch($tipoPagamento) {
                                        case 'direto': echo $isEmprestimo ? 'Pagamento Direto' : 'Pagamento Direto (Notificação ao Sacado)'; break;
                                        case 'escrow': echo $isEmprestimo ? 'Conta Escrow' : 'Pagamento via Conta Escrow (Conta Vinculada)'; break;
                                        case 'indireto': echo $isEmprestimo ? 'Repasse via Cedente' : 'Pagamento Indireto (via Cedente)'; break;
                                        case 'cheque': echo 'Cheque(s)'; break;
                                        default: echo htmlspecialchars($tipoPagamento);
                                    }
                                ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1">Incorre Custo de IOF:</strong>
                            <div class="fs-6"><?php echo formatHtmlSimNao($incorreCustoIOF); ?></div>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1"><?php echo $labelIOFCliente; ?></strong>
                            <div class="fs-6"><?php echo formatHtmlSimNao($cobrarIOFCliente); ?></div>
                        </div>
                        <?php if ($temValorEmprestimoOriginal): ?>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1">Valor Original do Empréstimo:</strong>
                            <div class="fs-6"><?php echo formatHtmlCurrency($valorEmprestimoOriginal); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1"><?php echo $labelTotalOriginal; ?></strong>
                            <div class="fs-6"><?php echo formatHtmlCurrency($totalOriginalCalculado); ?></div>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1"><?php echo $labelTotalIOF; ?></strong>
                             <div class="fs-6"><?php echo formatHtmlCurrency($operacao['iof_total_calc'] ?? 0); ?></div>
                        </div>
                        <?php if ($operacao['valor_total_compensacao'] > 0): ?>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1">Abatimento:</strong>
                             <div class="fs-6 text-warning fw-bold"><?php echo formatHtmlCurrency(-$operacao['valor_total_compensacao']); ?></div>
                        </div>
                        <?php 
                        // Calcular custo da antecipação
                        $custo_antecipacao_total = 0;
                        try {
                            $sql_comp = "SELECT SUM(valor_compensado) as total_compensado, SUM(valor_presente_compensacao) as total_presente
                                        FROM compensacoes 
                                        WHERE operacao_principal_id = :operacao_id";
                            $stmt_comp = $pdo->prepare($sql_comp);
                            $stmt_comp->bindParam(':operacao_id', $operacao_id, PDO::PARAM_INT);
                            $stmt_comp->execute();
                            $resultado_comp = $stmt_comp->fetch(PDO::FETCH_ASSOC);
                            
                            if ($resultado_comp && $resultado_comp['total_compensado'] > 0) {
                                $custo_antecipacao_total = $resultado_comp['total_compensado'] - $resultado_comp['total_presente'];
                            }
                        } catch (Exception $e) {
                            // Em caso de erro, manter custo como 0
                        }
                        ?>
                        <?php if (!$isEmprestimo): ?>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1">Custo da Antecipação:</strong>
                             <div class="fs-6 text-danger fw-bold"><?php echo formatHtmlCurrency($custo_antecipacao_total); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <!-- Totais Finais agrupados na direita -->
                        <div class="col-md-6 offset-md-6 border-top pt-3 mt-3">
                            <div class="mb-3">
                                <strong class="text-muted d-block mb-1"><?php echo $labelValorLiberado; ?></strong>
                                <div class="fs-5 fw-bold"><?php echo formatHtmlCurrency($totalLiquidoPagoCalculado); ?></div>
                            </div>
                            <div>
                                <strong class="text-muted d-block mb-1"><?php echo $labelResultadoLiquido; ?></strong>
                                <div class="fs-5 text-success fw-bold"><?php echo formatHtmlCurrency($totalLucroLiquidoCalculado); ?> <small class="text-muted fs-6">(<?php echo number_format($percentualLucroLiquido, 2, ',', '.') . '%'; ?>)</small></div>
                            </div>
                        </div>

                        <div class="col-md-12 mt-4">
                            <strong class="text-muted d-block mb-2">Observações:</strong>
                            <div class="p-3 bg-light border rounded">
                                <?php 
                                $observacoes = $operacao['notas'] ?? '';
                                
                                // Buscar informações de compensação se existirem
                                if ($operacao['valor_total_compensacao'] > 0) {
                                    try {
                                        $sql_comp = "SELECT c.*, r.valor_original as recebivel_valor_original, 
                                                           r.data_vencimento as recebivel_data_vencimento,
                                                           r.id as recebivel_id
                                                    FROM compensacoes c 
                                                    INNER JOIN recebiveis r ON c.recebivel_compensado_id = r.id
                                                    WHERE c.operacao_principal_id = :operacao_id 
                                                    ORDER BY c.data_compensacao ASC";
                                        $stmt_comp = $pdo->prepare($sql_comp);
                                        $stmt_comp->bindParam(':operacao_id', $operacao_id, PDO::PARAM_INT);
                                        $stmt_comp->execute();
                                        $compensacoes = $stmt_comp->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        if (!empty($compensacoes)) {
                                            $observacoes .= "\n\n=== ENCONTRO DE CONTAS ===\n";
                                            
                                            $total_compensado = array_sum(array_column($compensacoes, 'valor_compensado'));
                                            $total_presente = array_sum(array_column($compensacoes, 'valor_presente_compensacao'));
                                            $custo_antecipacao = $total_compensado - $total_presente;
                                            
                                            foreach ($compensacoes as $comp) {
                                                // Buscar a operação que contém este recebível para criar o link
                                                $operacao_recebivel = null;
                                                try {
                                                    $stmt_op_rec = $pdo->prepare("SELECT operacao_id FROM recebiveis WHERE id = ?");
                                                    $stmt_op_rec->execute([$comp['recebivel_id']]);
                                                    $operacao_recebivel = $stmt_op_rec->fetchColumn();
                                                } catch (Exception $e) {
                                                    // Em caso de erro, usar apenas o ID sem link
                                                }
                                                
                                                $saldo_restante = $comp['recebivel_valor_original'] - $comp['valor_compensado'];
                                                $taxa_formatada = number_format($comp['taxa_antecipacao_aplicada'], 0, ',', '.');
                                                
                                                if ($operacao_recebivel) {
                                                    $observacoes .= "\n• R$ " . number_format($comp['valor_compensado'], 2, ',', '.') .
                                                                  " antecipados do recebível #{$comp['recebivel_id']} " .
                                                                  "(venc. " . date('d/m/Y', strtotime($comp['recebivel_data_vencimento'])) . ") " .
                                                                  "com taxa de {$taxa_formatada}% a.m. " .
                                                                  "<a href='detalhes_operacao.php?id={$operacao_recebivel}' target='_blank'>Ver Operação</a>\n";
                                                } else {
                                                    $observacoes .= "\n• R$ " . number_format($comp['valor_compensado'], 2, ',', '.') .
                                                                  " antecipados do recebível #{$comp['recebivel_id']} " .
                                                                  "(venc. " . date('d/m/Y', strtotime($comp['recebivel_data_vencimento'])) . ") " .
                                                                  "com taxa de {$taxa_formatada}% a.m.\n";
                                                }
                                                
                                                if ($comp['tipo_compensacao'] === 'parcial') {
                                                    $observacoes .= "  Saldo restante: R$ " . number_format($saldo_restante, 2, ',', '.') . "\n";
                                                }
                                            }
                                            
                                            $observacoes .= "\n• Resumo: R$ " . number_format($total_compensado, 2, ',', '.') . " compensados\n";
                                            $observacoes .= "• Valor presente: R$ " . number_format($total_presente, 2, ',', '.') . " recebidos\n";
                                            $observacoes .= "• Custo da antecipação (crédito ao cliente): R$ " . number_format($custo_antecipacao, 2, ',', '.') . "\n";
                                        }
                                    } catch (Exception $e) {
                                        $observacoes .= "\n\n[Erro ao carregar detalhes da compensação: " . $e->getMessage() . "]";
                                    }
                                }
                                
                                echo nl2br($observacoes ?: 'Nenhuma observação registrada.');
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-card s-recb">
                <div class="section-head">
                    <span class="step-num">2</span>
                    <h5 class="mb-0">Recebíveis da Operação</h5>
                    <span class="head-meta"><?php echo count($recebiveis_para_exibir ?? []); ?> título<?php echo count($recebiveis_para_exibir ?? []) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="section-body p-0">
            <?php if (isset($error_message_recebiveis)): ?>
                 <div class="alert alert-warning m-3"><?php echo $error_message_recebiveis; ?></div>
            <?php elseif (empty($recebiveis_para_exibir)): ?>
                <div class="alert alert-info m-3">Nenhum recebível encontrado para esta operação.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center">ID</th>
                                <th class="text-center">Vencimento</th>
                                <th class="text-center">Sacado (Devedor)</th>
                                <th class="text-center">Tipo</th>
                                <th class="text-end">Valor Original</th>
                                <th class="text-end">Capital Aplicado</th>
                                <th class="text-end">Recebido</th>
                                <th class="text-end">Lucro</th>
                                <th class="text-center">Status</th>
                                <th class="text-center" style="width: 110px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="recebiveis-table-body">
                            <?php
                            foreach ($recebiveis_para_exibir as $r):
                            ?>
                                <?php
                                    // Usando os campos recalculados dinamicamente
                                    $dias_para_vencimento = $r['dias_para_vencimento_calc'];
                                    $valor_liquido_recebido_item = $r['valor_liquido_calc_dinamico'];
                                    $lucro_atual_recebivel = $r['lucro_liquido_calc_dinamico'];

                                    // Calcular dias entre hoje e o vencimento (negativo se vencido)
                                    $dias_atraso_hoje = 0;
                                    try {
                                        $_hoje = new DateTime('today');
                                        $_venc = new DateTime($r['data_vencimento']);
                                        $_venc->setTime(0, 0, 0);
                                        $_diff = $_hoje->diff($_venc);
                                        $dias_atraso_hoje = $_venc < $_hoje ? -$_diff->days : $_diff->days;
                                    } catch (Exception $e) {
                                        $dias_atraso_hoje = 0;
                                    }

                                    $rowClass = getTableRowClass($r['status'], $r['data_vencimento'] ?? null);
                                    
                                    // Calcular saldo em aberto e buscar operação compensadora para recebíveis parcialmente compensados
                                    $saldo_aberto = null;
                                    $operacao_compensadora = null;
                                    if ($r['status'] === 'Parcialmente Compensado') {
                                        try {
                                            $stmt_saldo = $pdo->prepare("
                                                SELECT
                                                    r.valor_original,
                                                    COALESCE(SUM(c.valor_compensado), 0) as total_compensado,
                                                    c.operacao_principal_id
                                                FROM recebiveis r
                                                LEFT JOIN compensacoes c ON c.recebivel_compensado_id = r.id
                                                WHERE r.id = ?
                                                GROUP BY r.id, r.valor_original, c.operacao_principal_id
                                            ");
                                            $stmt_saldo->execute([$r['id']]);
                                            $dados_saldo = $stmt_saldo->fetch(PDO::FETCH_ASSOC);
                                            
                                            if ($dados_saldo) {
                                                $saldo_aberto = $dados_saldo['valor_original'] - $dados_saldo['total_compensado'];
                                                $operacao_compensadora = $dados_saldo['operacao_principal_id'];
                                            }
                                        } catch (Exception $e) {
                                            // Em caso de erro, não mostrar saldo
                                            $saldo_aberto = null;
                                            $operacao_compensadora = null;
                                        }
                                    }
                                ?>
                                <?php
                                    $valor_original = (float)$r['valor_original'];
                                    $valor_recebido_db = isset($r['valor_recebido']) && is_numeric($r['valor_recebido']) ? (float)$r['valor_recebido'] : null;
                                    $is_recebido = $r['status'] === 'Recebido';
                                    $is_compensado = in_array($r['status'], ['Compensado', 'Totalmente Compensado']);
                                    $is_vencido_aberto = $dias_atraso_hoje < 0 && !$is_recebido && !$is_compensado;
                                    // Lucro realizado: se recebido com valor diferente, ajusta o lucro pela diferença
                                    $lucro_exibicao = $lucro_atual_recebivel;
                                    if ($is_recebido && $valor_recebido_db !== null) {
                                        $lucro_exibicao = $valor_recebido_db - (float)$valor_liquido_recebido_item;
                                    }
                                    $valor_exibicao = $valor_original;
                                    if ($is_vencido_aberto) {
                                        $calc_corr = calcularValorCorrigido($valor_original, $r['data_vencimento']);
                                        $valor_exibicao = $calc_corr['valor_corrigido'];
                                    } elseif ($is_recebido && $valor_recebido_db !== null) {
                                        $valor_exibicao = $valor_recebido_db;
                                    }
                                ?>
                                <tr id="recebivel-row-<?php echo $r['id']; ?>" class="<?php echo $rowClass; ?>">
                                    <td class="text-center"><?php echo htmlspecialchars($r['id']); ?></td>
                                    <td class="text-center">
                                        <div class="text-nowrap"><?php echo formatHtmlDate($r['data_vencimento']); ?></div>
                                        <?php
                                        if ($is_recebido && !empty($r['data_recebimento'])) {
                                            echo '<div class="small text-success text-nowrap"><i class="bi bi-check-circle"></i> pago em ' . formatHtmlDate($r['data_recebimento']) . '</div>';
                                        } elseif ($is_compensado) {
                                            echo '<div class="small text-muted">—</div>';
                                        } elseif ($dias_atraso_hoje < 0) {
                                            echo '<div class="small text-danger fw-bold text-nowrap">' . abs($dias_atraso_hoje) . ' dias em atraso</div>';
                                        } elseif ($dias_atraso_hoje === 0) {
                                            echo '<div class="small text-warning fw-bold">vence hoje</div>';
                                        } else {
                                            echo '<div class="small text-muted text-nowrap">em ' . htmlspecialchars($dias_atraso_hoje) . ' dias</div>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($r['sacado_id']): ?>
                                            <a href="visualizar_cliente.php?id=<?php echo $r['sacado_id']; ?>" title="Ver/Editar Sacado">
                                                <?php echo htmlspecialchars($r['sacado_nome'] ?? 'Desconhecido'); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($r['sacado_nome'] ?? 'N/A'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo htmlspecialchars($r['tipo_recebivel'] ?? 'N/A'); ?></td>
                                    <td class="text-end">
                                        <?php
                                        if ($is_vencido_aberto) {
                                            // Em atraso e não recebido: mostra original riscado + corrigido até hoje
                                            echo '<div class="text-nowrap"><span class="text-decoration-line-through text-muted small">' . formatHtmlCurrency($valor_original) . '</span></div>';
                                            echo '<div class="text-danger fw-bold text-nowrap" title="Atraso de ' . $calc_corr['dias_atraso'] . ' dias. Juros: ' . formatHtmlCurrency($calc_corr['valor_juros']) . ' / Multa: ' . formatHtmlCurrency($calc_corr['valor_multa']) . '">' . formatHtmlCurrency($calc_corr['valor_corrigido']) . ' <i class="bi bi-info-circle small"></i></div>';
                                        } else {
                                            echo '<div class="text-nowrap">' . formatHtmlCurrency($valor_original) . '</div>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end text-muted text-nowrap"><?php echo formatHtmlCurrency($valor_liquido_recebido_item); ?></td>
                                    <td class="text-end">
                                        <?php
                                        if ($is_recebido && $valor_recebido_db !== null) {
                                            $diferenca = $valor_recebido_db - $valor_original;
                                            if (abs($diferenca) > 0.01) {
                                                $is_acrescimo = $diferenca > 0;
                                                $cor = $is_acrescimo ? 'text-success' : 'text-warning';
                                                $sinal = $is_acrescimo ? '+' : '−';
                                                $tooltip = $is_acrescimo
                                                    ? 'Acréscimo de ' . formatHtmlCurrency(abs($diferenca)) . ' (juros/mora).'
                                                    : 'Desconto de ' . formatHtmlCurrency(abs($diferenca)) . '.';
                                                echo '<div class="' . $cor . ' fw-bold text-nowrap" title="' . htmlspecialchars($tooltip) . '">' . formatHtmlCurrency($valor_recebido_db) . '</div>';
                                                echo '<div class="small ' . $cor . ' text-nowrap">' . $sinal . formatHtmlCurrency(abs($diferenca)) . ' vs original</div>';
                                            } else {
                                                echo '<div class="text-success fw-bold text-nowrap">' . formatHtmlCurrency($valor_recebido_db) . '</div>';
                                            }
                                        } elseif ($is_compensado) {
                                            echo '<div class="text-muted text-nowrap">—</div>';
                                        } else {
                                            echo '<div class="text-muted text-nowrap" style="opacity: 0.55;">' . formatHtmlCurrency(0) . '</div>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="text-nowrap fw-bold <?php echo $lucro_exibicao < 0 ? 'text-danger' : ''; ?>"><?php echo formatHtmlCurrency($lucro_exibicao); ?></div>
                                        <?php if ($is_recebido): ?>
                                            <div class="small text-success text-nowrap"><i class="bi bi-check-circle-fill"></i> realizado</div>
                                        <?php elseif (!$is_compensado): ?>
                                            <div class="small text-muted text-nowrap">projetado</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center status-cell"><?php echo formatHtmlStatus($r['status'], $r['data_recebimento'] ?? null, $saldo_aberto, $operacao_compensadora); ?></td>
                                    <td class="text-center actions-cell">
                                        <?php 
                                        $btn_data_attrs = 'data-id="' . $r['id'] . '" data-status="Recebido" data-valor-original="' . $valor_original . '" data-valor-corrigido="' . $valor_exibicao . '"'; 
                                        if ($r['status'] === 'Em Aberto'): ?>
                                            <button class="btn btn-success action-btn update-status-btn" <?php echo $btn_data_attrs; ?> title="Marcar como Recebido"><i class="bi bi-check-lg"></i></button>
                                            <button class="btn btn-danger action-btn update-status-btn" data-id="<?php echo $r['id']; ?>" data-status="Problema" title="Marcar com Problema"><i class="bi bi-exclamation-triangle-fill"></i></button>
                                        <?php elseif ($r['status'] === 'Parcialmente Compensado'): ?>
                                            <button class="btn btn-success action-btn update-status-btn" <?php echo $btn_data_attrs; ?> title="Marcar como Recebido"><i class="bi bi-check-lg"></i></button>
                                            <button class="btn btn-danger action-btn update-status-btn" data-id="<?php echo $r['id']; ?>" data-status="Problema" title="Marcar com Problema"><i class="bi bi-exclamation-triangle-fill"></i></button>
                                        <?php elseif ($r['status'] === 'Problema'): ?>
                                            <button class="btn btn-success action-btn update-status-btn" <?php echo $btn_data_attrs; ?> title="Marcar como Recebido"><i class="bi bi-check-lg"></i></button>
                                            <button class="btn btn-secondary action-btn update-status-btn" data-id="<?php echo $r['id']; ?>" data-status="Em Aberto" title="Reverter para Em Aberto"><i class="bi bi-arrow-counterclockwise"></i></button>
                                        <?php elseif ($r['status'] === 'Recebido'): ?>
                                            <button class="btn btn-secondary action-btn update-status-btn" data-id="<?php echo $r['id']; ?>" data-status="Em Aberto" title="Reverter para Em Aberto"><i class="bi bi-arrow-counterclockwise"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($operacao['valor_total_compensacao'] > 0): ?>
                            <tr style="background-color: var(--app-warn-soft); color: var(--app-warn);">
                                <td class="text-center">-</td>
                                <td class="text-center">Abatimento</td>
                                <td class="text-center">-</td>
                                <td class="text-center">-</td>
                                <td class="text-end"><?php echo formatHtmlCurrency($operacao['valor_total_compensacao']); ?></td>
                                <td class="text-end"><?php echo formatHtmlCurrency(-$operacao['valor_total_compensacao']); ?></td>
                                <td class="text-end"><?php echo formatHtmlCurrency(0); ?></td>
                                <td class="text-end"><?php echo formatHtmlCurrency(0); ?></td>
                                <td class="text-center"><span class="status-pill s-neutro">Abatido</span></td>
                                <td class="text-center">-</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                         <tfoot class="table-group-divider">
                            <tr>
                                <td colspan="4" class="text-end pe-3"><strong>Totais:</strong></td>
                                <td class="text-end"><strong><?php echo formatHtmlCurrency($totalOriginalCalculado); ?></strong></td>
                                <td class="text-end text-muted"><strong><?php echo formatHtmlCurrency($totalLiquidoPagoCalculado); ?></strong></td>
                                <td class="text-end <?php echo $totalRecebidoCalculado > 0 ? 'text-success' : 'text-muted'; ?>"><strong><?php echo formatHtmlCurrency($totalRecebidoCalculado); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatHtmlCurrency($totalLucroLiquidoCalculado); ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
                </div>
            </div>

            <!-- Seção de Contratos e Assinaturas -->
            <div class="section-card s-cont" id="contratosCard">
                <div class="section-head">
                    <span class="step-num">3</span>
                    <h5 class="mb-0">Contratos e Assinaturas</h5>
                    <?php
                    $statusRaw = $operacao['status_contrato'] ?? 'pendente';
                    $statusMap = [
                        'aguardando_assinatura' => 'Aguardando Assinatura',
                        'assinado' => 'Assinado',
                        'pendente' => 'Pendente'
                    ];
                    $statusDisplay = $statusMap[$statusRaw] ?? ucfirst($statusRaw);
                    $pillClass = ($statusRaw === 'assinado') ? 's-concluida' : 's-parcial';
                    ?>
                    <span class="status-pill <?php echo $pillClass; ?>" id="statusContratoBadge">
                        Status: <?php echo htmlspecialchars($statusDisplay); ?>
                    </span>
                </div>
                <div class="section-body">
                    <div class="mb-3">
                        <button type="button" class="btn btn-primary btn-sm me-2" id="btnGerarContratos">
                            <i class="bi bi-file-earmark-text"></i> Gerar Contratos
                        </button>
                        <button type="button" class="btn btn-success btn-sm" id="btnAnexarAssinado">
                            <i class="bi bi-upload"></i> Anexar Assinado
                        </button>
                        <input type="file" id="inputAnexarAssinado" accept=".pdf" style="display: none;">
                    </div>
                    
                    <div id="contratosLoading" class="text-center my-3" style="display: none;">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div> Carregando documentos...
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle" id="tabelaContratos" style="display: none;">
                            <thead class="table-light">
                                <tr>
                                    <th>Documento</th>
                                    <th>Data</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="listaContratos">
                                <!-- Preenchido via AJAX -->
                            </tbody>
                        </table>
                    </div>
                    <div id="contratosVazio" class="text-muted small">Nenhum contrato gerado ou anexado ainda.</div>
                </div>
            </div>

            <!-- Seção de Arquivos da Operação -->
            <div class="section-card s-anex">
                <div class="section-head">
                    <span class="step-num">4</span>
                    <h5 class="mb-0">Documentos Anexados</h5>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="adicionarArquivosBtn">
                        <i class="bi bi-plus-circle"></i> Adicionar Arquivos
                    </button>
                </div>
                <div class="section-body">
                    <div id="arquivos-loading" class="text-center" style="display: none;">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                        <p class="mt-2">Carregando arquivos...</p>
                    </div>
                    
                    <div id="arquivos-container">
                        <div id="arquivos-lista" class="row"></div>
                        <div id="arquivos-vazio" class="text-center text-muted" style="display: none;">
                            <i class="bi bi-file-earmark-x" style="font-size: 3rem;"></i>
                            <p class="mt-2">Nenhum documento anexado a esta operação.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal para Upload de Arquivos -->
            <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="uploadModalLabel">Adicionar Documentos</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="uploadForm" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label for="arquivos" class="form-label">Selecionar Arquivos</label>
                                    <input type="file" class="form-control" id="arquivos" name="arquivos[]" multiple
                                           accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.txt">
                                    <div class="form-text">
                                        Tipos aceitos: PDF, JPG, PNG, GIF, WebP, DOC, DOCX, XLS, XLSX, TXT<br>
                                        Tamanho máximo por arquivo: 10MB | Máximo: 20 arquivos por operação
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="descricaoArquivos" class="form-label">Descrição (opcional)</label>
                                    <textarea class="form-control" id="descricaoArquivos" rows="3"
                                              placeholder="Descrição dos documentos anexados..."></textarea>
                                </div>
                                <div id="arquivos-preview" class="mt-3" style="display: none;">
                                    <h6>Arquivos Selecionados:</h6>
                                    <div id="arquivos-list" class="list-group"></div>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" id="uploadBtn" disabled>
                                <i class="bi bi-cloud-upload"></i> Enviar Arquivos
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Seção de Anotações -->
            <div class="section-card s-anot">
                <div class="section-head">
                    <span class="step-num">5</span>
                    <h5 class="mb-0">Anotações</h5>
                    <span class="head-meta"><?php echo count($anotacoes ?? []); ?> registro<?php echo count($anotacoes ?? []) === 1 ? '' : 's'; ?></span>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#novaAnotacaoModal">
                        <i class="bi bi-plus-circle"></i> Nova Anotação
                    </button>
                </div>
                <div class="section-body">
                    <?php if (isset($error_message_anotacoes)): ?>
                        <div class="alert alert-danger"><?php echo $error_message_anotacoes; ?></div>
                    <?php elseif (empty($anotacoes)): ?>
                        <div class="text-center text-muted">
                            <i class="bi bi-journal-x" style="font-size: 3rem;"></i>
                            <p class="mt-2">Nenhuma anotação registrada para esta operação.</p>
                        </div>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($anotacoes as $anotacao): ?>
                                <div class="card mb-3 shadow-sm border-0 bg-light">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between mb-2">
                                            <div>
                                                <strong><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($anotacao['usuario_nome']); ?></strong>
                                                <small class="text-muted ms-2">
                                                    <i class="bi bi-clock"></i> <?php echo date('d/m/Y H:i', strtotime($anotacao['data_criacao'])); ?>
                                                </small>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if ($anotacao['recebivel_id']): ?>
                                                    <span class="status-pill s-aberto" title="Vinculado ao Recebível #<?php echo $anotacao['recebivel_id']; ?>">
                                                        <?php echo htmlspecialchars(ucfirst($anotacao['recebivel_tipo'] ?? 'Recebível')); ?> #<?php echo $anotacao['recebivel_id']; ?>
                                                        <?php if (!empty($anotacao['recebivel_vencimento'])): ?>
                                                            | Venc: <?php echo formatHtmlDate($anotacao['recebivel_vencimento']); ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($anotacao['recebivel_valor'])): ?>
                                                            | <?php echo formatHtmlCurrency($anotacao['recebivel_valor']); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-pill s-neutro">Geral</span>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger border-0 ms-2" onclick="apagarAnotacao(<?php echo $anotacao['id']; ?>)" title="Excluir Anotação">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="anotacao-content">
                                            <!-- The content is HTML generated by Quill, so we output it directly -->
                                            <?php echo $anotacao['anotacao']; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php
            // Determinar o estado civil do devedor para controle do campo "Cônjuge vai Assinar?"
            $devedorEstadoCivil = '';
            if ($isEmprestimo && !empty($recebiveis_db)) {
                $devedorEstadoCivil = $recebiveis_db[0]['sacado_representante_estado_civil'] ?? '';
            } elseif (!$isEmprestimo && !empty($operacao['cedente_id'])) {
                $devedorEstadoCivil = $operacao['cedente_representante_estado_civil'] ?? '';
            }
            $devedorEhCasado = in_array($devedorEstadoCivil, ['Casado(a)'], true);
            ?>

            <!-- Modal para Gerar Contratos -->
            <div class="modal fade" id="modalGerarContrato" tabindex="-1" aria-labelledby="modalGerarContratoLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="modalGerarContratoLabel">Gerar Contratos</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="formGerarContrato">
                                <input type="hidden" name="operacao_id" value="<?php echo htmlspecialchars($operacao_id); ?>">
                                
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Natureza da Operação</label>
                                        <select class="form-select" name="natureza" id="modalNatureza" required disabled>
                                            <option value="EMPRESTIMO" <?php echo $isEmprestimo ? 'selected' : ''; ?>>Empréstimo</option>
                                            <option value="DESCONTO" <?php echo !$isEmprestimo ? 'selected' : ''; ?>>Antecipação (Cessão)</option>
                                        </select>
                                        <input type="hidden" name="natureza" value="<?php echo $isEmprestimo ? 'EMPRESTIMO' : 'DESCONTO'; ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Porte do Cliente (Cedente)</label>
                                        <?php 
                                            // Buscar porte atual do cedente se possível, para pré-selecionar
                                            $porteAtual = '';
                                            if ($operacao['cedente_id']) {
                                                try {
                                                    $stmt_ced = $pdo->prepare("SELECT porte FROM clientes WHERE id = ?");
                                                    $stmt_ced->execute([$operacao['cedente_id']]);
                                                    $porteAtual = $stmt_ced->fetchColumn();
                                                } catch (Exception $e) {}
                                            }
                                        ?>
                                        <select class="form-select" name="porte_cliente" id="modalPorteCliente" required>
                                            <option value="">Selecione...</option>
                                            <option value="MEI" <?php echo $porteAtual === 'MEI' ? 'selected' : ''; ?>>MEI → até R$ 81 mil</option>
                                            <option value="ME" <?php echo $porteAtual === 'ME' ? 'selected' : ''; ?>>ME → até R$ 360 mil</option>
                                            <option value="EPP" <?php echo $porteAtual === 'EPP' ? 'selected' : ''; ?>>EPP → até R$ 4,8 milhões</option>
                                        </select>

                                    </div>
                                    <div class="col-md-12" id="garantiaToggleSection" style="display: none;">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold d-block">Cliente ofereceu garantia?</label>
                                                <div class="btn-group w-100" role="group" aria-label="Garantia real">
                                                    <input type="radio" class="btn-check" name="tem_garantia_real" id="modalTemGarantiaRealSim" value="1">
                                                    <label class="btn btn-outline-primary" for="modalTemGarantiaRealSim">Sim</label>
                                                    <input type="radio" class="btn-check" name="tem_garantia_real" id="modalTemGarantiaRealNao" value="0" checked>
                                                    <label class="btn btn-outline-primary" for="modalTemGarantiaRealNao">Não</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold d-block">Tipo da Garantia</label>
                                                <select class="form-select" name="tipo_garantia" id="modalTipoGarantia">
                                                    <option value="veiculo" selected>Veículo</option>
                                                    <option value="bem_movel">Outro bem móvel</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold d-block">O sacado tem avalista?</label>
                                                <div class="btn-group w-100" role="group" aria-label="Avalista">
                                                    <input type="radio" class="btn-check" name="tem_avalista" id="modalTemAvalistaSim" value="1">
                                                    <label class="btn btn-outline-primary" for="modalTemAvalistaSim">Sim</label>
                                                    <input type="radio" class="btn-check" name="tem_avalista" id="modalTemAvalistaNao" value="0" checked>
                                                    <label class="btn btn-outline-primary" for="modalTemAvalistaNao">Não</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold d-block">Cônjuge vai Assinar?</label>
                                                <select class="form-select" name="conjuge_assina" id="modalConjugeAssina">
                                                    <option value="0" selected>Não</option>
                                                    <option value="1">Sim (Incluir no contrato)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div id="garantiasContainer" style="display: none;">
                                    <!-- Secao Avalista -->
                                    <div id="avalistaContainer" style="display: none;">
                                        <h6 class="border-bottom pb-2 mb-3">Dados do Avalista</h6>
                                        <div class="row g-3 mb-4">
                                            <div class="col-md-6">
                                                <label class="form-label">Nome Completo</label>
                                                <input type="text" class="form-control req-avalista" name="avalista_nome">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">CPF</label>
                                                <input type="text" class="form-control req-avalista cpf-mask" name="avalista_cpf" placeholder="000.000.000-00">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">RG</label>
                                                <input type="text" class="form-control req-avalista" name="avalista_rg">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Nacionalidade</label>
                                                <input type="text" class="form-control req-avalista" name="avalista_nacionalidade" value="brasileiro(a)">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Estado Civil</label>
                                                <select class="form-select req-avalista" name="avalista_estado_civil" id="avalistaEstadoCivil">
                                                    <option value="Solteiro(a)">Solteiro(a)</option>
                                                    <option value="Casado(a)">Casado(a)</option>
                                                    <option value="Divorciado(a)">Divorciado(a)</option>
                                                    <option value="Viúvo(a)">Viúvo(a)</option>
                                                    <option value="União Estável">União Estável</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Profissão</label>
                                                <input type="text" class="form-control req-avalista" name="avalista_profissao">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Endereço Completo</label>
                                                <input type="text" class="form-control req-avalista" name="avalista_endereco">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">E-mail</label>
                                                <input type="email" class="form-control req-avalista" name="avalista_email">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">WhatsApp</label>
                                                <input type="text" class="form-control req-avalista phone-mask" name="avalista_whatsapp" placeholder="(00) 00000-0000">
                                            </div>
                                        </div>

                                        <div id="conjugeSection" style="display: none;">
                                            <h6 class="border-bottom pb-2 mb-3">Dados do Cônjuge (Anuente)</h6>
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-4">
                                                    <label class="form-label">Regime de Casamento</label>
                                                    <select class="form-select req-conjuge" name="avalista_regime_casamento">
                                                        <option value="">Selecione...</option>
                                                        <option value="Comunhão Parcial de Bens">Comunhão Parcial de Bens</option>
                                                        <option value="Comunhão Universal de Bens">Comunhão Universal de Bens</option>
                                                        <option value="Separação Total de Bens">Separação Total de Bens</option>
                                                        <option value="Participação Final nos Aquestos">Participação Final nos Aquestos</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">Nome do Cônjuge</label>
                                                    <input type="text" class="form-control req-conjuge" name="avalista_conjuge_nome">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">CPF do Cônjuge</label>
                                                    <input type="text" class="form-control req-conjuge cpf-mask" name="avalista_conjuge_cpf" placeholder="000.000.000-00">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Secao Veículo -->
                                    <div id="veiculoContainer" style="display: none;">
                                        <h6 class="border-bottom pb-2 mb-3">Dados do Veículo (Garantia Mútuo)</h6>
                                        <div class="row g-3 mb-4">
                                            <div class="col-md-3">
                                                <label class="form-label">Marca</label>
                                                <input type="text" class="form-control req-veiculo" name="veiculo_marca">
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label">Modelo</label>
                                                <input type="text" class="form-control req-veiculo" name="veiculo_modelo">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Ano Fab.</label>
                                                <input type="number" class="form-control req-veiculo" name="veiculo_ano_fab">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Ano Mod.</label>
                                                <input type="number" class="form-control" name="veiculo_ano_mod">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Cor</label>
                                                <input type="text" class="form-control req-veiculo" name="veiculo_cor">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Placa</label>
                                                <input type="text" class="form-control req-veiculo" name="veiculo_placa">
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label">RENAVAM</label>
                                                <input type="text" class="form-control req-veiculo" name="veiculo_renavam">
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label">Chassi</label>
                                                <input type="text" class="form-control req-veiculo" name="veiculo_chassi">
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label">Município de Registro</label>
                                                <input type="text" class="form-control req-veiculo" name="veiculo_municipio_registro">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">UF</label>
                                                <input type="text" class="form-control req-veiculo" name="veiculo_uf" maxlength="2">
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label">Valor de Avaliação (R$)</label>
                                                <input type="number" step="0.01" class="form-control req-veiculo" name="veiculo_valor_avaliacao">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Secao Bem Movel -->
                                    <div id="bemMovelContainer" style="display: none;">
                                        <h6 class="border-bottom pb-2 mb-3">Dados do Bem Móvel (Garantia Mútuo)</h6>
                                        <div class="row g-3 mb-4">
                                            <div class="col-md-4">
                                                <label class="form-label">Tipo / Categoria</label>
                                                <input type="text" class="form-control req-bem" name="bem_tipo" placeholder="Máquina, equipamento, mercadoria...">
                                            </div>
                                            <div class="col-md-8">
                                                <label class="form-label">Descrição Detalhada</label>
                                                <input type="text" class="form-control req-bem" name="bem_descricao_detalhada" placeholder="Descreva o bem oferecido em garantia">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Identificadores Únicos</label>
                                                <input type="text" class="form-control req-bem" name="bem_identificadores" placeholder="Série, patrimônio, lote, IMEI...">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Local de Guarda</label>
                                                <input type="text" class="form-control req-bem" name="bem_local_guarda" placeholder="Onde o bem ficará armazenado">
                                            </div>
                                            <div class="col-md-8">
                                                <label class="form-label">Documentos de Origem</label>
                                                <input type="text" class="form-control" name="bem_documentos_origem" placeholder="Nota fiscal, recibo, contrato de compra...">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Valor de Avaliação (R$)</label>
                                                <input type="number" step="0.01" class="form-control req-bem" name="bem_valor_avaliacao">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="gerarContratoError" class="alert alert-danger" style="display: none;"></div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" id="btnConfirmarGerarContratos">
                                <i class="bi bi-file-earmark-check"></i> Confirmar e Gerar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal para Excluir Contrato -->
            <div class="modal fade" id="modalExcluirContrato" tabindex="-1" aria-labelledby="modalExcluirContratoLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title" id="modalExcluirContratoLabel"><i class="bi bi-exclamation-triangle-fill"></i> Confirmar Exclusão</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Tem certeza que deseja apagar o seguinte documento?</p>
                            <p class="fw-bold" id="excluirContratoNome"></p>
                            <p class="text-danger small mb-0"><i class="bi bi-info-circle"></i> Esta ação removerá o arquivo fisicamente do servidor e não pode ser desfeita. O status da operação será recalculado automaticamente.</p>
                            <input type="hidden" id="excluirContratoId">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-danger" id="btnConfirmarExcluirContrato">
                                <i class="bi bi-trash"></i> Sim, Apagar Arquivo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal para Nova Anotação -->
            <div class="modal fade" id="novaAnotacaoModal" tabindex="-1" aria-labelledby="novaAnotacaoModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="novaAnotacaoModalLabel">Nova Anotação</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="formNovaAnotacao">
                                <input type="hidden" id="anotacaoOperacaoId" value="<?php echo htmlspecialchars($operacao_id); ?>">
                                
                                <div class="mb-3">
                                    <label for="anotacaoRecebivelId" class="form-label">Associar a</label>
                                    <select class="form-select" id="anotacaoRecebivelId">
                                        <option value="">Geral (Operação)</option>
                                        <?php foreach ($recebiveis_para_exibir as $r): ?>
                                            <option value="<?php echo $r['id']; ?>">
                                                Recebível #<?php echo $r['id']; ?> (<?php echo htmlspecialchars(ucfirst($r['tipo_recebivel'] ?? 'N/A')); ?>) - Venc: <?php echo formatHtmlDate($r['data_vencimento']); ?> - <?php echo formatHtmlCurrency($r['valor_original']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Anotação</label>
                                    <!-- Quill Editor Container -->
                                    <div id="quillEditor" style="height: 200px;"></div>
                                </div>
                            </form>
                            <div id="anotacaoError" class="alert alert-danger" style="display: none;"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" id="btnSalvarAnotacao">
                                <i class="bi bi-save"></i> Salvar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$isEmprestimo): ?>
            <!-- Modal para Pré-visualização de Notificação -->
            <?php
            // Lê config.json para sugerir CC com o e-mail da credora
            $_cfgEmail = [];
            $_cfgPath = __DIR__ . '/config.json';
            if (file_exists($_cfgPath)) {
                $_cfgEmail = json_decode(file_get_contents($_cfgPath), true) ?: [];
            }
            $_defaultCc = trim((string)($_cfgEmail['resend_cc_email'] ?? ''));
            if ($_defaultCc === '') {
                $_defaultCc = trim((string)($_cfgEmail['empresa_email'] ?? ''));
            }
            $_defaultBcc = trim((string)($_cfgEmail['resend_bcc_email'] ?? ''));
            ?>
            <div class="modal fade" id="previewNotificacaoModal" tabindex="-1" aria-labelledby="previewNotificacaoModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="previewNotificacaoModalLabel">Pré-visualização do E-mail para Sacados</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div id="previewNotificacaoLoading" class="text-center" style="display: none;">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Carregando...</span>
                                </div>
                                <p class="mt-2">Gerando pré-visualização...</p>
                            </div>
                            <div id="previewNotificacaoContent" style="display: none;">
                                <div class="mb-3">
                                    <label for="selectSacadoPreview" class="form-label">Selecione o Sacado para visualizar o e-mail que ele receberá:</label>
                                    <select class="form-select" id="selectSacadoPreview"></select>
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-md-6">
                                        <label for="emailCcInput" class="form-label small mb-1">CC <span class="text-muted">(separados por vírgula)</span></label>
                                        <input type="text" class="form-control form-control-sm" id="emailCcInput"
                                               value="<?php echo htmlspecialchars($_defaultCc); ?>"
                                               placeholder="copia@dominio.com, outro@dominio.com">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="emailBccInput" class="form-label small mb-1">BCC <span class="text-muted">(separados por vírgula)</span></label>
                                        <input type="text" class="form-control form-control-sm" id="emailBccInput"
                                               value="<?php echo htmlspecialchars($_defaultBcc); ?>"
                                               placeholder="oculto@dominio.com">
                                    </div>
                                </div>
                                <div class="border rounded p-4 bg-light shadow-sm" style="min-height: 400px; overflow-y: auto;">
                                    <iframe id="iframePreviewEmail" style="width: 100%; min-height: 500px; border: none; background: #fff;"></iframe>
                                </div>
                            </div>
                            <div id="previewNotificacaoError" class="alert alert-danger" style="display: none;"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-success" id="confirmarDisparoBtn">
                                <i class="bi bi-send-fill"></i> Confirmar e Enviar E-mails
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div id="status-feedback" class="mt-2 mb-3" style="min-height: 1.5em;"></div>

            <div class="section-card s-flux">
                <div class="section-head">
                    <span class="step-num">6</span>
                    <h5 class="mb-0">Fluxo de Caixa por Mês</h5>
                    <span class="head-meta">capital, retorno e lucro</span>
                </div>
                <div class="section-body">
                    <?php if (empty($recebiveis_para_exibir) && $totalLiquidoPagoCalculado == 0): ?>
                        <div class="alert alert-info text-center mb-0">Nenhum dado para gerar o gráfico.</div>
                    <?php else: ?>
                        <div class="chart-wrapper"><canvas id="fluxoCaixaChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>

            <form id="analiseInternaForm" method="POST" action="gera_analise_interna_pdf.php" target="_blank" style="display:none;">
                <input type="hidden" name="operacao_id" value="<?php echo htmlspecialchars($operacao['id']); ?>">
                <input type="hidden" name="chartImageData" id="chartImageData">
            </form>

            </div><!-- /col-xl-8 -->

            <!-- ===== STICKY PANEL ===== -->
            <div class="col-xl-4">
                <?php
                // KPIs do painel sticky
                // Usa os totais recalculados na página (consistente com o detalhamento acima);
                // as colunas _calc do banco ficam NULL em operações antigas e zeravam o resumo.
                $kpiTotalNominal = (float)$totalOriginalCalculado;
                $kpiLiquidoPago  = (float)$totalLiquidoPagoCalculado;
                $kpiLucro        = (float)$totalLucroLiquidoCalculado;
                $kpiTaxa         = (float)($operacao['taxa_mensal'] ?? 0) * 100;
                $kpiIof          = (float)($totalIOFTeoricoCalculado ?? ($operacao['iof_total_calc'] ?? 0));
                // Saldo em aberto e dias médios calculados a partir dos recebíveis
                $kpiSaldoAberto = 0; $kpiQtdAberto = 0;
                $kpiDiasSoma = 0; $kpiDiasCount = 0;
                if (!empty($recebiveis_para_exibir)) {
                    foreach ($recebiveis_para_exibir as $rk) {
                        $stk = $rk['status'] ?? '';
                        $vk  = (float)($rk['valor_original'] ?? 0);
                        if ($stk === 'Em Aberto' || $stk === 'Problema') {
                            $kpiSaldoAberto += $vk;
                            $kpiQtdAberto++;
                        } elseif ($stk === 'Parcialmente Compensado') {
                            $kpiSaldoAberto += $vk;
                            $kpiQtdAberto++;
                        }
                        if (!empty($rk['dias_para_vencimento_calc'])) {
                            $kpiDiasSoma += (float)$rk['dias_para_vencimento_calc'];
                            $kpiDiasCount++;
                        }
                    }
                }
                $kpiDiasMedios = $kpiDiasCount > 0 ? round($kpiDiasSoma / $kpiDiasCount) : 0;
                $kpiMargem = $kpiTotalNominal > 0 ? ($kpiLucro / $kpiTotalNominal) * 100 : 0;

                // Progresso financeiro
                $kpiRecebido        = (float)$totalRecebidoCalculado;
                $kpiCapitalAplicado = (float)$totalLiquidoPagoCalculado; // Investimento inicial total
                $kpiCapitalRecup    = (float)$capitalRecuperadoCalculado; // Capital já recuperado dos títulos recebidos
                $kpiLucroRealizado  = $kpiRecebido - $kpiCapitalRecup;   // Lucro travado dos recebimentos
                $kpiFaltaBreakEven  = max(0, $kpiCapitalAplicado - $kpiRecebido);
                $kpiPctRecuperacao  = $kpiCapitalAplicado > 0 ? min(100, ($kpiRecebido / $kpiCapitalAplicado) * 100) : 0;
                $kpiPctNominal      = $kpiTotalNominal > 0 ? ($kpiRecebido / $kpiTotalNominal) * 100 : 0;
                $kpiBreakEvenAtingido = $kpiCapitalAplicado > 0 && $kpiRecebido >= $kpiCapitalAplicado;
                ?>
                <div class="sticky-panel">
                    <div class="panel-head">
                        <h3><i class="bi bi-bar-chart-fill"></i> Resumo da Operação</h3>
                        <span class="id-pill">#<?php echo (int)$operacao['id']; ?></span>
                    </div>

                    <div class="summary-hero">
                        <div class="h-label">Lucro líquido <?php echo $statusAgg === 'Concluída' ? 'realizado' : 'projetado'; ?></div>
                        <div class="h-value"><?php echo formatHtmlCurrency($kpiLucro); ?></div>
                        <div class="h-sub">
                            margem <?php echo number_format($kpiMargem, 1, ',', '.'); ?>%
                            <?php if ($kpiDiasMedios > 0): ?>
                                · <?php echo $kpiDiasMedios; ?> dias médios
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="summary-grid">
                        <div class="group-label"><i class="bi bi-graph-up"></i> Projeção da operação</div>
                        <div class="summary-cell profit span2">
                            <div class="c-label"><i class="bi bi-arrow-up-right"></i> Margem</div>
                            <div class="c-value"><?php echo number_format($kpiMargem, 2, ',', '.'); ?>%</div>
                        </div>
                        <div class="summary-cell">
                            <div class="c-label"><?php echo $isEmprestimo ? 'Valor a receber' : 'Total nominal'; ?></div>
                            <div class="c-value"><?php echo formatHtmlCurrency($kpiTotalNominal); ?></div>
                        </div>
                        <div class="summary-cell">
                            <div class="c-label"><?php echo $isEmprestimo ? 'Valor liberado' : 'Capital aplicado'; ?></div>
                            <div class="c-value"><?php echo formatHtmlCurrency($kpiCapitalAplicado); ?></div>
                        </div>
                        <div class="summary-cell">
                            <div class="c-label">Taxa</div>
                            <div class="c-value"><?php echo number_format($kpiTaxa, 2, ',', '.'); ?>%</div>
                        </div>
                        <div class="summary-cell">
                            <div class="c-label">Dias médios</div>
                            <div class="c-value"><?php echo $kpiDiasMedios > 0 ? $kpiDiasMedios : '—'; ?></div>
                        </div>

                        <?php if ($kpiCapitalAplicado > 0): ?>
                        <div class="group-label is-realizado"><i class="bi bi-cash-coin"></i> Realizado até agora</div>
                        <div class="summary-cell span2">
                            <div class="c-label"><i class="bi bi-graph-up-arrow"></i> Progresso financeiro</div>
                            <div class="c-value">
                                <?php echo formatHtmlCurrency($kpiRecebido); ?> recebido
                                <span class="small text-muted ms-1"><?php echo number_format($kpiPctNominal, 1, ',', '.'); ?>% do nominal</span>
                            </div>
                            <div class="progress-recup" title="<?php echo number_format($kpiPctRecuperacao, 1, ',', '.'); ?>% do capital aplicado recuperado">
                                <div class="progress-recup-bar" style="width: <?php echo number_format($kpiPctRecuperacao, 2, '.', ''); ?>%"></div>
                            </div>
                            <div class="progress-breakdown">
                                <span>Capital recup.: <b><?php echo formatHtmlCurrency($kpiCapitalRecup); ?></b></span>
                                <span>Lucro realiz.: <b class="<?php echo $kpiLucroRealizado > 0 ? 'text-success' : ''; ?>"><?php echo formatHtmlCurrency($kpiLucroRealizado); ?></b></span>
                            </div>
                            <div class="progress-status <?php echo $kpiBreakEvenAtingido ? 'ok' : 'pending'; ?>">
                                <?php if ($kpiBreakEvenAtingido): ?>
                                    <i class="bi bi-check-circle-fill"></i> Break-even atingido — todo recebimento agora é lucro líquido.
                                <?php else: ?>
                                    <i class="bi bi-arrow-clockwise"></i> Faltam <b><?php echo formatHtmlCurrency($kpiFaltaBreakEven); ?></b> em recebimentos para empatar o capital aplicado.
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($kpiSaldoAberto > 0): ?>
                        <div class="summary-cell span2">
                            <div class="c-label"><i class="bi bi-hourglass-split"></i> Saldo em aberto</div>
                            <div class="c-value">
                                <?php echo formatHtmlCurrency($kpiSaldoAberto); ?>
                                <span class="small text-muted ms-1"><?php echo $kpiQtdAberto; ?> título<?php echo $kpiQtdAberto === 1 ? '' : 's'; ?></span>
                            </div>
                        </div>
                        <?php elseif ($kpiIof > 0): ?>
                        <div class="summary-cell span2">
                            <div class="c-label"><i class="bi bi-receipt"></i> IOF total</div>
                            <div class="c-value"><?php echo formatHtmlCurrency($kpiIof); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="panel-actions">
                        <button id="editarOperacaoBtnSticky" class="btn btn-outline-secondary"
                                onclick="document.getElementById('editarOperacaoBtn').click();">
                            <i class="bi bi-pencil-square"></i> Editar Operação
                        </button>
                        <div class="btn-row">
                            <button id="gerarAnaliseInternaBtn" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-file-earmark-pdf"></i> Análise PDF
                            </button>
                            <a href="gerar_recibo_cliente.php?id=<?php echo htmlspecialchars($operacao['id']); ?>"
                               target="_blank" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-receipt"></i> Recibo PDF
                            </a>
                        </div>
                        <?php if (!$isEmprestimo): ?>
                            <button class="btn btn-success"
                                    onclick="document.getElementById('notificarSacadosBtn').click();">
                                <i class="bi bi-envelope-fill"></i> Notificar Sacados
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /col-xl-4 -->

            </div><!-- /row -->

        <?php endif; ?>

    </div>

    <script>
    // Notificação de Sacados - Novo fluxo com preview
    document.addEventListener('DOMContentLoaded', function() {
        const btnNotificar = document.getElementById('notificarSacadosBtn');
        let previewModal;
        if (document.getElementById('previewNotificacaoModal')) {
            previewModal = new bootstrap.Modal(document.getElementById('previewNotificacaoModal'));
        }
        
        let currentPreviews = [];
        let currentOperacaoId = null;

        if (btnNotificar) {
            btnNotificar.addEventListener('click', function() {
                currentOperacaoId = this.getAttribute('data-operacao-id');
                
                // Mostrar modal de loading
                const loading = document.getElementById('previewNotificacaoLoading');
                const content = document.getElementById('previewNotificacaoContent');
                const errorDiv = document.getElementById('previewNotificacaoError');
                const btnConfirmar = document.getElementById('confirmarDisparoBtn');
                
                loading.style.display = 'block';
                content.style.display = 'none';
                errorDiv.style.display = 'none';
                btnConfirmar.disabled = true;
                
                previewModal.show();

                fetch('preview_notificacao_sacados.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ operacao_id: currentOperacaoId })
                })
                .then(response => response.json())
                .then(data => {
                    loading.style.display = 'none';
                    if (data.success && data.previews && data.previews.length > 0) {
                        currentPreviews = data.previews;
                        const select = document.getElementById('selectSacadoPreview');
                        select.innerHTML = '';
                        
                        data.previews.forEach((p, index) => {
                            const option = document.createElement('option');
                            option.value = index;
                            option.textContent = p.sacado + (p.email ? ` (${p.email})` : ' - Sem E-mail Cadastrado');
                            select.appendChild(option);
                        });
                        
                        select.onchange = function() {
                            const p = currentPreviews[this.value];
                            const iframe = document.getElementById('iframePreviewEmail');
                            iframe.srcdoc = p.html_body;
                        };
                        
                        // Selecionar o primeiro
                        select.selectedIndex = 0;
                        select.dispatchEvent(new Event('change'));
                        
                        content.style.display = 'block';
                        btnConfirmar.disabled = false;
                    } else {
                        errorDiv.textContent = data.error || 'Nenhum sacado encontrado para notificar.';
                        errorDiv.style.display = 'block';
                    }
                })
                .catch(error => {
                    loading.style.display = 'none';
                    errorDiv.textContent = 'Erro na requisição de preview: ' + error.message;
                    errorDiv.style.display = 'block';
                });
            });
        }
        
        const btnConfirmar = document.getElementById('confirmarDisparoBtn');
        if (btnConfirmar) {
            btnConfirmar.addEventListener('click', function() {
                if (!currentOperacaoId) return;
                
                const originalHtml = this.innerHTML;
                this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Enviando...';
                this.disabled = true;

                const ccInput  = document.getElementById('emailCcInput');
                const bccInput = document.getElementById('emailBccInput');
                const ccVal  = ccInput  ? ccInput.value.trim()  : '';
                const bccVal = bccInput ? bccInput.value.trim() : '';

                fetch('notificar_sacados.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        operacao_id: currentOperacaoId,
                        cc: ccVal,
                        bcc: bccVal
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Sucesso: ' + data.mensagem);
                        previewModal.hide();
                    } else {
                        alert('Erro ao enviar notificações: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Erro na requisição: ' + error.message);
                })
                .finally(() => {
                    this.innerHTML = originalHtml;
                    this.disabled = false;
                });
            });
        }
    });

    let myFluxoChart = null;

    function formatCurrencyJS(value) {
        if (typeof value === 'number') {
            return 'R$ ' + value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        return '--';
    }

    // Passamos os três conjuntos de dados separados para o JS
    const chartLabels = <?php echo json_encode($chartLabels); ?>;
    const chartDataCapitalEmprestado = <?php echo json_encode($chartDataCapitalEmprestado); ?>;
    const chartDataCapitalRetornado = <?php echo json_encode($chartDataCapitalRetornado); ?>;
    const chartDataLucro = <?php echo json_encode($chartDataLucro); ?>;
    const operacaoId = <?php echo json_encode($operacao_id); ?>;

    function updateChart(labels, dataEmprestado, dataRetornado, dataLucro) {
        if (myFluxoChart) {
            myFluxoChart.destroy();
            myFluxoChart = null;
        }

        // Adicionada validação mais robusta para todos os arrays de dados
        if (!labels || !Array.isArray(labels) || labels.length === 0 ||
            !dataEmprestado || !Array.isArray(dataEmprestado) || dataEmprestado.length === 0 || dataEmprestado.length !== labels.length ||
            !dataRetornado || !Array.isArray(dataRetornado) || dataRetornado.length === 0 || dataRetornado.length !== labels.length ||
            !dataLucro || !Array.isArray(dataLucro) || dataLucro.length === 0 || dataLucro.length !== labels.length
        ) {
            console.warn("Dados inválidos ou inconsistentes para o gráfico. Gráfico não será renderizado.");
            const chartCanvasElement = document.getElementById('fluxoCaixaChart');
            if(chartCanvasElement){
                const ctx = chartCanvasElement.getContext('2d');
                if(ctx) ctx.clearRect(0, 0, chartCanvasElement.width, chartCanvasElement.height);
            }
            return;
        }

        const chartCanvasElement = document.getElementById('fluxoCaixaChart');
        if (!chartCanvasElement) {
            console.error("Canvas do gráfico não encontrado!");
            return;
        }
        const ctx = chartCanvasElement.getContext('2d');
        if (!ctx) {
            console.error("Contexto 2D do gráfico não obtido.");
            return;
        }
        try {
            myFluxoChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels, // Agora 'labels' já contém os nomes dos meses
                    datasets: [
                        {
                            label: 'Capital Emprestado (Saída)',
                            data: dataEmprestado,
                            backgroundColor: 'rgba(108, 117, 125, 0.7)', // Cinza
                            borderColor: 'rgba(108, 117, 125, 1)',
                            borderWidth: 1,
                            stack: 'Stack 0'
                        },
                        {
                            label: 'Capital Retornado (Base)',
                            data: dataRetornado,
                            backgroundColor: 'rgba(0, 123, 255, 0.7)', // Azul
                            borderColor: 'rgba(0, 123, 255, 1)',
                            borderWidth: 1,
                            stack: 'Stack 1'
                        },
                        {
                            label: 'Lucro Líquido (Adicional)',
                            data: dataLucro,
                            backgroundColor: 'rgba(25, 135, 84, 0.7)', // Verde
                            borderColor: 'rgba(25, 135, 84, 1)',
                            borderWidth: 1,
                            stack: 'Stack 1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            ticks: {
                                callback: function(value, index, ticks) {
                                    const mesAno = this.chart.data.labels[index];
                                    const lucroMes = dataLucro[index] || 0;
                                    const capitalRetornado = dataRetornado[index] || 0;
                                    
                                    // Função para formatar moeda
                                    function formatCurrency(value) {
                                        return value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 0, maximumFractionDigits: 0 });
                                    }
                                    
                                    return [
                                        mesAno,
                                        'Lucro: ' + formatCurrency(lucroMes),
                                        'Capital Retornado: ' + formatCurrency(capitalRetornado)
                                    ];
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            stacked: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 0, maximumFractionDigits: 0 });
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    // O título do tooltip será o próprio label do mês
                                    return context[0].label;
                                },
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed.y !== null) {
                                        label += context.parsed.y.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        } catch (chartError) {
            console.error("Erro Chart.js:", chartError);
        }
    }

    // --- Lógica para o status do Recebível (copiada de listar_recebiveis.php) ---
    document.addEventListener('DOMContentLoaded', function () {
        const feedbackDiv = document.getElementById('status-feedback');
        const tableBody = document.getElementById('recebiveis-table-body');
        const modalRecebimento = new bootstrap.Modal(document.getElementById('modalRecebimento'));
        const btnConfirmar = document.getElementById('btnConfirmarRecebimento');

        function performStatusUpdate(recebivelId, newStatus, valorRecebido = null) {
            const row = document.getElementById('recebivel-row-' + recebivelId);
            if (!row) {
                console.error('Elemento da linha não encontrado:', 'recebivel-row-' + recebivelId);
                return;
            }

            const statusCell = row.querySelector('.status-cell');
            const actionsCell = row.querySelector('.actions-cell');
            if (!statusCell || !actionsCell) {
                 console.error('Célula de status ou ações não encontrada na linha:', row);
                return;
            }

            feedbackDiv.innerHTML = '<div class="spinner-border spinner-border-sm text-primary" role="status"><span class="visually-hidden">Atualizando...</span></div> Atualizando status...';
            feedbackDiv.className = 'alert alert-info';

            let bodyParams = 'id=' + encodeURIComponent(recebivelId) + '&status=' + encodeURIComponent(newStatus);
            if (valorRecebido !== null) {
                bodyParams += '&valor_recebido=' + encodeURIComponent(valorRecebido);
            }

            fetch('atualizar_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: bodyParams
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Erro HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                const closeButtonHtml = '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';

                 if (data.success && typeof data.newStatusHtml !== 'undefined' && typeof data.newActionsHtml !== 'undefined' && typeof data.newRowClass !== 'undefined') {
                    statusCell.innerHTML = data.newStatusHtml;
                    actionsCell.innerHTML = data.newActionsHtml;
                    row.className = data.newRowClass;
                    feedbackDiv.innerHTML = `Status do recebível ${recebivelId} atualizado para ${newStatus}. ${closeButtonHtml}`;
                    feedbackDiv.className = 'alert alert-success alert-dismissible fade show';

                    window.location.reload(); // Recarrega para refletir todos os cálculos atualizados

                } else {
                    feedbackDiv.innerHTML = `Erro ao atualizar status: ${data.message || 'Dados de resposta incompletos ou falha no servidor.'} ${closeButtonHtml}`;
                    feedbackDiv.className = 'alert alert-danger alert-dismissible fade show';
                }
            })
            .catch(error => {
                console.error('Erro no catch:', error);
                const closeButtonHtml = '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                feedbackDiv.innerHTML = `Erro na comunicação ou processamento da resposta. Ver Console (F12). [${error.message}] ${closeButtonHtml}`;
                feedbackDiv.className = 'alert alert-danger alert-dismissible fade show';
            });
        }

        if (tableBody) {
            tableBody.addEventListener('click', function(event) {
                const button = event.target.closest('.update-status-btn');
                if (!button) return;

                const recebivelId = button.dataset.id;
                const newStatus = button.dataset.status;
                
                if (newStatus === 'Recebido') {
                    const valorOriginal = button.dataset.valorOriginal;
                    const valorCorrigido = button.dataset.valorCorrigido;
                    
                    if (valorOriginal && valorCorrigido) {
                        document.getElementById('modal_recebivel_id').value = recebivelId;
                        document.getElementById('modal_new_status').value = newStatus;
                        document.getElementById('modal_valor_original').value = 'R$ ' + parseFloat(valorOriginal).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        
                        if (parseFloat(valorCorrigido) > parseFloat(valorOriginal)) {
                            document.getElementById('div_valor_corrigido').style.display = 'block';
                            document.getElementById('modal_valor_corrigido').value = 'R$ ' + parseFloat(valorCorrigido).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        } else {
                            document.getElementById('div_valor_corrigido').style.display = 'none';
                        }
                        
                        document.getElementById('modal_valor_recebido').value = parseFloat(valorCorrigido).toFixed(2);
                        
                        modalRecebimento.show();
                        return;
                    }
                }

                performStatusUpdate(recebivelId, newStatus);
            });
        }
        
        if (btnConfirmar) {
            btnConfirmar.addEventListener('click', function() {
                const recebivelId = document.getElementById('modal_recebivel_id').value;
                const newStatus = document.getElementById('modal_new_status').value;
                const valorRecebido = document.getElementById('modal_valor_recebido').value;
                
                if (!valorRecebido || parseFloat(valorRecebido) < 0) {
                    alert('Por favor, informe um valor recebido válido.');
                    return;
                }
                
                modalRecebimento.hide();
                performStatusUpdate(recebivelId, newStatus, valorRecebido);
            });
        }

        // --- Lógica para Gerar Análise Interna PDF (mantida) ---
        const gerarAnaliseInternaBtn = document.getElementById('gerarAnaliseInternaBtn');
        const analiseInternaForm = document.getElementById('analiseInternaForm');
        const chartImageDataInput = document.getElementById('chartImageData');
        const chartCanvasElement = document.getElementById('fluxoCaixaChart');

        // Se não houver dados para o gráfico, desabilita o botão
        if (chartLabels.length > 0 && (chartDataCapitalEmprestado.some(val => val > 0) || chartDataCapitalRetornado.some(val => val > 0) || chartDataLucro.some(val => val > 0))) {
            updateChart(chartLabels, chartDataCapitalEmprestado, chartDataCapitalRetornado, chartDataLucro);
        } else {
             gerarAnaliseInternaBtn.disabled = true;
             gerarAnaliseInternaBtn.title = 'Nenhum dado financeiro para gerar análise de fluxo de caixa.';
             const chartWrapper = document.querySelector('.chart-wrapper');
             if(chartWrapper) chartWrapper.innerHTML = '<div class="alert alert-info text-center">Nenhum dado para gerar o gráfico de fluxo de caixa.</div>';
        }

        if (gerarAnaliseInternaBtn) {
            gerarAnaliseInternaBtn.addEventListener('click', function() {
                if (gerarAnaliseInternaBtn.disabled) {
                    console.log("[Gerar Análise Interna] Botão desabilitado.");
                    return;
                }
                console.log("[Gerar Análise Interna] Botão clicado. Gerando imagem...");

                chartImageDataInput.value = '';
                if (myFluxoChart && chartCanvasElement) {
                    try {
                        if (myFluxoChart.isDestroyed) {
                            updateChart(chartLabels, chartDataCapitalEmprestado, chartDataCapitalRetornado, chartDataLucro);
                        }
                        chartImageDataInput.value = myFluxoChart.toBase64Image('image/png', 1.0);
                        if (!chartImageDataInput.value || !chartImageDataInput.value.startsWith('data:image/png;base64,')) {
                            console.error("[Gerar Análise Interna] Erro: Dados base64 inválidos gerados pelo Chart.js.");
                            alert("Erro ao gerar imagem do gráfico. O PDF será gerado sem o gráfico.");
                            chartImageDataInput.value = '';
                        } else {
                            console.log("[Gerar Análise Interna] Imagem Base64 gerada OK.");
                        }
                    } catch (e) {
                        console.error("[Gerar Análise Interna] Erro CATASTRÓFICO ao gerar Base64:", e);
                        alert("Erro inesperado ao preparar gráfico. O PDF será gerado sem o gráfico.");
                        chartImageDataInput.value = '';
                    }
                } else {
                    console.log("[Gerar Análise Interna] Gráfico não renderizado ou sem dados. Imagem não será enviada.");
                    chartImageDataInput.value = '';
                }

                console.log("[Gerar Análise Interna] Submetendo formulário para gera_analise_interna_pdf.php...");
                analiseInternaForm.submit();
            });
        }

        // Event listener para o botão Editar Operação
        const editarOperacaoBtn = document.getElementById('editarOperacaoBtn');
        if (editarOperacaoBtn) {
            editarOperacaoBtn.addEventListener('click', function() {
                window.location.href = 'editar_operacao.php?id=' + operacaoId;
            });
        }

        // --- Funcionalidade de Arquivos ---
        let uploadModal;
        const adicionarArquivosBtn = document.getElementById('adicionarArquivosBtn');
        const arquivosInput = document.getElementById('arquivos');
        const arquivosPreview = document.getElementById('arquivos-preview');
        const arquivosList = document.getElementById('arquivos-list');
        const descricaoArquivos = document.getElementById('descricaoArquivos');
        const uploadBtn = document.getElementById('uploadBtn');
        const arquivosLoading = document.getElementById('arquivos-loading');
        const arquivosContainer = document.getElementById('arquivos-container');
        const arquivosListaDiv = document.getElementById('arquivos-lista');
        const arquivosVazio = document.getElementById('arquivos-vazio');

        // Inicializar modal
        uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));

        // Carregar arquivos ao carregar a página
        carregarArquivosOperacao(operacaoId);

        // Event listeners
        adicionarArquivosBtn.addEventListener('click', function() {
            uploadModal.show();
        });

        arquivosInput.addEventListener('change', function() {
            const files = this.files;
            if (files.length === 0) {
                arquivosPreview.style.display = 'none';
                uploadBtn.disabled = true;
                return;
            }

            arquivosList.innerHTML = '';
            let totalSize = 0;
            let hasErrors = false;

            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                totalSize += file.size;
                
                const listItem = document.createElement('div');
                listItem.className = 'list-group-item d-flex justify-content-between align-items-center';
                
                let statusClass = 'text-success';
                let statusIcon = 'bi-check-circle';
                let statusText = 'OK';
                
                // Validações
                if (file.size > 10 * 1024 * 1024) { // 10MB
                    statusClass = 'text-danger';
                    statusIcon = 'bi-x-circle';
                    statusText = 'Muito grande (>10MB)';
                    hasErrors = true;
                }
                
                const allowedTypes = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
                const extension = file.name.split('.').pop().toLowerCase();
                if (!allowedTypes.includes(extension)) {
                    statusClass = 'text-danger';
                    statusIcon = 'bi-x-circle';
                    statusText = 'Tipo não permitido';
                    hasErrors = true;
                }

                listItem.innerHTML = `
                    <div>
                        <strong>${file.name}</strong><br>
                        <small class="text-muted">${formatFileSize(file.size)}</small>
                    </div>
                    <span class="${statusClass}">
                        <i class="bi ${statusIcon}"></i> ${statusText}
                    </span>
                `;
                
                arquivosList.appendChild(listItem);
            }

            // Mostrar total
            const totalItem = document.createElement('div');
            totalItem.className = 'list-group-item bg-light';
            totalItem.innerHTML = `<strong>Total: ${files.length} arquivo(s) - ${formatFileSize(totalSize)}</strong>`;
            arquivosList.appendChild(totalItem);

            arquivosPreview.style.display = 'block';
            uploadBtn.disabled = hasErrors || files.length === 0;
        });

        uploadBtn.addEventListener('click', async function() {
            const files = arquivosInput.files;
            
            if (files.length === 0) {
                alert('Selecione arquivos para enviar.');
                return;
            }

            const originalText = uploadBtn.innerHTML;
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';

            try {
                const formData = new FormData();
                formData.append('operacao_id', operacaoId);
                
                for (let i = 0; i < files.length; i++) {
                    formData.append('arquivos[]', files[i]);
                    formData.append('descricao[]', descricaoArquivos.value || '');
                }

                const response = await fetch('upload_arquivos.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    alert(`${result.total_uploaded} arquivo(s) enviado(s) com sucesso!`);
                    
                    // Limpar formulário
                    arquivosInput.value = '';
                    descricaoArquivos.value = '';
                    arquivosPreview.style.display = 'none';
                    
                    // Fechar modal
                    uploadModal.hide();
                    
                    // Recarregar lista de arquivos
                    carregarArquivosOperacao(operacaoId);
                } else {
                    let errorMsg = result.error || 'Erro desconhecido no upload.';
                    if (result.errors && result.errors.length > 0) {
                        errorMsg += '\n\nDetalhes:\n' + result.errors.join('\n');
                    }
                    alert('Erro no upload:\n' + errorMsg);
                }

            } catch (error) {
                console.error('Erro no upload:', error);
                alert('Erro de comunicação durante o upload: ' + error.message);
            } finally {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = originalText;
            }
        });

        // Função para carregar arquivos da operação
        async function carregarArquivosOperacao(operacaoId) {
            arquivosLoading.style.display = 'block';
            arquivosContainer.style.display = 'none';

            try {
                const response = await fetch(`listar_arquivos.php?operacao_id=${operacaoId}`);
                const result = await response.json();

                if (result.success) {
                    renderizarArquivos(result.arquivos);
                } else {
                    console.error('Erro ao carregar arquivos:', result.error);
                    arquivosVazio.innerHTML = '<p class="text-danger">Erro ao carregar arquivos: ' + result.error + '</p>';
                    arquivosVazio.style.display = 'block';
                }
            } catch (error) {
                console.error('Erro na comunicação:', error);
                arquivosVazio.innerHTML = '<p class="text-danger">Erro de comunicação ao carregar arquivos.</p>';
                arquivosVazio.style.display = 'block';
            } finally {
                arquivosLoading.style.display = 'none';
                arquivosContainer.style.display = 'block';
            }
        }

        // Função para renderizar lista de arquivos
        function renderizarArquivos(arquivos) {
            arquivosListaDiv.innerHTML = '';

            if (arquivos.length === 0) {
                arquivosVazio.style.display = 'block';
                return;
            }

            arquivosVazio.style.display = 'none';

            arquivos.forEach(arquivo => {
                const col = document.createElement('div');
                col.className = 'col-md-6 col-lg-4 mb-3';

                const card = document.createElement('div');
                card.className = 'card h-100';

                // Definir a miniatura com base no tipo de arquivo
                let miniaturaHtml = '';
                if (arquivo.is_image) {
                    miniaturaHtml = `<div style="width: 60px; height: 60px; overflow: hidden; border-radius: 4px; display: flex; align-items: center; justify-content: center; background-color: var(--app-surface-2);">
                                        <img src="${arquivo.download_url}" alt="Miniatura" style="max-width: 100%; max-height: 100%; object-fit: cover; cursor: pointer;" onclick="verImagem('${arquivo.download_url}', '${arquivo.nome_original}')">
                                     </div>`;
                } else if (arquivo.is_pdf) {
                    miniaturaHtml = `<i class="bi bi-file-earmark-pdf-fill" style="font-size: 3rem; color: var(--app-danger);"></i>`; // Ícone PDF vermelho
                } else {
                    miniaturaHtml = `<i class="bi ${arquivo.icone}" style="font-size: 3rem; color: var(--app-neutral);"></i>`;
                }
                
                let verBotaoHtml = '';
                if (arquivo.pode_visualizar) {
                    if (arquivo.is_image) {
                        verBotaoHtml = `<button type="button" onclick="verImagem('${arquivo.download_url}', '${arquivo.nome_original}')" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-eye"></i> Ver
                                        </button>`;
                    } else {
                        verBotaoHtml = `<a href="${arquivo.download_url}" target="_blank" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-eye"></i> Ver
                                        </a>`;
                    }
                }

                card.innerHTML = `
                    <div class="card-body">
                        <div class="d-flex align-items-start">
                            <div class="me-3">
                                ${miniaturaHtml}
                            </div>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <h6 class="card-title mb-1 text-truncate" title="${arquivo.nome_original}">${arquivo.nome_original}</h6>
                                <p class="card-text mb-1">
                                    <small class="text-muted">
                                        ${arquivo.tamanho_formatado}<br>
                                        ${arquivo.data_upload_formatada}<br>
                                        ${arquivo.usuario_upload || 'Sistema'}
                                    </small>
                                </p>
                                ${arquivo.descricao ? `<p class="card-text text-truncate"><small title="${arquivo.descricao}">${arquivo.descricao}</small></p>` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent">
                        <div class="btn-group w-100" role="group">
                            ${verBotaoHtml}
                            <a href="${arquivo.download_url}&download=1" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-download"></i> Download
                            </a>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="excluirArquivo(${arquivo.id}, '${arquivo.nome_original}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                `;

                col.appendChild(card);
                arquivosListaDiv.appendChild(col);
            });
        }

        // Função para visualizar imagem com Viewer.js
        window.verImagem = function(url, nome) {
            const img = new Image();
            img.src = url;
            img.alt = nome;
            const viewer = new Viewer(img, {
                hidden: function () {
                    viewer.destroy();
                },
                toolbar: {
                    zoomIn: 1,
                    zoomOut: 1,
                    oneToOne: 1,
                    reset: 1,
                    prev: 0,
                    play: 0,
                    next: 0,
                    rotateLeft: 1,
                    rotateRight: 1,
                    flipHorizontal: 1,
                    flipVertical: 1,
                },
                navbar: false,
                title: true,
                button: true,
                backdrop: true,
                className: 'viewer-90-percent' // Classe opcional para estilização customizada se precisar
            });
            viewer.show();
        };

        // Função para excluir arquivo
        window.excluirArquivo = async function(arquivoId, nomeArquivo) {
            if (!confirm(`Tem certeza que deseja excluir o arquivo "${nomeArquivo}"?`)) {
                return;
            }

            try {
                const response = await fetch('excluir_arquivo.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${arquivoId}`
                });

                const result = await response.json();

                if (result.success) {
                    alert('Arquivo excluído com sucesso!');
                    carregarArquivosOperacao(operacaoId);
                } else {
                    alert('Erro ao excluir arquivo: ' + result.error);
                }
            } catch (error) {
                console.error('Erro ao excluir arquivo:', error);
                alert('Erro de comunicação ao excluir arquivo.');
            }
        };

        // Função para formatar tamanho de arquivo
        function formatFileSize(bytes) {
            if (bytes >= 1073741824) {
                return (bytes / 1073741824).toFixed(2) + ' GB';
            } else if (bytes >= 1048576) {
                return (bytes / 1048576).toFixed(2) + ' MB';
            } else if (bytes >= 1024) {
                return (bytes / 1024).toFixed(2) + ' KB';
            } else {
                return bytes + ' bytes';
            }
        }
    });
    </script>
    <script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
    <!-- Modal Recebimento -->
    <div class="modal fade" id="modalRecebimento" tabindex="-1" aria-labelledby="modalRecebimentoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalRecebimentoLabel">Confirmar Recebimento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="modal_recebivel_id">
                    <input type="hidden" id="modal_new_status">
                    
                    <div class="mb-3">
                        <label class="form-label">Valor Original:</label>
                        <input type="text" class="form-control" id="modal_valor_original" readonly disabled>
                    </div>
                    
                    <div class="mb-3" id="div_valor_corrigido">
                        <label class="form-label text-danger">Valor Corrigido (com Juros e Mora):</label>
                        <input type="text" class="form-control text-danger fw-bold" id="modal_valor_corrigido" readonly disabled>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Valor Recebido:</label>
                        <div class="input-group">
                            <span class="input-group-text">R$</span>
                            <input type="number" step="0.01" min="0" class="form-control" id="modal_valor_recebido" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmarRecebimento">Confirmar Recebimento</button>
                </div>
            </div>
        </div>
    </div>

    <!-- bootstrap bundle já carregado pelo head.php -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.11.6/viewer.min.js"></script>
    
    <script>
    // Inicialização do Quill Editor e lógica de anotações
    document.addEventListener('DOMContentLoaded', function() {
        const quill = new Quill('#quillEditor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline', 'strike'],
                    ['blockquote', 'code-block'],
                    [{ 'header': 1 }, { 'header': 2 }],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    [{ 'script': 'sub'}, { 'script': 'super' }],
                    [{ 'indent': '-1'}, { 'indent': '+1' }],
                    [{ 'direction': 'rtl' }],
                    [{ 'size': ['small', false, 'large', 'huge'] }],
                    [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
                    [{ 'color': [] }, { 'background': [] }],
                    [{ 'font': [] }],
                    [{ 'align': [] }],
                    ['clean'],
                    ['link', 'image']
                ]
            }
        });

        const btnSalvarAnotacao = document.getElementById('btnSalvarAnotacao');
        const errorDiv = document.getElementById('anotacaoError');
        let anotacaoModal;
        if (document.getElementById('novaAnotacaoModal')) {
            anotacaoModal = new bootstrap.Modal(document.getElementById('novaAnotacaoModal'));
        }

        if (btnSalvarAnotacao) {
            btnSalvarAnotacao.addEventListener('click', function() {
                const operacaoId = document.getElementById('anotacaoOperacaoId').value;
                const recebivelId = document.getElementById('anotacaoRecebivelId').value;
                const anotacaoHtml = quill.root.innerHTML;
                const anotacaoText = quill.getText().trim();

                if (anotacaoText.length === 0) {
                    errorDiv.textContent = "A anotação não pode estar vazia.";
                    errorDiv.style.display = 'block';
                    return;
                }

                errorDiv.style.display = 'none';
                
                const originalHtml = this.innerHTML;
                this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Salvando...';
                this.disabled = true;

                const formData = new FormData();
                formData.append('operacao_id', operacaoId);
                if (recebivelId) formData.append('recebivel_id', recebivelId);
                formData.append('anotacao', anotacaoHtml);

                fetch('ajax_salvar_anotacao.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reload the page to show the new annotation
                        window.location.reload();
                    } else {
                        errorDiv.textContent = data.message || 'Erro ao salvar a anotação.';
                        errorDiv.style.display = 'block';
                    }
                })
                .catch(error => {
                    errorDiv.textContent = 'Erro na requisição: ' + error.message;
                    errorDiv.style.display = 'block';
                })
                .finally(() => {
                    this.innerHTML = originalHtml;
                    this.disabled = false;
                });
            });
        }
    });

    // Função para excluir anotação
    function apagarAnotacao(id) {
        if (confirm("Tem certeza que deseja excluir esta anotação?")) {
            const formData = new FormData();
            formData.append('id', id);

            fetch('excluir_anotacao.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Erro ao excluir a anotação.');
                }
            })
            .catch(error => {
                alert('Erro na requisição: ' + error.message);
            });
        }
    }

    // --- Lógica de Contratos e Assinaturas ---
    document.addEventListener('DOMContentLoaded', function() {
        const btnGerarContratos = document.getElementById('btnGerarContratos');
        const btnAnexarAssinado = document.getElementById('btnAnexarAssinado');
        const inputAnexarAssinado = document.getElementById('inputAnexarAssinado');
        const listaContratos = document.getElementById('listaContratos');
        const tabelaContratos = document.getElementById('tabelaContratos');
        const contratosVazio = document.getElementById('contratosVazio');
        const contratosLoading = document.getElementById('contratosLoading');
        const statusContratoBadge = document.getElementById('statusContratoBadge');
        
        let modalExcluirContrato = null;
        if (document.getElementById('modalExcluirContrato')) {
            modalExcluirContrato = new bootstrap.Modal(document.getElementById('modalExcluirContrato'));
        }
        
        // Event delegation para os botões de excluir contrato
        listaContratos.addEventListener('click', function(e) {
            const btnExcluir = e.target.closest('.btn-excluir-contrato');
            if (btnExcluir) {
                const docId = btnExcluir.getAttribute('data-id');
                const docNome = btnExcluir.getAttribute('data-nome');
                
                document.getElementById('excluirContratoId').value = docId;
                document.getElementById('excluirContratoNome').textContent = docNome;
                
                if (modalExcluirContrato) {
                    modalExcluirContrato.show();
                }
            }
        });

        const btnConfirmarExcluirContrato = document.getElementById('btnConfirmarExcluirContrato');
        if (btnConfirmarExcluirContrato) {
            btnConfirmarExcluirContrato.addEventListener('click', function() {
                const docId = document.getElementById('excluirContratoId').value;
                if (!docId) return;

                const originalHtml = this.innerHTML;
                this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Apagando...';
                this.disabled = true;

                const formData = new FormData();
                formData.append('operacao_id', operacaoId);
                formData.append('documento_id', docId);

                fetch('api_contratos.php?action=delete', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Contrato apagado com sucesso!');
                        modalExcluirContrato.hide();
                        carregarContratos();
                    } else {
                        alert('Erro ao apagar contrato: ' + (data.error || data.message || 'Erro desconhecido'));
                    }
                })
                .catch(error => {
                    alert('Erro na requisição: ' + error.message);
                })
                .finally(() => {
                    this.innerHTML = originalHtml;
                    this.disabled = false;
                });
            });
        }
        
        function carregarContratos() {
            contratosLoading.style.display = 'block';
            tabelaContratos.style.display = 'none';
            contratosVazio.style.display = 'none';
            
            fetch('api_contratos.php?action=listar&operacao_id=' + operacaoId)
            .then(response => response.json())
            .then(data => {
                contratosLoading.style.display = 'none';
                if (data.success && data.documentos && data.documentos.length > 0) {
                    listaContratos.innerHTML = '';
                    data.documentos.forEach(doc => {
                        const tr = document.createElement('tr');
                        
                        let icone = 'bi-file-earmark-text';
                        let cor = 'text-primary';
                        if (doc.nome_arquivo.toLowerCase().endsWith('.pdf')) {
                            icone = 'bi-file-earmark-pdf-fill';
                            cor = 'text-danger';
                        }
                        
                        tr.innerHTML = `
                            <td><i class="bi ${icone} ${cor}"></i> <a href="${doc.caminho_arquivo}" target="_blank" class="text-decoration-none">${doc.nome_arquivo}</a></td>
                            <td>${doc.data_geracao || '-'}</td>
                            <td class="text-end text-nowrap">
                                <a href="${doc.caminho_arquivo}" target="_blank" class="btn btn-sm btn-outline-secondary" title="Visualizar/Baixar"><i class="bi bi-download"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-1 btn-excluir-contrato" data-id="${doc.id}" data-nome="${doc.nome_arquivo}" title="Excluir Arquivo"><i class="bi bi-trash"></i></button>
                            </td>
                        `;
                        listaContratos.appendChild(tr);
                    });
                    tabelaContratos.style.display = 'table';
                } else {
                    contratosVazio.style.display = 'block';
                }
                
                // Atualizar o status no badge se retornado
                if (data.status_contrato) {
                    const statusMap = {
                        'aguardando_assinatura': 'Aguardando Assinatura',
                        'assinado': 'Assinado',
                        'pendente': 'Pendente'
                    };
                    const statusDisplay = statusMap[data.status_contrato] || data.status_contrato;
                    statusContratoBadge.textContent = 'Status: ' + statusDisplay;
                    statusContratoBadge.className = 'status-pill ' + (data.status_contrato === 'assinado' ? 's-concluida' : 's-parcial');
                }
            })
            .catch(error => {
                console.error('Erro ao carregar contratos:', error);
                contratosLoading.style.display = 'none';
                contratosVazio.innerHTML = '<span class="text-danger">Erro ao carregar os documentos.</span>';
                contratosVazio.style.display = 'block';
            });
        }

        if (btnGerarContratos) {
            btnGerarContratos.addEventListener('click', function() {
                const modal = new bootstrap.Modal(document.getElementById('modalGerarContrato'));
                modal.show();
                
                // Trigger change to set initial visibility
                document.getElementById('modalNatureza').dispatchEvent(new Event('change'));
                document.getElementById('avalistaEstadoCivil').dispatchEvent(new Event('change'));
                
                // Controlar habilitação do campo "Cônjuge vai Assinar?" com base no estado civil do devedor
                atualizarCampoConjugeAssina();
            });
        }
        
        function atualizarCampoConjugeAssina() {
            const modalConjugeAssina = document.getElementById('modalConjugeAssina');
            if (!modalConjugeAssina) return;
            
            const devedorEhCasado = <?php echo $devedorEhCasado ? 'true' : 'false'; ?>;
            
            if (devedorEhCasado) {
                modalConjugeAssina.disabled = false;
                modalConjugeAssina.classList.remove('bg-light');
            } else {
                modalConjugeAssina.disabled = true;
                modalConjugeAssina.classList.add('bg-light');
                modalConjugeAssina.value = '0';
            }
        }
        
        // CPF/CNPJ Mask function
        function applyCpfCnpjMask(input) {
            let value = input.value.replace(/\D/g, '');
            if (value.length <= 11) {
                // CPF
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            } else {
                // CNPJ
                if (value.length > 14) value = value.slice(0, 14);
                value = value.replace(/(\d{2})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d)/, '$1/$2');
                value = value.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
            }
            input.value = value;
        }

        document.querySelectorAll('.cpf-mask, .cnpj-mask').forEach(input => {
            input.addEventListener('input', function() {
                applyCpfCnpjMask(this);
            });
        });

        // Phone Mask function
        function applyPhoneMask(input) {
            let value = input.value.replace(/\D/g, '');
            if (value.length > 11) value = value.slice(0, 11);
            if (value.length <= 10) {
                value = value.replace(/(\d{2})(\d)/, '($1) $2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
            } else {
                value = value.replace(/(\d{2})(\d)/, '($1) $2');
                value = value.replace(/(\d{5})(\d)/, '$1-$2');
            }
            input.value = value;
        }

        document.querySelectorAll('.phone-mask').forEach(input => {
            input.addEventListener('input', function() {
                applyPhoneMask(this);
            });
        });

        const modalNatureza = document.getElementById('modalNatureza');
        const garantiaToggleSection = document.getElementById('garantiaToggleSection');
        const garantiasContainer = document.getElementById('garantiasContainer');
        const avalistaContainer = document.getElementById('avalistaContainer');
        const veiculoContainer = document.getElementById('veiculoContainer');
        const bemMovelContainer = document.getElementById('bemMovelContainer');
        const conjugeSection = document.getElementById('conjugeSection');
        const modalTipoGarantia = document.getElementById('modalTipoGarantia');
        const modalTemGarantiaRealInputs = document.querySelectorAll('input[name="tem_garantia_real"]');
        const modalTemAvalistaInputs = document.querySelectorAll('input[name="tem_avalista"]');

        function obterValorRadioSelecionado(inputs) {
            const selecionado = Array.from(inputs).find(input => input.checked);
            return selecionado ? selecionado.value : '0';
        }

        function atualizarCamposEmprestimo() {
            const temGarantiaReal = obterValorRadioSelecionado(modalTemGarantiaRealInputs) === '1';
            const temAvalista = obterValorRadioSelecionado(modalTemAvalistaInputs) === '1';
            const tipoGarantia = modalTipoGarantia ? modalTipoGarantia.value : 'veiculo';

            garantiasContainer.style.display = 'none';
            avalistaContainer.style.display = 'none';
            veiculoContainer.style.display = 'none';
            bemMovelContainer.style.display = 'none';

            avalistaContainer.querySelectorAll('.req-avalista, .req-conjuge').forEach(input => input.required = false);
            veiculoContainer.querySelectorAll('.req-veiculo').forEach(input => input.required = false);
            bemMovelContainer.querySelectorAll('.req-bem').forEach(input => input.required = false);

            if (modalTipoGarantia) {
                modalTipoGarantia.disabled = !temGarantiaReal;
            }

            if (temGarantiaReal || temAvalista) {
                garantiasContainer.style.display = 'block';
            }

            if (temAvalista) {
                avalistaContainer.style.display = 'block';
                const avalistaNome = avalistaContainer.querySelector('input[name="avalista_nome"]');
                const avalistaCpf = avalistaContainer.querySelector('input[name="avalista_cpf"]');
                if (avalistaNome) avalistaNome.required = true;
                if (avalistaCpf) avalistaCpf.required = true;
            }

            if (temGarantiaReal) {
                if (tipoGarantia === 'bem_movel') {
                    bemMovelContainer.style.display = 'block';
                    bemMovelContainer.querySelectorAll('.req-bem').forEach(input => input.required = true);
                } else {
                    veiculoContainer.style.display = 'block';
                    veiculoContainer.querySelectorAll('.req-veiculo').forEach(input => input.required = true);
                }
            }

            document.getElementById('avalistaEstadoCivil').dispatchEvent(new Event('change'));
        }

        // Show/hide sections based on selections
        modalNatureza.addEventListener('change', function() {
            if (this.value === 'EMPRESTIMO') {
                garantiaToggleSection.style.display = 'block';
                atualizarCamposEmprestimo();
            } else {
                garantiaToggleSection.style.display = 'none';
                garantiasContainer.style.display = 'none';
                avalistaContainer.style.display = 'none';
                veiculoContainer.style.display = 'none';
                bemMovelContainer.style.display = 'none';
                conjugeSection.style.display = 'none';
                garantiasContainer.querySelectorAll('.req-avalista, .req-veiculo, .req-bem, .req-conjuge').forEach(input => input.required = false);
            }
        });

        modalTemGarantiaRealInputs.forEach(input => input.addEventListener('change', atualizarCamposEmprestimo));
        modalTemAvalistaInputs.forEach(input => input.addEventListener('change', atualizarCamposEmprestimo));
        if (modalTipoGarantia) {
            modalTipoGarantia.addEventListener('change', atualizarCamposEmprestimo);
        }

        document.getElementById('avalistaEstadoCivil').addEventListener('change', function() {
            const isAvalistaVisible = document.getElementById('avalistaContainer').style.display !== 'none';
            
            if ((this.value === 'Casado(a)' || this.value === 'União Estável') && isAvalistaVisible) {
                conjugeSection.style.display = 'block';
                conjugeSection.querySelectorAll('.req-conjuge').forEach(input => input.required = true);
            } else {
                conjugeSection.style.display = 'none';
                conjugeSection.querySelectorAll('.req-conjuge').forEach(input => input.required = false);
            }
        });

        const btnConfirmarGerarContratos = document.getElementById('btnConfirmarGerarContratos');
        if (btnConfirmarGerarContratos) {
            btnConfirmarGerarContratos.addEventListener('click', function() {
                const form = document.getElementById('formGerarContrato');
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                const originalHtml = this.innerHTML;
                this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Gerando...';
                this.disabled = true;

                const formData = new FormData(form);
                
                // Garantir que a natureza seja enviada se estiver desabilitada no select
                const naturezaValue = modalNatureza.value || form.querySelector('input[name="natureza"][type="hidden"]')?.value;
                if (naturezaValue) {
                    formData.set('natureza', naturezaValue);
                }

                if (naturezaValue === 'EMPRESTIMO') {
                    formData.set('tem_garantia_real', obterValorRadioSelecionado(modalTemGarantiaRealInputs));
                    formData.set('tem_avalista', obterValorRadioSelecionado(modalTemAvalistaInputs));
                    formData.set('tipo_garantia', modalTipoGarantia ? modalTipoGarantia.value : 'veiculo');
                } else {
                    formData.set('tem_garantia_real', '0');
                    formData.set('tem_avalista', '0');
                    formData.set('tipo_garantia', '');
                    formData.set('conjuge_assina', '0');
                }

                fetch('api_contratos.php?action=gerar', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Contratos gerados com sucesso!');
                        bootstrap.Modal.getInstance(document.getElementById('modalGerarContrato')).hide();
                        carregarContratos();
                    } else {
                        const errorDiv = document.getElementById('gerarContratoError');
                        errorDiv.textContent = 'Erro ao gerar contratos: ' + (data.error || data.message || 'Erro desconhecido');
                        errorDiv.style.display = 'block';
                    }
                })
                .catch(error => {
                    const errorDiv = document.getElementById('gerarContratoError');
                    errorDiv.textContent = 'Erro na requisição: ' + error.message;
                    errorDiv.style.display = 'block';
                })
                .finally(() => {
                    this.innerHTML = originalHtml;
                    this.disabled = false;
                });
            });
        }

        if (modalNatureza.value === 'EMPRESTIMO') {
            garantiaToggleSection.style.display = 'block';
            atualizarCamposEmprestimo();
        } else if (modalTipoGarantia) {
            modalTipoGarantia.disabled = true;
        }

        if (btnAnexarAssinado && inputAnexarAssinado) {
            btnAnexarAssinado.addEventListener('click', function() {
                inputAnexarAssinado.click();
            });

            inputAnexarAssinado.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    const file = this.files[0];
                    
                    const originalHtml = btnAnexarAssinado.innerHTML;
                    btnAnexarAssinado.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Enviando...';
                    btnAnexarAssinado.disabled = true;

                    const formData = new FormData();
                    formData.append('operacao_id', operacaoId);
                    formData.append('contrato_assinado', file);

                    fetch('api_contratos.php?action=upload', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Documento assinado anexado com sucesso!');
                            carregarContratos();
                        } else {
                            alert('Erro ao enviar documento: ' + (data.error || data.message || 'Erro desconhecido'));
                        }
                    })
                    .catch(error => {
                        alert('Erro na requisição: ' + error.message);
                    })
                    .finally(() => {
                        btnAnexarAssinado.innerHTML = originalHtml;
                        btnAnexarAssinado.disabled = false;
                        inputAnexarAssinado.value = '';
                    });
                }
            });
        }
        
        // Carregar na inicialização
        if (typeof operacaoId !== 'undefined') {
            carregarContratos();
        }
    });
    </script>
</body>
</html>
