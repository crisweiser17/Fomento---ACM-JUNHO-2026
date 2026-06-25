<?php require_once 'auth_check.php'; ?><?php
require_once 'db_connection.php';
require_once 'ui_helpers.php';

// --- Configurações de Paginação, Ordenação e Busca ---
$results_per_page = 15;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'id';
$dir = isset($_GET['dir']) && in_array(strtolower($_GET['dir']), ['asc', 'desc']) ? strtolower($_GET['dir']) : 'desc';

// Filtros
$filter_cedente = isset($_GET['filter_cedente']) ? trim($_GET['filter_cedente']) : '';
$filter_status = isset($_GET['filter_status']) && is_array($_GET['filter_status']) ? $_GET['filter_status'] : [];
$filter_valor_min = isset($_GET['filter_valor_min']) ? floatval($_GET['filter_valor_min']) : 0;
$filter_valor_max = isset($_GET['filter_valor_max']) ? floatval($_GET['filter_valor_max']) : 0;
$filter_data = isset($_GET['filter_data']) ? trim($_GET['filter_data']) : '';
$filter_data_inicio = isset($_GET['filter_data_inicio']) ? trim($_GET['filter_data_inicio']) : '';
$filter_data_fim = isset($_GET['filter_data_fim']) ? trim($_GET['filter_data_fim']) : '';
$filter_tipo_operacao = isset($_GET['filter_tipo_operacao']) && is_array($_GET['filter_tipo_operacao']) ? $_GET['filter_tipo_operacao'] : [];

$allowed_sort_columns = [
    'id' => 'o.id',
    'cedente_nome' => 's.empresa',
    'taxa_mensal' => 'o.taxa_mensal',
    'total_original_calc' => 'o.total_original_calc',
    'total_liquido_pago_calc' => 'o.total_liquido_pago_calc',
    'total_lucro_liquido_calc' => 'o.total_lucro_liquido_calc',
    'data_operacao' => 'o.data_operacao',
    'data_base_calculo' => 'o.data_operacao',
    'tipo_operacao' => 'o.tipo_operacao',
    'media_dias_operacao' => 'media_dias_operacao',
    'status_operacao' => 'status_operacao',
    'num_recebiveis' => 'num_recebiveis',
    'saldo_em_aberto' => 'saldo_em_aberto'
];
if (!array_key_exists($sort, $allowed_sort_columns)) $sort = 'id';
$sort_column_sql = $allowed_sort_columns[$sort];

// Processamento dos Filtros de Data
$data_inicio = null; $data_fim = null;
if (!empty($filter_data)) {
    $hoje = date('Y-m-d');
    switch ($filter_data) {
        case 'hoje': $data_inicio = $data_fim = $hoje; break;
        case 'ontem': $data_inicio = $data_fim = date('Y-m-d', strtotime('-1 day')); break;
        case 'ultimos_7_dias':
            $data_inicio = date('Y-m-d', strtotime('-7 days'));
            $data_fim = $hoje; break;
        case 'mes_atual':
            $data_inicio = date('Y-m-01');
            $data_fim = date('Y-m-t'); break;
        case 'mes_passado':
            $data_inicio = date('Y-m-01', strtotime('first day of last month'));
            $data_fim = date('Y-m-t', strtotime('last day of last month')); break;
        case 'custom':
            $data_inicio = !empty($filter_data_inicio) ? $filter_data_inicio : null;
            $data_fim = !empty($filter_data_fim) ? $filter_data_fim : null;
            break;
    }
}

$offset = max(0, ($page - 1) * $results_per_page);

// --- Construção da Query com Filtros ---
$params_count = []; $params_data = []; $whereClauses = [];

if (!empty($search)) {
    $whereClauses[] = "(CAST(o.id AS CHAR) LIKE :search_id OR s.empresa LIKE :search_nome)";
    $sp = "%$search%";
    $params_count[':search_id'] = $sp; $params_count[':search_nome'] = $sp;
    $params_data[':search_id'] = $sp; $params_data[':search_nome'] = $sp;
}
if (!empty($filter_cedente)) {
    $whereClauses[] = "o.cedente_id = :filter_cedente";
    $params_count[':filter_cedente'] = $filter_cedente;
    $params_data[':filter_cedente'] = $filter_cedente;
}
if ($filter_valor_min > 0) {
    $whereClauses[] = "o.total_liquido_pago_calc >= :filter_valor_min";
    $params_count[':filter_valor_min'] = $filter_valor_min;
    $params_data[':filter_valor_min'] = $filter_valor_min;
}
if ($filter_valor_max > 0) {
    $whereClauses[] = "o.total_liquido_pago_calc <= :filter_valor_max";
    $params_count[':filter_valor_max'] = $filter_valor_max;
    $params_data[':filter_valor_max'] = $filter_valor_max;
}
if (!empty($filter_tipo_operacao)) {
    $placeholders = [];
    for ($i = 0; $i < count($filter_tipo_operacao); $i++) {
        $placeholders[] = ":tipo_operacao_$i";
        $params_count[":tipo_operacao_$i"] = $filter_tipo_operacao[$i];
        $params_data[":tipo_operacao_$i"] = $filter_tipo_operacao[$i];
    }
    $whereClauses[] = "o.tipo_operacao IN (" . implode(',', $placeholders) . ")";
}
if ($data_inicio && $data_fim) {
    $whereClauses[] = "DATE(o.data_operacao) BETWEEN :data_inicio AND :data_fim";
    $params_count[':data_inicio'] = $data_inicio; $params_count[':data_fim'] = $data_fim;
    $params_data[':data_inicio'] = $data_inicio; $params_data[':data_fim'] = $data_fim;
} elseif ($data_inicio) {
    $whereClauses[] = "DATE(o.data_operacao) >= :data_inicio";
    $params_count[':data_inicio'] = $data_inicio; $params_data[':data_inicio'] = $data_inicio;
} elseif ($data_fim) {
    $whereClauses[] = "DATE(o.data_operacao) <= :data_fim";
    $params_count[':data_fim'] = $data_fim; $params_data[':data_fim'] = $data_fim;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Contagem
$total_results = 0;
try {
    $countSql = "SELECT COUNT(DISTINCT o.id)
                 FROM operacoes o
                 LEFT JOIN clientes s ON o.cedente_id = s.id
                 $whereSql";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params_count);
    $total_results = (int)$stmtCount->fetchColumn();
} catch (PDOException $e) { $error_message_count = "Erro Crítico [Count]: " . htmlspecialchars($e->getMessage()); }

$total_pages = max(1, (int) ceil($total_results / $results_per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = max(0, ($page - 1) * $results_per_page); }
elseif ($page < 1) { $page = 1; $offset = 0; }

// Query principal
$operacoes = [];
if (!isset($error_message_count)) {
    try {
        $sql = "SELECT
                    o.id, o.cedente_id, o.data_operacao, o.taxa_mensal, o.tipo_operacao,
                    o.total_original_calc, o.total_liquido_pago_calc, o.total_lucro_liquido_calc,
                    COALESCE(s.empresa, s.nome, (SELECT COALESCE(sac.empresa, sac.nome) FROM recebiveis r2 JOIN clientes sac ON r2.sacado_id = sac.id WHERE r2.operacao_id = o.id LIMIT 1)) AS cedente_nome,
                    AVG(DATEDIFF(r.data_vencimento, o.data_operacao)) AS media_dias_operacao,
                    CASE
                        WHEN SUM(CASE WHEN r.status = 'Problema' THEN 1 ELSE 0 END) > 0 THEN 'Com Problema'
                        WHEN SUM(CASE WHEN r.status = 'Em Aberto' THEN 1 ELSE 0 END) > 0 THEN 'Em Aberto'
                        WHEN SUM(CASE WHEN r.status = 'Parcialmente Compensado' THEN 1 ELSE 0 END) > 0 THEN 'Parcialmente Compensada'
                        WHEN SUM(CASE WHEN r.status IN ('Recebido', 'Compensado') THEN 1 ELSE 0 END) = COUNT(r.id) AND COUNT(r.id) > 0 THEN 'Concluída'
                        ELSE 'Em Aberto'
                    END AS status_operacao,
                    COUNT(r.id) AS num_recebiveis,
                    SUM(CASE
                        WHEN r.status = 'Recebido' THEN 0
                        WHEN r.status = 'Parcialmente Compensado' THEN
                            COALESCE((SELECT c.saldo_restante FROM compensacoes c WHERE c.recebivel_compensado_id = r.id ORDER BY c.data_compensacao DESC LIMIT 1), r.valor_original)
                        ELSE r.valor_original
                    END) AS saldo_em_aberto,
                    o.data_operacao AS data_base_calculo
                FROM operacoes o
                LEFT JOIN clientes s ON o.cedente_id = s.id
                LEFT JOIN recebiveis r ON o.id = r.operacao_id
                $whereSql
                GROUP BY o.id, o.cedente_id, o.data_operacao, o.taxa_mensal, o.tipo_operacao,
                         o.total_original_calc, o.total_liquido_pago_calc, o.total_lucro_liquido_calc,
                         s.empresa";

        if (!empty($filter_status)) {
            $placeholders = [];
            for ($i = 0; $i < count($filter_status); $i++) $placeholders[] = ":filter_status_$i";
            $sql .= " HAVING status_operacao IN (" . implode(',', $placeholders) . ")";
        }

        $sql .= " ORDER BY $sort_column_sql $dir LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);

        if (!empty($search)) {
            $stmt->bindParam(':search_id', $params_data[':search_id'], PDO::PARAM_STR);
            $stmt->bindParam(':search_nome', $params_data[':search_nome'], PDO::PARAM_STR);
        }
        if (!empty($filter_cedente)) $stmt->bindParam(':filter_cedente', $filter_cedente, PDO::PARAM_INT);
        if (!empty($filter_valor_min)) $stmt->bindParam(':filter_valor_min', $filter_valor_min);
        if (!empty($filter_valor_max)) $stmt->bindParam(':filter_valor_max', $filter_valor_max);
        if (!empty($filter_data_inicio)) $stmt->bindParam(':filter_data_inicio', $filter_data_inicio);
        if (!empty($filter_data_fim)) $stmt->bindParam(':filter_data_fim', $filter_data_fim);
        if (!empty($filter_status)) {
            for ($i = 0; $i < count($filter_status); $i++) $stmt->bindParam(":filter_status_$i", $filter_status[$i]);
        }
        if (!empty($filter_tipo_operacao)) {
            for ($i = 0; $i < count($filter_tipo_operacao); $i++) $stmt->bindParam(":tipo_operacao_$i", $params_data[":tipo_operacao_$i"]);
        }
        $stmt->bindParam(':limit', $results_per_page, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $operacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $error_message_data = "Erro Crítico [Data]: " . htmlspecialchars($e->getMessage()); }
}

// KPIs gerais (independe de paginação, mas considera filtros aplicados)
$kpis = ['filtradas' => $total_results, 'total_geral' => 0, 'volume' => 0, 'lucro' => 0, 'saldo_aberto' => 0];
try {
    $kpis['total_geral'] = (int) $pdo->query("SELECT COUNT(*) FROM operacoes")->fetchColumn();

    // Volume e lucro filtrados
    $sumSql = "SELECT
                  COALESCE(SUM(o.total_original_calc),0) AS volume,
                  COALESCE(SUM(o.total_lucro_liquido_calc),0) AS lucro
               FROM operacoes o
               LEFT JOIN clientes s ON o.cedente_id = s.id
               $whereSql";
    $stmtSum = $pdo->prepare($sumSql);
    $stmtSum->execute($params_count);
    $row = $stmtSum->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $kpis['volume'] = (float) $row['volume'];
        $kpis['lucro']  = (float) $row['lucro'];
    }

    // Saldo aberto (independente de filtros — é o "geral")
    $kpis['saldo_aberto'] = (float) $pdo->query("
        SELECT COALESCE(SUM(CASE
            WHEN r.status = 'Recebido' THEN 0
            WHEN r.status = 'Parcialmente Compensado' THEN
                COALESCE((SELECT c.saldo_restante FROM compensacoes c WHERE c.recebivel_compensado_id = r.id ORDER BY c.data_compensacao DESC LIMIT 1), r.valor_original)
            ELSE r.valor_original
        END), 0)
        FROM recebiveis r
        WHERE r.status IN ('Em Aberto', 'Parcialmente Compensado', 'Problema')
    ")->fetchColumn();
} catch (PDOException $e) { /* ignora */ }

// Helpers
function getSortLink($column, $text, $currentSort, $currentDir, $currentSearch, $filters = []) {
    $newDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($currentSort === $column) {
        $arrow = $currentDir === 'asc' ? ' <i class="bi bi-arrow-up"></i>' : ' <i class="bi bi-arrow-down"></i>';
    }
    $params = ['sort' => $column, 'dir' => $newDir];
    if ($currentSearch) $params['search'] = $currentSearch;
    foreach ($filters as $k => $v) {
        if (is_array($v)) { foreach ($v as $vv) if ($vv !== '') $params[$k][] = $vv; }
        elseif (!empty($v)) $params[$k] = $v;
    }
    return '<a href="?' . http_build_query($params) . '">' . htmlspecialchars($text) . $arrow . '</a>';
}

function statusPill($status) {
    $map = [
        'Concluída' => ['s-concluida', 'Concluída', 'check-circle-fill'],
        'Em Aberto' => ['s-aberto', 'Em aberto', 'clock-fill'],
        'Parcialmente Compensada' => ['s-parcial', 'Parcial', 'pie-chart-fill'],
        'Com Problema' => ['s-problema', 'Problema', 'exclamation-triangle-fill'],
    ];
    $info = $map[$status] ?? ['s-aberto', $status, 'circle-fill'];
    return '<span class="status-pill ' . $info[0] . '"><i class="bi bi-' . $info[2] . '"></i> ' . htmlspecialchars($info[1]) . '</span>';
}

function formatHtmlCurrency($value) {
    return 'R$ ' . number_format($value ?? 0, 2, ',', '.');
}
function moedaCompact($v) {
    $v = (float)$v;
    if ($v >= 1000000) return 'R$ ' . number_format($v / 1000000, 2, ',', '.') . ' mi';
    if ($v >= 10000)   return 'R$ ' . number_format($v / 1000, 1, ',', '.') . ' mil';
    return formatHtmlCurrency($v);
}
function iniciais($nome) {
    $nome = trim((string)$nome);
    if ($nome === '') return '?';
    $partes = preg_split('/\s+/', $nome);
    $r = '';
    foreach ($partes as $p) {
        if (strlen($r) >= 2) break;
        $r .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $r;
}
function avatarColor($id) {
    $cls = ['b1', 'b2', 'b3', 'b4'];
    return $cls[((int)$id) % count($cls)];
}

// Lista de cedentes para filtro
$cedentes_list = [];
try {
    $stmt_sacados = $pdo->query("SELECT id, COALESCE(empresa, nome) AS nome FROM clientes ORDER BY COALESCE(empresa, nome)");
    $cedentes_list = $stmt_sacados->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Construir lista de chips ativos
$activeFilters = [];
if (!empty($search)) $activeFilters[] = ['label' => 'Busca: ' . $search, 'param' => 'search', 'cls' => 'f-info'];
if (!empty($filter_cedente)) {
    $cedNome = '';
    foreach ($cedentes_list as $c) if ((int)$c['id'] === (int)$filter_cedente) { $cedNome = $c['nome']; break; }
    $activeFilters[] = ['label' => 'Cliente: ' . ($cedNome ?: '#' . $filter_cedente), 'param' => 'filter_cedente', 'cls' => 'f-info'];
}
foreach ($filter_status as $st) {
    $activeFilters[] = ['label' => $st, 'param' => 'filter_status', 'value' => $st, 'cls' => 'f-warn'];
}
foreach ($filter_tipo_operacao as $t) {
    $cls = $t === 'antecipacao' ? 'f-green' : 'f-warn';
    $lab = $t === 'antecipacao' ? 'Antecipação' : 'Empréstimo';
    $activeFilters[] = ['label' => $lab, 'param' => 'filter_tipo_operacao', 'value' => $t, 'cls' => $cls];
}
if ($filter_valor_min > 0) $activeFilters[] = ['label' => 'Valor min: ' . formatHtmlCurrency($filter_valor_min), 'param' => 'filter_valor_min', 'cls' => 'f-info'];
if ($filter_valor_max > 0) $activeFilters[] = ['label' => 'Valor max: ' . formatHtmlCurrency($filter_valor_max), 'param' => 'filter_valor_max', 'cls' => 'f-info'];
if (!empty($filter_data)) {
    $labMap = ['hoje'=>'Hoje','ontem'=>'Ontem','ultimos_7_dias'=>'Últimos 7 dias','mes_atual'=>'Mês atual','mes_passado'=>'Mês passado','custom'=>'Período personalizado'];
    $activeFilters[] = ['label' => 'Período: ' . ($labMap[$filter_data] ?? $filter_data), 'param' => 'filter_data', 'cls' => 'f-info'];
}

// Helper para gerar URL removendo um filtro específico
function urlSemFiltro($paramRemove, $valorRemove = null) {
    $q = $_GET;
    if ($valorRemove !== null && isset($q[$paramRemove]) && is_array($q[$paramRemove])) {
        $q[$paramRemove] = array_values(array_filter($q[$paramRemove], fn($v) => $v !== $valorRemove));
        if (empty($q[$paramRemove])) unset($q[$paramRemove]);
    } else {
        unset($q[$paramRemove]);
        if ($paramRemove === 'filter_data') unset($q['filter_data_inicio'], $q['filter_data_fim']);
    }
    unset($q['page']);
    return '?' . http_build_query($q);
}

$current_filters = [
    'filter_cedente' => $filter_cedente,
    'filter_status' => $filter_status,
    'filter_tipo_operacao' => $filter_tipo_operacao,
    'filter_valor_min' => $filter_valor_min > 0 ? $filter_valor_min : '',
    'filter_valor_max' => $filter_valor_max > 0 ? $filter_valor_max : '',
    'filter_data' => $filter_data,
    'filter_data_inicio' => $filter_data_inicio,
    'filter_data_fim' => $filter_data_fim
];

?>
<?php
$pageTitle = 'Operações';
require_once 'head.php';
?>
    <style>
        /* Estilos específicos da página (tokens e componentes base vêm de theme.css) */
        body { font-size: 0.95rem; }

        /* Variantes de cor dos filter chips (esta página usa f-info/f-green/f-warn) */
        .filter-chip.f-info  { background: var(--info-soft); color: var(--info); border-color: #c8dafc; }
        .filter-chip.f-green { background: var(--profit-soft); color: var(--profit); border-color: #b3e3c4; }
        .filter-chip.f-warn  { background: var(--warn-soft); color: var(--warn); border-color: #f1d999; }
        .filter-chip.btn-add {
            background: var(--surface-2); color: var(--neutral);
            border: 1px dashed var(--border); cursor: pointer;
        }
        .filter-chip.btn-add:hover { background: #e9ecef; color: var(--info); border-color: var(--info); }

        /* Filter form (collapsible) — específico desta página */
        .filter-form-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 14px; margin-bottom: 18px;
        }
        .filter-form-card .head {
            padding: 12px 16px; border-bottom: 1px solid var(--border);
            background: var(--surface-2); font-weight: 600; font-size: 0.9rem;
            display: flex; justify-content: space-between; align-items: center;
        }
        .filter-form-card .body { padding: 16px; }
        .form-label-strong {
            font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em;
            color: var(--neutral); font-weight: 600; margin-bottom: 4px; display: block;
        }
    </style>

    <div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">

        <?php if (isset($error_message_count)) echo "<div class='alert alert-danger'>$error_message_count</div>"; ?>
        <?php if (isset($error_message_data)) echo "<div class='alert alert-danger'>$error_message_data</div>"; ?>

        <!-- Toolbar -->
        <div class="page-toolbar">
            <div>
                <h1>
                    <i class="bi bi-cash-stack text-primary"></i>
                    Operações
                    <span class="id-pill"><i class="bi bi-hash"></i><?php echo $kpis['total_geral']; ?> registradas</span>
                </h1>
                <div class="text-muted small mt-1">Acompanhamento de antecipações e empréstimos</div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <form method="GET" action="listar_operacoes.php" class="d-flex gap-2">
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                    <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
                    <div class="input-group input-group-sm" style="width: 260px;">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" name="search"
                               placeholder="Buscar por ID ou cliente…"
                               value="<?php echo htmlspecialchars($search); ?>">
                        <?php if ($search !== ''): ?>
                            <a href="?<?php echo http_build_query(['sort'=>$sort,'dir'=>$dir]); ?>"
                               class="btn btn-outline-secondary" title="Limpar busca"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
                <a href="exportar_csv.php?<?php echo http_build_query($_GET); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-download"></i> Exportar CSV
                </a>
                <a href="simulacao.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nova Operação</a>
            </div>
        </div>

        <!-- KPIs -->
        <div class="kpi-strip">
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-list-ol"></i></div>
                <div class="k-label">Operações filtradas</div>
                <div class="k-value"><?php echo $kpis['filtradas']; ?></div>
                <div class="k-trend">de <?php echo $kpis['total_geral']; ?> totais</div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-hourglass-split"></i></div>
                <div class="k-label">Saldo em aberto</div>
                <div class="k-value"><?php echo moedaCompact($kpis['saldo_aberto']); ?></div>
                <div class="k-trend">títulos a receber</div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-bar-chart-fill"></i></div>
                <div class="k-label">Volume operado</div>
                <div class="k-value"><?php echo moedaCompact($kpis['volume']); ?></div>
                <div class="k-trend">total nominal</div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-green"><i class="bi bi-piggy-bank-fill"></i></div>
                <div class="k-label">Lucro líquido</div>
                <div class="k-value"><?php echo moedaCompact($kpis['lucro']); ?></div>
                <?php if ($kpis['volume'] > 0): ?>
                    <div class="k-trend up"><i class="bi bi-arrow-up"></i> margem <?php echo number_format(($kpis['lucro'] / $kpis['volume']) * 100, 1, ',', '.'); ?>%</div>
                <?php else: ?>
                    <div class="k-trend">resultado consolidado</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter chips ativos -->
        <div class="filter-bar">
            <span class="filter-label">Filtros:</span>
            <?php if (!empty($activeFilters)): ?>
                <?php foreach ($activeFilters as $f): ?>
                    <span class="filter-chip <?php echo $f['cls']; ?>">
                        <?php echo htmlspecialchars($f['label']); ?>
                        <a href="<?php echo urlSemFiltro($f['param'], $f['value'] ?? null); ?>" class="x" title="Remover"><i class="bi bi-x"></i></a>
                    </span>
                <?php endforeach; ?>
                <a href="listar_operacoes.php?<?php echo http_build_query(['sort'=>$sort,'dir'=>$dir]); ?>" class="filter-chip btn-clear ms-auto">
                    <i class="bi bi-x-circle"></i> Limpar tudo
                </a>
            <?php else: ?>
                <span class="text-muted small">Nenhum filtro ativo</span>
            <?php endif; ?>
            <button class="filter-chip btn-add <?php echo empty($activeFilters) ? 'ms-auto' : ''; ?>" type="button"
                    data-bs-toggle="collapse" data-bs-target="#filtrosCollapse">
                <i class="bi bi-funnel"></i> <?php echo empty($activeFilters) ? 'Adicionar filtros' : 'Ajustar filtros'; ?>
            </button>
        </div>

        <!-- Filter form (collapse) -->
        <div class="collapse <?php echo empty($activeFilters) ? '' : ''; ?>" id="filtrosCollapse">
            <div class="filter-form-card">
                <div class="head">
                    <span><i class="bi bi-funnel"></i> Filtrar operações</span>
                </div>
                <div class="body">
                    <form method="GET" action="listar_operacoes.php">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                        <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="filter_cedente" class="form-label-strong">Cliente</label>
                                <select class="form-select" id="filter_cedente" name="filter_cedente">
                                    <option value="">Todos os clientes</option>
                                    <?php foreach ($cedentes_list as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo ($filter_cedente == $c['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label-strong">Status</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php
                                    $status_options = ['Em Aberto', 'Parcialmente Compensada', 'Concluída', 'Com Problema'];
                                    foreach ($status_options as $st):
                                        $checked = in_array($st, $filter_status) ? 'checked' : '';
                                        $id = 'st_' . md5($st);
                                    ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="filter_status[]" value="<?php echo htmlspecialchars($st); ?>" id="<?php echo $id; ?>" <?php echo $checked; ?>>
                                            <label class="form-check-label small" for="<?php echo $id; ?>"><?php echo htmlspecialchars($st); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label-strong">Tipo</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach (['antecipacao'=>'Antecipação','emprestimo'=>'Empréstimo'] as $val => $lab):
                                        $checked = in_array($val, $filter_tipo_operacao) ? 'checked' : '';
                                    ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="filter_tipo_operacao[]" value="<?php echo $val; ?>" id="to_<?php echo $val; ?>" <?php echo $checked; ?>>
                                            <label class="form-check-label small" for="to_<?php echo $val; ?>"><?php echo $lab; ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label for="filter_valor_min" class="form-label-strong">Valor mínimo</label>
                                <input type="number" class="form-control" id="filter_valor_min" name="filter_valor_min" step="0.01" min="0"
                                       placeholder="0,00" value="<?php echo $filter_valor_min > 0 ? $filter_valor_min : ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="filter_valor_max" class="form-label-strong">Valor máximo</label>
                                <input type="number" class="form-control" id="filter_valor_max" name="filter_valor_max" step="0.01" min="0"
                                       placeholder="0,00" value="<?php echo $filter_valor_max > 0 ? $filter_valor_max : ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="filter_data" class="form-label-strong">Período</label>
                                <select class="form-select" id="filter_data" name="filter_data" onchange="toggleCustomDates()">
                                    <option value="">Todas as datas</option>
                                    <option value="hoje" <?php echo ($filter_data == 'hoje') ? 'selected' : ''; ?>>Hoje</option>
                                    <option value="ontem" <?php echo ($filter_data == 'ontem') ? 'selected' : ''; ?>>Ontem</option>
                                    <option value="ultimos_7_dias" <?php echo ($filter_data == 'ultimos_7_dias') ? 'selected' : ''; ?>>Últimos 7 dias</option>
                                    <option value="mes_atual" <?php echo ($filter_data == 'mes_atual') ? 'selected' : ''; ?>>Mês atual</option>
                                    <option value="mes_passado" <?php echo ($filter_data == 'mes_passado') ? 'selected' : ''; ?>>Mês passado</option>
                                    <option value="custom" <?php echo ($filter_data == 'custom') ? 'selected' : ''; ?>>Período personalizado</option>
                                </select>
                            </div>
                            <div class="col-md-3" id="custom_dates_wrap" style="display:<?php echo $filter_data === 'custom' ? 'block' : 'none'; ?>;">
                                <label class="form-label-strong">Início → Fim</label>
                                <div class="input-group">
                                    <input type="date" class="form-control" id="filter_data_inicio" name="filter_data_inicio"
                                           value="<?php echo htmlspecialchars($filter_data_inicio); ?>">
                                    <input type="date" class="form-control" id="filter_data_fim" name="filter_data_fim"
                                           value="<?php echo htmlspecialchars($filter_data_fim); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Aplicar filtros</button>
                            <a href="?<?php echo http_build_query(['sort'=>$sort,'dir'=>$dir,'search'=>$search]); ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Limpar
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tabela / Empty -->
        <?php if (empty($operacoes) && !isset($error_message_count) && !isset($error_message_data)): ?>
            <?php
            if (empty($activeFilters) && empty($search) && (int)$total_results === 0) {
                echo ui_empty_state(
                    'bi-inbox',
                    'Nenhuma operação registrada',
                    'Ainda não há operações cadastradas no sistema.',
                    ['label' => 'Nova Operação', 'href' => 'simulacao.php', 'icon' => 'bi-plus-circle']
                );
            } else {
                echo ui_empty_state(
                    'bi-search',
                    'Nenhum resultado',
                    'Não encontramos operações para os filtros, busca ou página atual.',
                    ['label' => 'Limpar filtros', 'href' => 'listar_operacoes.php', 'icon' => 'bi-x-circle']
                );
            }
            ?>
        <?php elseif (!empty($operacoes)): ?>
            <div class="data-table-wrap">
                <div class="data-table-head">
                    <h3>Resultado</h3>
                    <div class="meta">
                        <strong><?php echo $total_results; ?></strong> operações ·
                        Total nominal <strong><?php echo moedaCompact($kpis['volume']); ?></strong> ·
                        Lucro <strong class="profit"><?php echo moedaCompact($kpis['lucro']); ?></strong>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:60px;"><?php echo getSortLink('id', '#', $sort, $dir, $search, $current_filters); ?></th>
                                <th><?php echo getSortLink('cedente_nome', 'Cliente', $sort, $dir, $search, $current_filters); ?></th>
                                <th><?php echo getSortLink('tipo_operacao', 'Tipo', $sort, $dir, $search, $current_filters); ?></th>
                                <th class="text-end"><?php echo getSortLink('taxa_mensal', 'Taxa', $sort, $dir, $search, $current_filters); ?></th>
                                <th class="text-end"><?php echo getSortLink('total_original_calc', 'Nominal', $sort, $dir, $search, $current_filters); ?></th>
                                <th class="text-end"><?php echo getSortLink('total_liquido_pago_calc', 'Líquido pago', $sort, $dir, $search, $current_filters); ?></th>
                                <th class="text-end"><?php echo getSortLink('total_lucro_liquido_calc', 'Lucro', $sort, $dir, $search, $current_filters); ?></th>
                                <th class="text-center col-optional"><?php echo getSortLink('media_dias_operacao', 'Dias', $sort, $dir, $search, $current_filters); ?></th>
                                <th class="text-center"><?php echo getSortLink('status_operacao', 'Status', $sort, $dir, $search, $current_filters); ?></th>
                                <th class="text-center col-optional"><?php echo getSortLink('num_recebiveis', '# Rec.', $sort, $dir, $search, $current_filters); ?></th>
                                <th class="text-end"><?php echo getSortLink('saldo_em_aberto', 'Saldo aberto', $sort, $dir, $search, $current_filters); ?></th>
                                <th><?php echo getSortLink('data_base_calculo', 'Data', $sort, $dir, $search, $current_filters); ?></th>
                                <th class="text-center" style="width:90px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($operacoes as $op): ?>
                                <tr>
                                    <td><strong>#<?php echo (int)$op['id']; ?></strong></td>
                                    <td>
                                        <div class="client-cell">
                                            <div class="avatar <?php echo avatarColor($op['cedente_id']); ?>"><?php echo iniciais($op['cedente_nome'] ?? ''); ?></div>
                                            <div>
                                                <div class="name" title="<?php echo htmlspecialchars($op['cedente_nome'] ?? 'N/A'); ?>"><?php echo htmlspecialchars($op['cedente_nome'] ?? 'N/A'); ?></div>
                                                <div class="doc"><?php echo (int)$op['num_recebiveis']; ?> título<?php echo (int)$op['num_recebiveis'] === 1 ? '' : 's'; ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (($op['tipo_operacao'] ?? 'antecipacao') === 'emprestimo'): ?>
                                            <span class="pill-tipo empr"><i class="bi bi-cash-coin"></i> Empréstimo</span>
                                        <?php else: ?>
                                            <span class="pill-tipo antecip"><i class="bi bi-arrow-return-left"></i> Antecipação</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end num"><?php echo number_format(($op['taxa_mensal'] ?? 0) * 100, 2, ',', '.') . '%'; ?></td>
                                    <td class="text-end num"><?php echo formatHtmlCurrency($op['total_original_calc']); ?></td>
                                    <td class="text-end num"><?php echo formatHtmlCurrency($op['total_liquido_pago_calc']); ?></td>
                                    <td class="text-end profit"><?php echo formatHtmlCurrency($op['total_lucro_liquido_calc']); ?></td>
                                    <td class="text-center text-muted col-optional">
                                        <?php echo isset($op['media_dias_operacao']) ? round((float)$op['media_dias_operacao']) : '—'; ?>
                                    </td>
                                    <td class="text-center"><?php echo statusPill($op['status_operacao']); ?></td>
                                    <td class="text-center col-optional"><?php echo (int)($op['num_recebiveis'] ?? 0); ?></td>
                                    <td class="text-end num">
                                        <?php
                                        $saldo = (float)($op['saldo_em_aberto'] ?? 0);
                                        echo $saldo > 0 ? formatHtmlCurrency($saldo) : '<span class="text-muted">—</span>';
                                        ?>
                                    </td>
                                    <td class="text-muted small"><?php echo isset($op['data_base_calculo']) ? date('d/m/Y', strtotime($op['data_base_calculo'])) : '-'; ?></td>
                                    <td class="text-center">
                                        <div class="row-actions">
                                            <a href="detalhes_operacao.php?id=<?php echo $op['id']; ?>" class="btn-ico" title="Ver detalhes"><i class="bi bi-eye-fill"></i></a>
                                            <a href="excluir_operacao.php?id=<?php echo $op['id']; ?>" class="btn-ico danger delete-operacao-btn" data-operacao-id="<?php echo $op['id']; ?>" title="Excluir"><i class="bi bi-trash3-fill"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <?php
                                $soma_taxas = 0; $count_taxas = 0;
                                $soma_dias = 0; $count_dias = 0;
                                foreach ($operacoes as $op2) {
                                    if (!empty($op2['taxa_mensal'])) { $soma_taxas += (float)$op2['taxa_mensal']; $count_taxas++; }
                                    if (!empty($op2['media_dias_operacao'])) { $soma_dias += (float)$op2['media_dias_operacao']; $count_dias++; }
                                }
                                $media_taxa = $count_taxas > 0 ? ($soma_taxas / $count_taxas) : 0;
                                $media_dias = $count_dias > 0 ? ($soma_dias / $count_dias) : 0;
                                $total_original = array_sum(array_column($operacoes, 'total_original_calc'));
                                $total_liquido = array_sum(array_column($operacoes, 'total_liquido_pago_calc'));
                                $total_lucro = array_sum(array_column($operacoes, 'total_lucro_liquido_calc'));
                                $total_recebiveis = array_sum(array_column($operacoes, 'num_recebiveis'));
                                $total_saldo = array_sum(array_column($operacoes, 'saldo_em_aberto'));
                                ?>
                                <td colspan="3" class="text-end">TOTAIS (página):</td>
                                <td class="text-end"><?php echo number_format($media_taxa * 100, 2, ',', '.') . '%'; ?></td>
                                <td class="text-end"><?php echo formatHtmlCurrency($total_original); ?></td>
                                <td class="text-end"><?php echo formatHtmlCurrency($total_liquido); ?></td>
                                <td class="text-end profit"><?php echo formatHtmlCurrency($total_lucro); ?></td>
                                <td class="text-center col-optional"><?php echo round($media_dias); ?> méd.</td>
                                <td></td>
                                <td class="text-center col-optional"><?php echo (int)$total_recebiveis; ?></td>
                                <td class="text-end"><?php echo formatHtmlCurrency($total_saldo); ?></td>
                                <td colspan="2" class="text-center text-muted small"><?php echo count($operacoes); ?> operações</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php
                $first = $offset + 1;
                $last  = min($offset + count($operacoes), $total_results);
                $pgParams = array_filter([
                    'sort' => $sort, 'dir' => $dir, 'search' => $search,
                    'filter_cedente' => $filter_cedente,
                    'filter_status' => $filter_status,
                    'filter_tipo_operacao' => $filter_tipo_operacao,
                    'filter_valor_min' => $filter_valor_min > 0 ? $filter_valor_min : '',
                    'filter_valor_max' => $filter_valor_max > 0 ? $filter_valor_max : '',
                    'filter_data' => $filter_data,
                    'filter_data_inicio' => $filter_data_inicio,
                    'filter_data_fim' => $filter_data_fim
                ], fn($v) => $v !== '' && $v !== null && $v !== []);
                $pgUrl = function($p) use ($pgParams) {
                    $params = $pgParams; $params['page'] = $p;
                    return '?' . http_build_query($params);
                };
                ?>
                <?php $page = (int)$page; $total_pages = (int)$total_pages; ?>
                <div class="pagination-bar">
                    <div class="info">Mostrando <strong><?php echo $first; ?>–<?php echo $last; ?></strong> de <strong><?php echo $total_results; ?></strong> operações</div>
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
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
        function toggleCustomDates() {
            const sel = document.getElementById('filter_data');
            const wrap = document.getElementById('custom_dates_wrap');
            wrap.style.display = sel.value === 'custom' ? 'block' : 'none';
        }
        document.addEventListener('DOMContentLoaded', function () {
            toggleCustomDates();
            document.querySelectorAll('.delete-operacao-btn').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    const id = this.dataset.operacaoId;
                    const url = this.href;
                    if (confirm(`ATENÇÃO!\n\nExcluir Operação #${id}?\n\n- Esta ação NÃO pode ser desfeita.\n- Todos os recebíveis associados serão EXCLUÍDOS.`)) {
                        if (confirm(`CONFIRMAÇÃO FINAL:\n\nExcluir PERMANENTEMENTE a Operação #${id}?`)) {
                            window.location.href = url;
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
