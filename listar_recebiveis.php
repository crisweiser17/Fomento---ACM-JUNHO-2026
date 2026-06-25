<?php require_once 'auth_check.php'; ?>
<?php
// --- APENAS PARA DEBUG - REMOVER/COMENTAR EM PRODUÇÃO ---
ini_set('display_errors', 1); // Descomente para ver erros no navegador
ini_set('display_startup_errors', 1); // Descomente para ver erros de inicialização
error_reporting(E_ALL); // Descomente para reportar todos os tipos de erros
// --- FIM DEBUG ---

require_once 'db_connection.php'; // Conexão $pdo
require_once 'funcoes_compensacao.php';
require_once 'funcoes_calculo_central.php';
require_once 'functions.php'; // Funções auxiliares

// --- Configurações e Parâmetros ---
$items_per_page_options = [25, 50, 100];
$items_per_page = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $items_per_page_options) ? (int)$_GET['per_page'] : 25;
$results_per_page = $items_per_page;
setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'portuguese');

// Parâmetros GET (Paginação, Ordenação, Filtros Existentes, Busca)
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'data_vencimento'; // Padrão
$dir = isset($_GET['dir']) && in_array(strtolower($_GET['dir']), ['asc', 'desc']) ? strtolower($_GET['dir']) : 'asc'; // Padrão ASC para data

// Filtros existentes
// Filtro de status agora aceita múltiplos valores
$filtro_status = isset($_GET['status']) && is_array($_GET['status']) ? $_GET['status'] : [];
$filtro_data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : '';
$filtro_data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : '';
// Filtro por tipo de pagamento (múltipla seleção)
$filtro_tipo_pagamento = isset($_GET['tipo_pagamento']) && is_array($_GET['tipo_pagamento']) ? $_GET['tipo_pagamento'] : [];
// Filtro por tipo de operação (múltipla seleção)
$filtro_tipo_operacao = isset($_GET['tipo_operacao']) && is_array($_GET['tipo_operacao']) ? $_GET['tipo_operacao'] : [];
// Busca
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Colunas permitidas para ordenação (Adicionado cedente_nome e tipo_pagamento)
$allowed_sort_columns = [
    'operacao_id' => 'r.operacao_id',
    'id' => 'r.id',
    'cedente_nome' => 's.empresa',
    'sacado_nome' => 'sac.empresa',
    'data_operacao' => 'o.data_operacao',
    'tipo_pagamento' => 'o.tipo_pagamento',
    'tipo_operacao' => 'o.tipo_operacao',
    'data_vencimento' => 'r.data_vencimento',
    'valor_original' => 'r.valor_original',
    'status' => 'r.status'
    // 'dias_p_vencimento' não é adicionado aqui porque é calculado no PHP e não diretamente no SQL para ordenação.
];

// Validar coluna de ordenação
if (!array_key_exists($sort, $allowed_sort_columns)) {
    $sort = 'data_vencimento';
}
$sort_column_sql = $allowed_sort_columns[$sort];

// Calcular offset
$offset = ($page - 1) * $results_per_page;
if ($offset < 0) $offset = 0;

// --- Construção da Query Dinâmica ---
$params_count = [];
$params_data = [];
$whereClauses = [];

// Filtro de status (múltiplos valores)
if (!empty($filtro_status)) {
    $placeholders = [];
    for ($i = 0; $i < count($filtro_status); $i++) {
        $placeholders[] = ":status_$i";
        $params_count[":status_$i"] = $filtro_status[$i];
        $params_data[":status_$i"] = $filtro_status[$i];
    }
    $whereClauses[] = "r.status IN (" . implode(',', $placeholders) . ")";
}

// Filtro de tipo de pagamento (múltiplos valores)
if (!empty($filtro_tipo_pagamento)) {
    $placeholders = [];
    for ($i = 0; $i < count($filtro_tipo_pagamento); $i++) {
        $placeholders[] = ":tipo_pagamento_$i";
        $params_count[":tipo_pagamento_$i"] = $filtro_tipo_pagamento[$i];
        $params_data[":tipo_pagamento_$i"] = $filtro_tipo_pagamento[$i];
    }
    $whereClauses[] = "o.tipo_pagamento IN (" . implode(',', $placeholders) . ")";
}

// Filtro de tipo de operação (múltipla seleção)
if (!empty($filtro_tipo_operacao)) {
    $placeholders = [];
    for ($i = 0; $i < count($filtro_tipo_operacao); $i++) {
        $placeholders[] = ":tipo_operacao_$i";
        $params_count[":tipo_operacao_$i"] = $filtro_tipo_operacao[$i];
        $params_data[":tipo_operacao_$i"] = $filtro_tipo_operacao[$i];
    }
    $whereClauses[] = "o.tipo_operacao IN (" . implode(',', $placeholders) . ")";
}

// Filtro de data de início
if ($filtro_data_inicio && DateTime::createFromFormat('Y-m-d', $filtro_data_inicio)) {
    $whereClauses[] = "r.data_vencimento >= :data_inicio";
    $params_count[':data_inicio'] = $filtro_data_inicio;
    $params_data[':data_inicio'] = $filtro_data_inicio;
} else {
    $filtro_data_inicio = '';
}

// Filtro de data de fim
if ($filtro_data_fim && DateTime::createFromFormat('Y-m-d', $filtro_data_fim)) {
    $whereClauses[] = "r.data_vencimento <= :data_fim";
    $params_count[':data_fim'] = $filtro_data_fim;
    $params_data[':data_fim'] = $filtro_data_fim;
} else {
    $filtro_data_fim = '';
}

// Filtro Rápido
$quick_filter = isset($_GET['quick_filter']) ? $_GET['quick_filter'] : 'todos';

if ($quick_filter === 'inadimplentes') {
    $whereClauses[] = "r.status NOT IN ('Recebido', 'Compensado', 'Totalmente Compensado') AND r.data_vencimento < CURDATE()";
} elseif ($quick_filter === 'recebidos') {
    $whereClauses[] = "r.status IN ('Recebido', 'Compensado', 'Totalmente Compensado')";
} elseif ($quick_filter === 'a_receber') {
    $whereClauses[] = "r.status NOT IN ('Recebido', 'Compensado', 'Totalmente Compensado') AND r.data_vencimento >= CURDATE()";
} elseif ($quick_filter === 'vencendo_7_dias') {
    $whereClauses[] = "r.status NOT IN ('Recebido', 'Compensado', 'Totalmente Compensado') AND r.data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($quick_filter === 'vencendo_hoje') {
    $whereClauses[] = "r.status NOT IN ('Recebido', 'Compensado', 'Totalmente Compensado') AND r.data_vencimento = CURDATE()";
} elseif ($quick_filter === 'problemas') {
    $whereClauses[] = "r.status = 'Problema'";
}

// Filtro de Busca - Inclui busca pelo nome do cedente e sacado
if (!empty($search)) {
     $whereClauses[] = "(CAST(r.id AS CHAR) LIKE :search_rid OR CAST(r.operacao_id AS CHAR) LIKE :search_oid OR CAST(r.valor_original AS CHAR) LIKE :search_valor OR s.empresa LIKE :search_cedente OR sac.empresa LIKE :search_sacado)";
     $search_param = "%" . $search . "%";
     $search_valor_param = "%" . str_replace(',', '.', $search) . "%";

     $params_count[':search_rid'] = $search_param;
     $params_count[':search_oid'] = $search_param;
     $params_count[':search_valor'] = $search_valor_param;
     $params_count[':search_cedente'] = $search_param;
     $params_count[':search_sacado'] = $search_param;

     $params_data[':search_rid'] = $search_param;
     $params_data[':search_oid'] = $search_param;
     $params_data[':search_valor'] = $search_valor_param;
     $params_data[':search_cedente'] = $search_param;
     $params_data[':search_sacado'] = $search_param;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// --- Query para Contar o Total ---
$total_results = 0;
try {
    $countSql = "SELECT COUNT(r.id)
                 FROM recebiveis r
                 LEFT JOIN operacoes o ON r.operacao_id = o.id
                 LEFT JOIN clientes s ON o.cedente_id = s.id
                 LEFT JOIN clientes sac ON r.sacado_id = sac.id
                 $whereSql";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params_count);
    $total_results = (int)$stmtCount->fetchColumn();
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Erro Crítico [Count Recebíveis]: " . htmlspecialchars($e->getMessage()) . "</div>";
    $recebiveis = []; // Definir valores padrão para evitar erros no HTML
    $total_pages = 0;
}

// --- Totais filtrados (respeitam $whereSql, ignoram paginação) ---
$filtered_total_original = 0.0;
$filtered_total_recebido = 0.0;
$filtered_total_em_aberto = 0.0;
$filtered_qtd_em_aberto = 0;
try {
    $totalsSql = "SELECT
                    COALESCE(SUM(r.valor_original), 0) AS total_original,
                    COALESCE(SUM(CASE WHEN r.status = 'Recebido' THEN r.valor_recebido END), 0) AS total_recebido,
                    COALESCE(SUM(CASE WHEN r.status NOT IN ('Recebido', 'Compensado', 'Totalmente Compensado') THEN r.valor_original ELSE 0 END), 0) AS total_em_aberto,
                    COALESCE(SUM(CASE WHEN r.status NOT IN ('Recebido', 'Compensado', 'Totalmente Compensado') THEN 1 ELSE 0 END), 0) AS qtd_em_aberto
                  FROM recebiveis r
                  LEFT JOIN operacoes o ON r.operacao_id = o.id
                  LEFT JOIN clientes s ON o.cedente_id = s.id
                  LEFT JOIN clientes sac ON r.sacado_id = sac.id
                  $whereSql";
    $stmtTotals = $pdo->prepare($totalsSql);
    $stmtTotals->execute($params_count);
    $totalsRow = $stmtTotals->fetch(PDO::FETCH_ASSOC) ?: [];
    $filtered_total_original  = (float)($totalsRow['total_original']  ?? 0);
    $filtered_total_recebido  = (float)($totalsRow['total_recebido']  ?? 0);
    $filtered_total_em_aberto = (float)($totalsRow['total_em_aberto'] ?? 0);
    $filtered_qtd_em_aberto   = (int)  ($totalsRow['qtd_em_aberto']   ?? 0);
} catch (PDOException $e) { /* ignora — totais são informativos */ }

$total_pages = ($results_per_page > 0) ? ceil($total_results / $results_per_page) : 0;
if ($total_pages == 0) $total_pages = 1;

// Ajustar a página atual se for inválida
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $results_per_page;
    if ($offset < 0) $offset = 0;
} elseif ($page < 1) {
    $page = 1;
    $offset = 0;
}


// --- Query para Buscar os Dados da Página Atual ---
$recebiveis = []; // Inicializa
try {
    $sql = "SELECT r.*, o.data_operacao, o.tipo_pagamento, o.tipo_operacao, r.tipo_recebivel, COALESCE(s.empresa, s.nome, sac.empresa, sac.nome) AS cedente_nome,
                   sac.empresa AS sacado_nome,
                   COALESCE(SUM(c.valor_compensado), 0) as total_compensado
            FROM recebiveis r
            LEFT JOIN operacoes o ON r.operacao_id = o.id
            LEFT JOIN clientes s ON o.cedente_id = s.id
            LEFT JOIN clientes sac ON r.sacado_id = sac.id
            LEFT JOIN compensacoes c ON r.id = c.recebivel_compensado_id
            $whereSql
            GROUP BY r.id, r.operacao_id, r.valor_original, r.data_vencimento, r.status,
                     o.data_operacao, o.tipo_pagamento, o.tipo_operacao, o.cedente_id, s.empresa, r.sacado_id, sac.empresa
            ORDER BY $sort_column_sql $dir
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);

    // Bind dos parâmetros de filtros e busca
    if (!empty($search)) {
         $stmt->bindParam(':search_rid', $params_data[':search_rid'], PDO::PARAM_STR);
         $stmt->bindParam(':search_oid', $params_data[':search_oid'], PDO::PARAM_STR);
         $stmt->bindParam(':search_valor', $params_data[':search_valor'], PDO::PARAM_STR);
         $stmt->bindParam(':search_cedente', $params_data[':search_cedente'], PDO::PARAM_STR);
         $stmt->bindParam(':search_sacado', $params_data[':search_sacado'], PDO::PARAM_STR);
    }
    if (!empty($filtro_status)) {
        for ($i = 0; $i < count($filtro_status); $i++) {
            $stmt->bindParam(":status_$i", $params_data[":status_$i"], PDO::PARAM_STR);
        }
    }
    if (!empty($filtro_tipo_pagamento)) {
        for ($i = 0; $i < count($filtro_tipo_pagamento); $i++) {
            $stmt->bindParam(":tipo_pagamento_$i", $params_data[":tipo_pagamento_$i"], PDO::PARAM_STR);
        }
    }
    if ($filtro_data_inicio) {
        $stmt->bindParam(':data_inicio', $params_data[':data_inicio'], PDO::PARAM_STR);
    }
    if ($filtro_data_fim) {
        $stmt->bindParam(':data_fim', $params_data[':data_fim'], PDO::PARAM_STR);
    }

    // Bind de paginação
    $stmt->bindParam(':limit', $results_per_page, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $recebiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enriquecer dados com informações de compensação
    foreach ($recebiveis as &$recebivel) {
        $statusInfo = verificarStatusRecebivel($recebivel['id'], $pdo);
        $recebivel['status_real'] = $statusInfo['status'];
        $recebivel['saldo_disponivel'] = $statusInfo['saldo_disponivel'];
        $recebivel['percentual_compensado'] = $statusInfo['percentual_compensado'];
        $recebivel['disponivel_para_compensacao'] = $statusInfo['disponivel_para_compensacao'];
    }

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Erro Crítico [Data Recebíveis]: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// --- KPIs gerais (independem de filtros, mostram visão geral) ---
$kpis = ['total' => 0, 'em_aberto_qtd' => 0, 'em_aberto_valor' => 0, 'inadimplentes_qtd' => 0, 'inadimplentes_valor' => 0, 'recebidos_valor' => 0];
try {
    $kpis['total'] = (int) $pdo->query("SELECT COUNT(*) FROM recebiveis")->fetchColumn();

    $row = $pdo->query("
        SELECT COUNT(*) AS qtd, COALESCE(SUM(valor_original), 0) AS valor
        FROM recebiveis
        WHERE status NOT IN ('Recebido', 'Compensado', 'Totalmente Compensado')
    ")->fetch(PDO::FETCH_ASSOC);
    $kpis['em_aberto_qtd']   = (int) ($row['qtd'] ?? 0);
    $kpis['em_aberto_valor'] = (float) ($row['valor'] ?? 0);

    $row = $pdo->query("
        SELECT COUNT(*) AS qtd, COALESCE(SUM(valor_original), 0) AS valor
        FROM recebiveis
        WHERE status NOT IN ('Recebido', 'Compensado', 'Totalmente Compensado')
        AND data_vencimento < CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);
    $kpis['inadimplentes_qtd']   = (int) ($row['qtd'] ?? 0);
    $kpis['inadimplentes_valor'] = (float) ($row['valor'] ?? 0);

    $kpis['recebidos_valor'] = (float) $pdo->query("
        SELECT COALESCE(SUM(valor_original), 0)
        FROM recebiveis
        WHERE status IN ('Recebido', 'Compensado', 'Totalmente Compensado')
        AND data_vencimento >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    ")->fetchColumn();
} catch (PDOException $e) { /* ignora */ }

// --- Helpers de Formatação HTML ---
function formatHtmlCurrency($value) { return 'R$ ' . number_format($value ?? 0, 2, ',', '.'); }
function moedaCompact($v) {
    $v = (float)$v;
    if ($v >= 1000000) return 'R$ ' . number_format($v / 1000000, 2, ',', '.') . ' mi';
    if ($v >= 10000)   return 'R$ ' . number_format($v / 1000, 1, ',', '.') . ' mil';
    return formatHtmlCurrency($v);
}

function formatHtmlDate($value) {
  if(!$value) return '-';
  try {
    return (new DateTime($value))->format('d/m/Y');
  } catch(Exception $e){
    return '-';
  }
}

function formatHtmlStatus($recebivel, $data_recebimento = null) {
    $status = $recebivel['status_real'] ?? $recebivel['status'] ?? 'Em Aberto';
    $percentual = $recebivel['percentual_compensado'] ?? 0;
    
    // Dieta de cor: pills suaves. Cor só onde comunica desfecho — verde = recebido,
    // vermelho = problema. Os demais estados ficam neutros (a urgência de prazo já
    // aparece na coluna Vencimento, evitando excesso de cor no Status).
    $pillClass = 's-neutro'; $tooltip = 'Aguardando ação ou recebimento';
    switch ($status) {
        case 'Em Aberto': $pillClass = 's-neutro'; $tooltip = 'Aguardando ação ou recebimento'; break;
        case 'Recebido':
            $pillClass = 's-recebido';
            $tooltip = 'Recebimento confirmado';
            if (!empty($data_recebimento)) {
                $tooltip .= ' em ' . formatHtmlDate($data_recebimento);
            }
            break;
        case 'Problema': $pillClass = 's-problema'; $tooltip = 'Problema no recebimento'; break;
        case 'Compensado':
        case 'Totalmente Compensado': $pillClass = 's-neutro'; $tooltip = 'Valor totalmente compensado em encontro de contas'; break;
        case 'Parcialmente Compensado': $pillClass = 's-neutro'; $tooltip = "Valor parcialmente compensado ({$percentual}%)"; break;
    }

    $statusText = $status;
    if ($status === 'Parcialmente Compensado') {
        $statusText .= " ({$percentual}%)";
    }

    // Se for "Recebido" e tiver data, usar tooltip customizado
    if ($status === 'Recebido' && !empty($data_recebimento)) {
        return '<div class="tooltip-wrapper">
                    <span class="status-pill ' . $pillClass . '">' . htmlspecialchars($statusText) . '</span>
                    <span class="tooltip-text">' . htmlspecialchars($tooltip) . '</span>
                </div>';
    }

    // Para outros casos, usar tooltip padrão
    return '<span class="status-pill ' . $pillClass . '" title="' . htmlspecialchars($tooltip) . '">' . htmlspecialchars($statusText) . '</span>';
}

function getTableRowClass($status, $data_vencimento = null) {
    // Linhas não têm mais cor de fundo por status (usam zebra). Urgência e status
    // são comunicados por pills nas colunas Vencimento e Status.
    return '';
}

// Helper function para links de ordenação
function getRecebivelSortLink($column, $text, $currentSort, $currentDir, $currentFilters) {
    $newDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    $icon = '';
    if ($currentSort === $column) {
        $icon = $currentDir === 'asc' ? ' ▲' : ' ▼';
    }
    $filterParams = http_build_query($currentFilters);
    return "<a href=\"?sort=$column&dir=$newDir&$filterParams\">" . htmlspecialchars($text) . $icon . "</a>";
}

// Monta array com filtros atuais para usar nos links (excluindo page)
$current_filters_for_links = [
    'quick_filter' => $quick_filter,
    'status' => $filtro_status,
    'tipo_pagamento' => $filtro_tipo_pagamento,
    'tipo_operacao' => $filtro_tipo_operacao,
    'data_inicio' => $filtro_data_inicio,
    'data_fim' => $filtro_data_fim,
    'search' => $search,
    'per_page' => $items_per_page
];
// Monta array com filtros + sort/dir para usar nos links de paginação
$current_filters_for_pagination = $current_filters_for_links + ['sort' => $sort, 'dir' => $dir];

?>
<?php
$pageTitle = 'Recebíveis';
require_once 'head.php';
?>
    <style>
        /* Estilos específicos da página (tokens e componentes base vêm de theme.css) */
        body { font-size: 0.95rem; }

        /* Filtros avançados (collapse) — específico desta página */
        .filter-form-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 14px; margin-bottom: 18px;
        }
        .filter-form-card .head {
            padding: 12px 16px; border-bottom: 1px solid var(--border);
            background: var(--surface-2); font-weight: 600; font-size: 0.9rem;
        }
        .filter-form-card .body { padding: 16px; }
        .form-label-strong {
            font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em;
            color: var(--neutral); font-weight: 600; margin-bottom: 4px; display: block;
        }
        .check-group {
            border: 1px solid var(--border); border-radius: 8px;
            padding: 8px 10px; background: var(--surface);
            display: flex; flex-wrap: wrap; gap: 4px 12px;
        }
        .check-group .form-check { margin: 0; }
        .check-group .form-check-label { font-size: 0.85rem; }

        /* Variante extra de chip ativo usada por esta página */
        .filter-chip.active.f-danger { background: var(--danger-soft); color: var(--danger); border-color: #f5b7be; }
        .filter-chip.btn-add {
            background: var(--surface-2); color: var(--neutral);
            border: 1px dashed var(--border);
        }
        .filter-chip.btn-add:hover { color: var(--info); border-color: var(--info); }

        /* Ajustes específicos da tabela de recebíveis */
        .data-table th.text-center,
        .data-table th.text-end,
        .data-table td.text-center,
        .data-table td.text-end { width: 1%; white-space: nowrap; }
        .data-table tbody td.nowrap { white-space: nowrap; }
        .data-table .num { white-space: nowrap; }
        .data-table tfoot td {
            background: var(--surface-2); font-weight: 700;
            border-top: 2px solid var(--border);
            padding: 10px 8px; font-size: 0.82rem;
        }
        /* Sem cor de fundo por status: as linhas usam zebra (theme.css).
           Urgência de vencimento vira um pill em destaque na coluna Vencimento. */
        .venc-pill {
            display: inline-block;
            padding: 1px 9px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            white-space: nowrap;
            margin-top: 2px;
        }
        .venc-pill.atraso { background: var(--danger-soft); color: var(--danger); }
        .venc-pill.soon   { background: var(--warn-soft);   color: var(--warn); }

        /* Rodapé de totais: barra com rótulos claros em vez de números empilhados */
        td.totals-foot { padding: 0 !important; background: var(--surface-2); border-top: 2px solid var(--border); }
        .totals-bar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px 24px; flex-wrap: wrap; padding: 12px 18px;
        }
        .totals-bar .totals-title {
            font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em;
            color: var(--neutral); font-weight: 700;
        }
        .totals-stats { display: flex; gap: 14px 32px; flex-wrap: wrap; }
        .tstat { display: flex; flex-direction: column; line-height: 1.25; }
        .tstat-label {
            font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.03em;
            color: var(--neutral); font-weight: 600; margin-bottom: 2px;
        }
        .tstat-value { font-size: 1.05rem; font-weight: 700; font-variant-numeric: tabular-nums; }
        .tstat-meta .tstat-value { font-size: 0.9rem; font-weight: 600; color: var(--neutral); }

        /* Dieta de cor: tipo é categoria, não status — neutro. */
        .pill-tipo.antecip,
        .pill-tipo.empr    { background: #eef0f3; color: #5a6470; }

        .status-pill.s-recebido  { background: var(--profit-soft); color: var(--profit); }
        .status-pill.s-compensado{ background: #eef0f3; color: #5a6470; }

        /* Barra de ações em massa */
        .rec-bulk-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            background: var(--info-soft);
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .rec-bulk-bar .btn-link { text-decoration: none; }

        /* Action button (preservar classe .action-btn pq o JS reinjeta esse HTML) */
        .action-btn {
            width: 28px; height: 28px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 6px; padding: 0;
            font-size: 0.85rem; line-height: 1;
            margin: 0;
        }
        .action-btn:not(:last-child) { margin-right: 2px; }
        /* Dieta de cor: botões de ação em tons suaves (não saturados) para a tabela
           não virar um mural de quadrados verdes/vermelhos/azuis. A cor semântica
           continua legível; satura só no hover. Classes Bootstrap preservadas (JS reinjeta). */
        .action-btn.btn-success,
        .action-btn.btn-danger,
        .action-btn.btn-secondary,
        .action-btn.btn-primary {
            border-color: transparent;
            box-shadow: none;
        }
        .action-btn.btn-success { background: var(--profit-soft); color: var(--profit); }
        .action-btn.btn-danger  { background: var(--danger-soft); color: var(--danger); }
        .action-btn.btn-primary { background: var(--info-soft);   color: var(--info); }
        .action-btn.btn-secondary { background: #eef0f3; color: #5a6470; }
        .action-btn.btn-success:hover { background: var(--profit); color: #fff; }
        .action-btn.btn-danger:hover  { background: var(--danger); color: #fff; }
        .action-btn.btn-primary:hover { background: var(--info);   color: #fff; }
        .action-btn.btn-secondary:hover { background: #5a6470; color: #fff; }

        /* Coluna de ações sticky à direita */
        .acoes-col {
            position: sticky; right: 0;
            background-color: #fff;
            box-shadow: -2px 0 5px rgba(0,0,0,0.05);
            white-space: nowrap;
        }
        /* Coluna sticky acompanha a zebra das linhas (precisa de bg próprio por sobrepor). */
        .data-table thead th.acoes-col { background: var(--surface-2); }
        .data-table tfoot td.acoes-col { background: var(--surface-2); }
        .data-table tbody tr:nth-child(odd)  td.acoes-col { background: #ffffff; }
        .data-table tbody tr:nth-child(even) td.acoes-col { background: #f7f8fa; }
        .data-table tbody tr:hover td.acoes-col { background: #eef2f8; }

        /* Tooltip customizado (preservado — usado por status Recebido) */
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
    </style>

    <div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">

        <!-- Toolbar -->
        <div class="page-toolbar">
            <div>
                <h1>
                    <i class="bi bi-list-check text-primary"></i>
                    Recebíveis
                    <span class="id-pill"><i class="bi bi-hash"></i><?php echo $kpis['total']; ?> registrados</span>
                </h1>
                <div class="text-muted small mt-1">Acompanhamento de títulos a receber, recebidos e em problema</div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <form method="GET" action="listar_recebiveis.php" class="d-flex gap-2">
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                    <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
                    <?php if ($quick_filter !== 'todos'): ?>
                        <input type="hidden" name="quick_filter" value="<?php echo htmlspecialchars($quick_filter); ?>">
                    <?php endif; ?>
                    <div class="input-group input-group-sm" style="width: 280px;">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" name="search"
                               placeholder="Buscar ID, valor, cedente, sacado…"
                               value="<?php echo htmlspecialchars($search); ?>">
                        <?php if ($search !== ''): ?>
                            <a href="?<?php echo http_build_query(['sort'=>$sort,'dir'=>$dir,'quick_filter'=>$quick_filter]); ?>"
                               class="btn btn-outline-secondary" title="Limpar busca"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
                <a href="exportar_ics.php?<?php echo http_build_query($current_filters_for_links);?>"
                   class="btn btn-outline-primary btn-sm" target="_blank" title="Exportar lembretes (.ics)">
                    <i class="bi bi-calendar-plus"></i> .ics
                </a>
                <a href="exportar_csv.php?<?php echo http_build_query($current_filters_for_links);?>"
                   class="btn btn-outline-success btn-sm" target="_blank" title="Exportar lista (.csv)">
                    <i class="bi bi-file-earmark-spreadsheet"></i> .csv
                </a>
            </div>
        </div>

        <!-- KPIs -->
        <div class="kpi-strip">
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-list-ol"></i></div>
                <div class="k-label">Total cadastrados</div>
                <div class="k-value"><?php echo $kpis['total']; ?></div>
                <div class="k-trend">recebíveis no sistema</div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-hourglass-split"></i></div>
                <div class="k-label">Em aberto</div>
                <div class="k-value"><?php echo moedaCompact($kpis['em_aberto_valor']); ?></div>
                <div class="k-trend"><?php echo $kpis['em_aberto_qtd']; ?> a receber</div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-danger"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div class="k-label">Inadimplentes</div>
                <div class="k-value"><?php echo moedaCompact($kpis['inadimplentes_valor']); ?></div>
                <div class="k-trend"><?php echo $kpis['inadimplentes_qtd']; ?> vencidos</div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-green"><i class="bi bi-check-circle-fill"></i></div>
                <div class="k-label">Recebidos (12m)</div>
                <div class="k-value"><?php echo moedaCompact($kpis['recebidos_valor']); ?></div>
                <div class="k-trend">total liquidado</div>
            </div>
        </div>

        <!-- Filtros rápidos (chips) -->
        <?php
        $quickLink = function($k) {
            $params = [];
            if ($k !== 'todos') $params['quick_filter'] = $k;
            return 'listar_recebiveis.php' . (empty($params) ? '' : '?' . http_build_query($params));
        };
        ?>
        <div class="filter-bar">
            <span class="filter-label">Filtros rápidos:</span>
            <a href="<?php echo $quickLink('todos'); ?>" class="filter-chip <?php echo $quick_filter === 'todos' ? 'active' : ''; ?>">Todos</a>
            <a href="<?php echo $quickLink('a_receber'); ?>" class="filter-chip <?php echo $quick_filter === 'a_receber' ? 'active' : ''; ?>">A receber</a>
            <a href="<?php echo $quickLink('vencendo_hoje'); ?>" class="filter-chip <?php echo $quick_filter === 'vencendo_hoje' ? 'active f-warn' : ''; ?>">Vencendo hoje</a>
            <a href="<?php echo $quickLink('vencendo_7_dias'); ?>" class="filter-chip <?php echo $quick_filter === 'vencendo_7_dias' ? 'active f-warn' : ''; ?>">Vence em 7 dias</a>
            <a href="<?php echo $quickLink('inadimplentes'); ?>" class="filter-chip <?php echo $quick_filter === 'inadimplentes' ? 'active f-danger' : ''; ?>">Inadimplentes</a>
            <a href="<?php echo $quickLink('problemas'); ?>" class="filter-chip <?php echo $quick_filter === 'problemas' ? 'active f-danger' : ''; ?>">Problemas</a>
            <a href="<?php echo $quickLink('recebidos'); ?>" class="filter-chip <?php echo $quick_filter === 'recebidos' ? 'active f-green' : ''; ?>">Recebidos</a>
            <button class="filter-chip btn-add ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#filtrosCollapse">
                <i class="bi bi-funnel"></i> Filtros avançados
            </button>
        </div>

        <!-- Filtros avançados (collapse) -->
        <div class="collapse" id="filtrosCollapse">
            <div class="filter-form-card">
                <div class="head"><i class="bi bi-funnel"></i> Filtros avançados</div>
                <div class="body">
                    <form method="GET" action="listar_recebiveis.php">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                        <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                        <?php if ($quick_filter !== 'todos'): ?>
                            <input type="hidden" name="quick_filter" value="<?php echo htmlspecialchars($quick_filter); ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label-strong">Status</label>
                                <div class="check-group">
                                    <?php
                                    $status_options = ['Em Aberto', 'Recebido', 'Problema', 'Parcialmente Compensado', 'Totalmente Compensado'];
                                    foreach ($status_options as $status_option):
                                        $checked = in_array($status_option, $filtro_status) ? 'checked' : '';
                                        $sid = 'st_' . md5($status_option);
                                    ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="status[]"
                                                   value="<?php echo htmlspecialchars($status_option); ?>"
                                                   id="<?php echo $sid; ?>" <?php echo $checked; ?>>
                                            <label class="form-check-label" for="<?php echo $sid; ?>">
                                                <?php echo htmlspecialchars($status_option); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label-strong">Tipo de pagamento</label>
                                <div class="check-group">
                                    <?php foreach (['direto'=>'Direto','escrow'=>'Escrow','indireto'=>'Indireto'] as $val => $lab):
                                        $checked = in_array($val, $filtro_tipo_pagamento) ? 'checked' : '';
                                    ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="tipo_pagamento[]"
                                                   value="<?php echo $val; ?>" id="tp_<?php echo $val; ?>" <?php echo $checked; ?>>
                                            <label class="form-check-label" for="tp_<?php echo $val; ?>"><?php echo $lab; ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label-strong">Tipo de operação</label>
                                <div class="check-group">
                                    <?php foreach (['antecipacao'=>'Antecipação','emprestimo'=>'Empréstimo'] as $val => $lab):
                                        $checked = in_array($val, $filtro_tipo_operacao) ? 'checked' : '';
                                    ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="tipo_operacao[]"
                                                   value="<?php echo $val; ?>" id="to_<?php echo $val; ?>" <?php echo $checked; ?>>
                                            <label class="form-check-label" for="to_<?php echo $val; ?>"><?php echo $lab; ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="data_inicio" class="form-label-strong">Vencimento de</label>
                                <input type="date" name="data_inicio" id="data_inicio" class="form-control" value="<?php echo htmlspecialchars($filtro_data_inicio); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="data_fim" class="form-label-strong">Vencimento até</label>
                                <input type="date" name="data_fim" id="data_fim" class="form-control" value="<?php echo htmlspecialchars($filtro_data_fim); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="per_page" class="form-label-strong">Itens por página</label>
                                <select name="per_page" id="per_page" class="form-select">
                                    <?php foreach ($items_per_page_options as $option): ?>
                                        <option value="<?php echo $option; ?>" <?php echo ($items_per_page == $option) ? 'selected' : ''; ?>><?php echo $option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Aplicar filtros</button>
                            <a href="?<?php echo http_build_query(['sort'=>$sort,'dir'=>$dir,'search'=>$search]); ?>"
                               class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div id="status-feedback" class="mt-2 mb-3" style="min-height: 1.5em;"></div>
        <?php if (empty($recebiveis) && $total_results == 0 && empty($filtro_status) && empty($filtro_tipo_pagamento) && empty($filtro_data_inicio) && empty($filtro_data_fim) && empty($search) && $quick_filter === 'todos'): ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h4 class="mt-3 text-muted">Nenhum recebível registrado</h4>
                <p>Os recebíveis aparecem aqui quando você cria operações.</p>
            </div>
        <?php elseif (empty($recebiveis)): ?>
            <div class="empty-state">
                <i class="bi bi-search"></i>
                <h4 class="mt-3 text-muted">Nenhum resultado</h4>
                <p>Não encontramos recebíveis para os filtros/busca atuais.</p>
                <a href="listar_recebiveis.php" class="btn btn-outline-primary"><i class="bi bi-x-circle"></i> Limpar filtros</a>
            </div>
        <?php else: ?>
            <div class="data-table-wrap">
                <div class="data-table-head">
                    <h3>Resultado</h3>
                    <div class="meta">
                        <strong><?php echo $total_results; ?></strong> recebíve<?php echo $total_results === 1 ? 'l' : 'is'; ?>
                    </div>
                </div>
                <div id="recBulkBar" class="rec-bulk-bar" style="display:none;">
                    <span><strong id="recBulkCount">0</strong> selecionado(s)</span>
                    <button type="button" class="btn btn-success btn-sm" id="recBulkReceber"><i class="bi bi-check-lg"></i> Marcar como recebido</button>
                    <button type="button" class="btn btn-link btn-sm text-muted p-0 ms-1" id="recBulkClear">Limpar seleção</button>
                    <span id="recBulkProgress" class="small text-muted ms-auto"></span>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                          <tr>
                              <th class="text-center" style="width:36px;"><input class="form-check-input" type="checkbox" id="recSelectAll" title="Selecionar todos os recebíveis acionáveis"></th>
                              <th class="text-center"><?php echo getRecebivelSortLink('operacao_id', 'ID Op.', $sort, $dir, $current_filters_for_links); ?></th>
                              <th class="text-center"><?php echo getRecebivelSortLink('id', 'Id Rec.', $sort, $dir, $current_filters_for_links); ?></th>
                              <th><?php echo getRecebivelSortLink('cedente_nome', 'Cedente', $sort, $dir, $current_filters_for_links); ?></th>
                              <th><?php echo getRecebivelSortLink('sacado_nome', 'Sacado', $sort, $dir, $current_filters_for_links); ?></th>
                              <th class="text-center"><?php echo getRecebivelSortLink('data_operacao', 'Data Op.', $sort, $dir, $current_filters_for_links); ?></th>
                              <th class="text-center"><?php echo getRecebivelSortLink('tipo_pagamento', 'Pagto', $sort, $dir, $current_filters_for_links); ?></th>
                              <th class="text-center"><?php echo getRecebivelSortLink('tipo_operacao', 'Tipo', $sort, $dir, $current_filters_for_links); ?></th>
                              <th class="text-center">Tipo Rec.</th>
                              <th class="text-center"><?php echo getRecebivelSortLink('data_vencimento', 'Vencimento', $sort, $dir, $current_filters_for_links); ?></th>
                              <th class="text-end"><?php echo getRecebivelSortLink('valor_original', 'Valor', $sort, $dir, $current_filters_for_links); ?></th>
                              <th class="text-center"><?php echo getRecebivelSortLink('status', 'Status', $sort, $dir, $current_filters_for_links); ?></th>
                              <th class="text-center acoes-col">Ações</th>
                          </tr>
                      </thead>
                    <tbody>
                        <?php
                        foreach ($recebiveis as $r):
                        ?>
                            <?php
                            $rowClass = getTableRowClass($r['status_real'], $r['data_vencimento']);

                            // Dias entre hoje e vencimento (negativo se vencido)
                            $dias_atraso_hoje = 0;
                            try {
                                $_hoje = new DateTime('today');
                                $_venc = new DateTime($r['data_vencimento']);
                                $_venc->setTime(0, 0, 0);
                                $_diff = $_hoje->diff($_venc);
                                $dias_atraso_hoje = $_venc < $_hoje ? -$_diff->days : $_diff->days;
                            } catch (Exception $e) { $dias_atraso_hoje = 0; }

                            $valor_original = (float)$r['valor_original'];
                            $valor_recebido_db = isset($r['valor_recebido']) && is_numeric($r['valor_recebido']) ? (float)$r['valor_recebido'] : null;
                            $is_recebido = $r['status_real'] === 'Recebido';
                            $is_compensado = in_array($r['status_real'], ['Compensado', 'Totalmente Compensado']);
                            $is_vencido_aberto = $dias_atraso_hoje < 0 && !$is_recebido && !$is_compensado;
                            ?>
                            <tr id="recebivel-row-<?php echo $r['id']; ?>" class="<?php echo $rowClass; ?>">
                                <td class="text-center">
                                    <?php if (in_array($r['status_real'], ['Em Aberto', 'Problema', 'Parcialmente Compensado'])): ?>
                                        <input class="form-check-input rec-select" type="checkbox" data-id="<?php echo (int)$r['id']; ?>" title="Selecionar para ação em massa">
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><a href="detalhes_operacao.php?id=<?php echo (int)$r['operacao_id']; ?>" title="Ver detalhes da Operação <?php echo (int)$r['operacao_id']; ?>">
                                  <?php echo htmlspecialchars($r['operacao_id']); ?></a></td>

                                <td class="text-center"><small><?php echo htmlspecialchars($r['id']); ?></small></td>
                                <td><?php echo htmlspecialchars($r['cedente_nome'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['sacado_nome'] ?? 'N/A'); ?></td>
                                <td class="text-center"><?php echo formatHtmlDate($r['data_operacao']); ?></td>
                                <td class="text-center text-muted"><?php echo htmlspecialchars(ucfirst($r['tipo_pagamento'] ?? '—')); ?></td>
                                <td class="text-center">
                                    <?php
                                    if (($r['tipo_operacao'] ?? 'antecipacao') == 'emprestimo') {
                                        echo '<span class="pill-tipo empr"><i class="bi bi-cash-coin"></i> Empréstimo</span>';
                                    } else {
                                        echo '<span class="pill-tipo antecip"><i class="bi bi-arrow-return-left"></i> Antecipação</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-center text-muted small"><?php echo htmlspecialchars($r['tipo_recebivel'] ?? '—'); ?></td>
                                <td class="text-center nowrap">
                                    <div class="nowrap"><?php echo formatHtmlDate($r['data_vencimento']); ?></div>
                                    <?php
                                    if ($is_recebido && !empty($r['data_recebimento'])) {
                                        echo '<div class="small text-success nowrap"><i class="bi bi-check-circle"></i> pago em ' . formatHtmlDate($r['data_recebimento']) . '</div>';
                                    } elseif ($is_compensado) {
                                        echo '<div class="small text-muted">—</div>';
                                    } elseif ($dias_atraso_hoje < 0) {
                                        echo '<div><span class="venc-pill atraso"><i class="bi bi-exclamation-circle"></i> ' . abs($dias_atraso_hoje) . ' dias em atraso</span></div>';
                                    } elseif ($dias_atraso_hoje === 0) {
                                        echo '<div><span class="venc-pill soon">vence hoje</span></div>';
                                    } elseif ($dias_atraso_hoje <= 7) {
                                        echo '<div><span class="venc-pill soon">em ' . htmlspecialchars($dias_atraso_hoje) . ' dias</span></div>';
                                    } else {
                                        echo '<div class="small text-muted nowrap">em ' . htmlspecialchars($dias_atraso_hoje) . ' dias</div>';
                                    }
                                    ?>
                                </td>
                                <td class="text-end num">
                                    <?php
                                    $valor_exibicao = $valor_original;
                                    if ($is_vencido_aberto) {
                                        $calc = calcularValorCorrigido($valor_original, $r['data_vencimento']);
                                        $valor_exibicao = $calc['valor_corrigido'];
                                        echo '<div class="nowrap"><span class="text-decoration-line-through text-muted small">' . formatHtmlCurrency($valor_original) . '</span></div>';
                                        echo '<div class="text-danger fw-bold nowrap" title="Atraso de ' . $calc['dias_atraso'] . ' dias. Juros: ' . formatHtmlCurrency($calc['valor_juros']) . ' / Multa: ' . formatHtmlCurrency($calc['valor_multa']) . '">' . formatHtmlCurrency($valor_exibicao) . ' <i class="bi bi-info-circle small"></i></div>';
                                    } elseif ($is_recebido && $valor_recebido_db !== null && abs($valor_recebido_db - $valor_original) > 0.01) {
                                        $diferenca = $valor_recebido_db - $valor_original;
                                        $is_acrescimo = $diferenca > 0;
                                        $cor = $is_acrescimo ? 'text-success' : 'text-warning';
                                        $sinal = $is_acrescimo ? '+' : '−';
                                        $tooltip = $is_acrescimo
                                            ? 'Acréscimo de ' . formatHtmlCurrency(abs($diferenca)) . ' (juros/mora).'
                                            : 'Desconto de ' . formatHtmlCurrency(abs($diferenca)) . '.';
                                        echo '<div class="text-muted small nowrap">' . formatHtmlCurrency($valor_original) . '</div>';
                                        echo '<div class="' . $cor . ' fw-bold nowrap" title="' . htmlspecialchars($tooltip) . '">' . formatHtmlCurrency($valor_recebido_db) . '</div>';
                                        echo '<div class="small ' . $cor . ' nowrap">' . $sinal . formatHtmlCurrency(abs($diferenca)) . ' vs original</div>';
                                        $valor_exibicao = $valor_recebido_db;
                                    } else {
                                        echo '<div class="nowrap">' . formatHtmlCurrency($valor_original) . '</div>';
                                    }
                                    ?>
                                    <?php if ($r['total_compensado'] > 0): ?>
                                        <small class="text-muted">Saldo: <?php echo formatHtmlCurrency($r['saldo_disponivel']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center status-cell"><?php echo formatHtmlStatus($r, $r['data_recebimento'] ?? null); ?></td>
                                <td class="text-center actions-cell acoes-col">
                                    <?php 
                                    $btn_data_attrs = 'data-id="' . $r['id'] . '" data-status="Recebido" data-valor-original="' . $valor_original . '" data-valor-corrigido="' . $valor_exibicao . '"'; 
                                    if ($r['status_real'] === 'Em Aberto'): ?>
                                        <button class="btn btn-success action-btn update-status-btn" <?php echo $btn_data_attrs; ?> title="Marcar como Recebido"><i class="bi bi-check-lg"></i></button>
                                        <button class="btn btn-danger action-btn update-status-btn" data-id="<?php echo $r['id']; ?>" data-status="Problema" title="Marcar com Problema"><i class="bi bi-exclamation-triangle-fill"></i></button>
                                    <?php elseif ($r['status_real'] === 'Problema'): ?>
                                        <button class="btn btn-success action-btn update-status-btn" <?php echo $btn_data_attrs; ?> title="Marcar como Recebido"><i class="bi bi-check-lg"></i></button>
                                        <button class="btn btn-secondary action-btn update-status-btn" data-id="<?php echo $r['id']; ?>" data-status="Em Aberto" title="Reverter para Em Aberto"><i class="bi bi-arrow-counterclockwise"></i></button>
                                    <?php elseif ($r['status_real'] === 'Recebido'): ?>
                                        <button class="btn btn-secondary action-btn update-status-btn" data-id="<?php echo $r['id']; ?>" data-status="Em Aberto" title="Reverter para Em Aberto"><i class="bi bi-arrow-counterclockwise"></i></button>
                                    <?php elseif ($r['status_real'] === 'Parcialmente Compensado'): ?>
                                        <button class="btn btn-success action-btn update-status-btn" <?php echo $btn_data_attrs; ?> title="Marcar como Recebido"><i class="bi bi-check-lg"></i></button>
                                        <button class="btn btn-danger action-btn update-status-btn" data-id="<?php echo $r['id']; ?>" data-status="Problema" title="Marcar com Problema"><i class="bi bi-exclamation-triangle-fill"></i></button>
                                    <?php endif; ?>
                                    <a href="detalhes_operacao.php?id=<?php echo htmlspecialchars($r['operacao_id']); ?>" class="btn btn-primary action-btn" title="Visualizar Operação"><i class="bi bi-eye"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($recebiveis)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="13" class="totals-foot">
                                <div class="totals-bar">
                                    <div class="totals-title"><i class="bi bi-calculator"></i> Totais dos filtros aplicados</div>
                                    <div class="totals-stats">
                                        <div class="tstat">
                                            <span class="tstat-label">Valor total</span>
                                            <span class="tstat-value" title="Soma do valor de todos os recebíveis filtrados"><?php echo formatHtmlCurrency($filtered_total_original); ?></span>
                                        </div>
                                        <div class="tstat">
                                            <span class="tstat-label"><i class="bi bi-check-circle-fill text-success"></i> Já recebido</span>
                                            <span class="tstat-value" title="Quanto já entrou (soma do valor recebido nos títulos com status Recebido)"><?php echo formatHtmlCurrency($filtered_total_recebido); ?></span>
                                        </div>
                                        <div class="tstat">
                                            <span class="tstat-label"><i class="bi bi-hourglass-split text-warning"></i> Em aberto</span>
                                            <span class="tstat-value" title="Quanto ainda falta receber (soma dos títulos não recebidos)">
                                                <?php echo formatHtmlCurrency($filtered_total_em_aberto); ?>
                                                <?php if ($filtered_qtd_em_aberto > 0): ?><small class="text-muted fw-normal">· <?php echo $filtered_qtd_em_aberto; ?> tít.</small><?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="tstat tstat-meta">
                                            <span class="tstat-label">Exibindo</span>
                                            <span class="tstat-value"><?php echo count($recebiveis); ?> de <?php echo $total_results; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
                </div>
                <?php
                $first = $offset + 1;
                $last  = min($offset + count($recebiveis), $total_results);
                $page = (int)$page; $total_pages = (int)$total_pages;
                $pgUrl = function($p) use ($current_filters_for_pagination) {
                    $params = $current_filters_for_pagination; $params['page'] = $p;
                    return '?' . http_build_query($params);
                };
                $perPageUrl = function($pp) use ($current_filters_for_links) {
                    $params = $current_filters_for_links; $params['per_page'] = $pp;
                    unset($params['page']);
                    return '?' . http_build_query($params);
                };
                ?>
                <?php if ($total_results > 0): ?>
                <div class="pagination-bar">
                    <div class="info">
                        Mostrando <strong><?php echo $first; ?>–<?php echo $last; ?></strong> de <strong><?php echo $total_results; ?></strong> recebíveis
                    </div>
                    <?php if ($total_pages > 1): ?>
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $pgUrl($page - 1); ?>">«</a></li>
                        <?php
                        $start_page = max(1, $page - 2); $end_page = min($total_pages, $page + 2);
                        if ($page <= 3) $end_page = min($total_pages, 5);
                        if ($page >= $total_pages - 2) $start_page = max(1, $total_pages - 4);
                        if ($start_page > 1) {
                            echo '<li class="page-item"><a class="page-link" href="' . $pgUrl(1) . '">1</a></li>';
                            if ($start_page > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        }
                        for ($i = $start_page; $i <= $end_page; $i++) { ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo $pgUrl($i); ?>"><?php echo $i; ?></a></li>
                        <?php }
                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                            echo '<li class="page-item"><a class="page-link" href="' . $pgUrl($total_pages) . '">' . $total_pages . '</a></li>';
                        }
                        ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $pgUrl($page + 1); ?>">»</a></li>
                    </ul>
                    <?php endif; ?>
                    <div class="d-flex align-items-center gap-2">
                        <label for="per_page_bottom" class="small text-muted mb-0">Por página:</label>
                        <select id="per_page_bottom" class="form-select form-select-sm" style="width: 80px;" onchange="window.location.href=this.value">
                            <?php foreach ($items_per_page_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($perPageUrl($option)); ?>" <?php echo ($items_per_page == $option) ? 'selected' : ''; ?>><?php echo $option; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>

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

    <script>
document.addEventListener('DOMContentLoaded', function () {
    const feedbackDiv = document.getElementById('status-feedback');
    const modalEl = document.getElementById('modalRecebimento');
    const modalRecebimento = modalEl ? new bootstrap.Modal(modalEl) : null;
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

        // Mostra feedback inicial
        feedbackDiv.innerHTML = '<div class="spinner-border spinner-border-sm text-primary" role="status"><span class="visually-hidden">Atualizando...</span></div> Atualizando status...';
        feedbackDiv.className = 'alert alert-info';
        
        let bodyParams = 'id=' + encodeURIComponent(recebivelId) + '&status=' + encodeURIComponent(newStatus);
        if (valorRecebido !== null) {
            bodyParams += '&valor_recebido=' + encodeURIComponent(valorRecebido);
        }

        return fetch('atualizar_status.php', {
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

    document.addEventListener('click', function(event) {
        const button = event.target.closest('.update-status-btn');
        if (!button) return;

        const recebivelId = button.dataset.id;
        const newStatus = button.dataset.status;

        if (newStatus === 'Recebido') {
            const valorOriginal = button.dataset.valorOriginal;
            const valorCorrigido = button.dataset.valorCorrigido;

            if (valorOriginal && valorCorrigido && modalRecebimento) {
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

    /* ===== Ações em massa: marcar vários como recebido ===== */
    const recSelectAll   = document.getElementById('recSelectAll');
    const recBulkBar     = document.getElementById('recBulkBar');
    const recBulkCount   = document.getElementById('recBulkCount');
    const recBulkProgress = document.getElementById('recBulkProgress');
    const recBulkReceber = document.getElementById('recBulkReceber');
    const recBulkClear   = document.getElementById('recBulkClear');

    const recCheckboxes = () => Array.from(document.querySelectorAll('.rec-select'));
    const recSelected = () => recCheckboxes().filter(cb => cb.checked);

    function refreshBulkBar() {
        const all = recCheckboxes();
        const n = recSelected().length;
        if (recBulkCount) recBulkCount.textContent = n;
        if (recBulkBar) recBulkBar.style.display = n > 0 ? 'flex' : 'none';
        if (recSelectAll) {
            recSelectAll.checked = all.length > 0 && n === all.length;
            recSelectAll.indeterminate = n > 0 && n < all.length;
        }
    }

    if (recSelectAll) {
        recSelectAll.addEventListener('change', function () {
            recCheckboxes().forEach(cb => { cb.checked = recSelectAll.checked; });
            refreshBulkBar();
        });
    }
    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('rec-select')) refreshBulkBar();
    });
    if (recBulkClear) {
        recBulkClear.addEventListener('click', function () {
            recCheckboxes().forEach(cb => { cb.checked = false; });
            refreshBulkBar();
        });
    }
    if (recBulkReceber) {
        recBulkReceber.addEventListener('click', async function () {
            const selected = recSelected();
            if (!selected.length) return;
            if (!confirm('Marcar ' + selected.length + ' recebível(is) como recebido?')) return;

            recBulkReceber.disabled = true;
            let done = 0;
            for (const cb of selected) {
                const id = cb.dataset.id;
                const row = document.getElementById('recebivel-row-' + id);
                const recBtn = row ? row.querySelector('.update-status-btn[data-status="Recebido"]') : null;
                const valor = recBtn ? recBtn.dataset.valorCorrigido : null;
                if (recBulkProgress) recBulkProgress.textContent = 'Processando ' + (done + 1) + '/' + selected.length + '…';
                try { await performStatusUpdate(id, 'Recebido', valor); } catch (err) { /* feedback já tratado */ }
                cb.checked = false;
                cb.remove(); // linha agora está "Recebido": não é mais selecionável
                done++;
            }
            if (recBulkProgress) recBulkProgress.textContent = done + ' recebível(is) atualizado(s).';
            recBulkReceber.disabled = false;
            refreshBulkBar();
        });
    }
});
    </script>
</body>
</html>
