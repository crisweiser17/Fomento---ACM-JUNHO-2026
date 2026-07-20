<?php require_once 'auth_check.php'; ?><?php
require_once 'db_connection.php';
require_once 'ui_helpers.php';

// --- Configurações de Paginação, Ordenação e Busca ---
$results_per_page = 15;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
// Sem parâmetros na URL, abre pelos clientes cadastrados mais recentemente.
// Ordena por id, não por data_cadastro: clientes migrados de `sacados` mantiveram
// a data original e receberam ids novos, então data_cadastro não reflete a ordem
// real de cadastro. O id auto_increment reflete.
$sort   = isset($_GET['sort']) ? $_GET['sort'] : 'id';
$dir    = isset($_GET['dir']) && in_array(strtolower($_GET['dir']), ['asc', 'desc'], true)
    ? strtolower($_GET['dir'])
    : (isset($_GET['sort']) ? 'asc' : 'desc');
$quick  = isset($_GET['quick']) ? $_GET['quick'] : 'todos'; // todos | ativos | inativos | novos

$allowed_sort_columns = [
    'id' => 'c.id',
    'nome' => 'c.nome',
    'empresa' => 'c.empresa',
    'email' => 'c.email',
    'telefone' => 'c.telefone',
    'documento_principal' => 'c.documento_principal',
    'data_cadastro' => 'c.data_cadastro',
    'volume_12m' => 'volume_12m',
    'ultima_op' => 'ultima_op',
    'total_ops' => 'total_ops'
];
if (!array_key_exists($sort, $allowed_sort_columns)) $sort = 'id';
$sort_column_sql = $allowed_sort_columns[$sort];

$offset = max(0, ($page - 1) * $results_per_page);

// --- Construção de filtros ---
$params = [];
$whereClauses = [];

if ($search !== '') {
    $whereClauses[] = "(c.nome LIKE :s OR c.empresa LIKE :s OR c.email LIKE :s OR c.telefone LIKE :s OR c.documento_principal LIKE :s)";
    $params[':s'] = "%$search%";
}

// Quick filters dependem das ops -> aplicados via HAVING após JOIN
$havingClauses = [];
if ($quick === 'ativos') {
    $havingClauses[] = "ultima_op IS NOT NULL AND ultima_op >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
} elseif ($quick === 'inativos') {
    $havingClauses[] = "(ultima_op IS NULL OR ultima_op < DATE_SUB(NOW(), INTERVAL 6 MONTH))";
} elseif ($quick === 'novos') {
    $whereClauses[] = "c.data_cadastro >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : '';
$havingSql = !empty($havingClauses) ? "HAVING " . implode(' AND ', $havingClauses) : '';

// --- KPIs gerais (independem da paginação) ---
$kpis = ['total' => 0, 'ativos' => 0, 'inativos' => 0, 'novos_mes' => 0, 'volume_12m' => 0];
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM clientes");
    $kpis['total'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT c.id)
        FROM clientes c
        INNER JOIN operacoes o ON o.cedente_id = c.id
        WHERE o.data_operacao >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $kpis['ativos'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("
        SELECT COUNT(*) FROM clientes c
        WHERE NOT EXISTS (
            SELECT 1 FROM operacoes o
            WHERE o.cedente_id = c.id AND o.data_operacao >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        )
    ");
    $kpis['inativos'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM clientes WHERE data_cadastro >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $kpis['novos_mes'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COALESCE(SUM(total_original_calc),0) FROM operacoes WHERE data_operacao >= DATE_SUB(NOW(), INTERVAL 12 MONTH)");
    $kpis['volume_12m'] = (float) $stmt->fetchColumn();
} catch (PDOException $e) {
    // KPIs ficam zerados se algo falhar
}

// --- Contagem com filtros aplicados ---
$total_results = 0;
try {
    if ($havingSql !== '') {
        // precisa do agregado pra usar HAVING
        $countSql = "SELECT COUNT(*) FROM (
            SELECT c.id,
                   (SELECT MAX(o.data_operacao) FROM operacoes o WHERE o.cedente_id = c.id) AS ultima_op
            FROM clientes c
            $whereSql
            $havingSql
        ) AS sub";
    } else {
        $countSql = "SELECT COUNT(c.id) FROM clientes c $whereSql";
    }
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total_results = (int) $stmtCount->fetchColumn();
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Erro [Count]: " . htmlspecialchars($e->getMessage()) . "</div>";
}

$total_pages = max(1, (int) ceil($total_results / $results_per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = max(0, ($page - 1) * $results_per_page); }
elseif ($page < 1) { $page = 1; $offset = 0; }

// --- Query principal ---
$clientes = [];
try {
    $sql = "
        SELECT
            c.id,
            c.nome,
            c.empresa,
            c.email,
            c.telefone,
            c.whatsapp,
            c.documento_principal,
            c.porte,
            c.data_cadastro,
            (SELECT COUNT(*) FROM operacoes o WHERE o.cedente_id = c.id) AS total_ops,
            (SELECT COALESCE(SUM(o.total_original_calc), 0) FROM operacoes o WHERE o.cedente_id = c.id AND o.data_operacao >= DATE_SUB(NOW(), INTERVAL 12 MONTH)) AS volume_12m,
            (SELECT MAX(o.data_operacao) FROM operacoes o WHERE o.cedente_id = c.id) AS ultima_op
        FROM clientes c
        $whereSql
        $havingSql
        ORDER BY $sort_column_sql $dir, c.id $dir
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $results_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Erro [Data]: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// --- Helpers ---
function formatDocumento($documento) {
    $doc = preg_replace('/\D/', '', (string)$documento);
    if ($doc === '') return '-';
    if (strlen($doc) === 14) {
        return substr($doc, 0, 2) . '.' . substr($doc, 2, 3) . '.' . substr($doc, 5, 3) . '/' . substr($doc, 8, 4) . '-' . substr($doc, 12, 2);
    }
    if (strlen($doc) === 11) {
        return substr($doc, 0, 3) . '.' . substr($doc, 3, 3) . '.' . substr($doc, 6, 3) . '-' . substr($doc, 9, 2);
    }
    return $documento;
}
function formatTelefone($t) {
    $t = preg_replace('/\D/', '', (string)$t);
    if (strlen($t) === 11) return '(' . substr($t,0,2) . ') ' . substr($t,2,5) . '-' . substr($t,7,4);
    if (strlen($t) === 10) return '(' . substr($t,0,2) . ') ' . substr($t,2,4) . '-' . substr($t,6,4);
    return $t;
}
function moedaCompact($v) {
    $v = (float)$v;
    if ($v >= 1000000) return 'R$ ' . number_format($v / 1000000, 2, ',', '.') . ' mi';
    if ($v >= 1000)    return 'R$ ' . number_format($v / 1000, 1, ',', '.') . ' mil';
    return 'R$ ' . number_format($v, 2, ',', '.');
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
function ultimaOpLabel($data) {
    if (!$data) return '<span class="text-muted">—</span>';
    try {
        $dt = new DateTime($data);
        $hoje = new DateTime();
        $dias = (int) $hoje->diff($dt)->days;
        if ($dias === 0) return 'hoje';
        if ($dias === 1) return 'ontem';
        if ($dias < 30)  return "há $dias dias";
        if ($dias < 365) return 'há ' . round($dias/30) . ' meses';
        return 'há ' . round($dias/365, 1) . ' anos';
    } catch (Exception $e) { return '—'; }
}
function getSortLink($column, $text, $currentSort, $currentDir, $params) {
    $newDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($currentSort === $column) {
        $arrow = $currentDir === 'asc' ? ' <i class="bi bi-arrow-up"></i>' : ' <i class="bi bi-arrow-down"></i>';
    }
    $params['sort'] = $column;
    $params['dir'] = $newDir;
    unset($params['page']);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return '<a href="?' . http_build_query($params) . '">' . htmlspecialchars($text) . $arrow . '</a>';
}

$baseParams = ['search' => $search, 'sort' => $sort, 'dir' => $dir, 'quick' => $quick];
?>
<?php
$pageTitle = 'Clientes';
require_once 'head.php';
?>
    <style>
        /* Estilos específicos da página (tokens e componentes base vêm de theme.css) */
        body { font-size: 0.95rem; }

        /* Variantes de cor dos filtros rápidos (esta página usa .active) */
        .filter-chip.active {
            background: var(--info-soft); color: var(--info); border-color: #c8dafc;
        }
        .filter-chip.active.f-green { background: var(--profit-soft); color: var(--profit); border-color: #b3e3c4; }
        .filter-chip.active.f-warn  { background: var(--warn-soft); color: var(--warn); border-color: #f1d999; }

        /* Coluna de contatos empilhados */
        .contact-stack { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .mini-contact { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
        .mini-contact i { flex-shrink: 0; }
        .mini-contact a { overflow: hidden; text-overflow: ellipsis; }

        /* Ajustes finos específicos da tabela de clientes */
        .data-table tbody td.nowrap { white-space: nowrap; }
        .data-table .num { white-space: nowrap; }
        .client-cell { min-width: 0; }
        .client-cell .text { min-width: 0; }
        .client-cell .doc { white-space: nowrap; }
    </style>

    <div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">

        <!-- Toolbar -->
        <div class="page-toolbar">
            <div>
                <h1>
                    <i class="bi bi-people-fill text-info"></i>
                    Clientes
                    <span class="id-pill"><i class="bi bi-hash"></i><?php echo $kpis['total']; ?> cadastrados</span>
                </h1>
                <div class="text-muted small mt-1">Gerencie os cedentes da operação · busca e ordenação rápidas</div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <form method="GET" action="listar_clientes.php" class="d-flex gap-2">
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                    <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
                    <input type="hidden" name="quick" value="<?php echo htmlspecialchars($quick); ?>">
                    <div class="input-group input-group-sm" style="width: 280px;">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" name="search"
                               placeholder="Buscar por nome, CNPJ, e-mail…"
                               value="<?php echo htmlspecialchars($search); ?>">
                        <?php if ($search !== ''): ?>
                            <a href="?<?php echo http_build_query(array_filter(['sort'=>$sort,'dir'=>$dir,'quick'=>$quick])); ?>"
                               class="btn btn-outline-secondary" title="Limpar busca">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
                <a href="form_cliente.php" class="btn btn-success">
                    <i class="bi bi-plus-lg"></i> Novo Cliente
                </a>
            </div>
        </div>

        <!-- KPIs -->
        <div class="kpi-strip">
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-people-fill"></i></div>
                <div class="k-label">Total de clientes</div>
                <div class="k-value"><?php echo $kpis['total']; ?></div>
                <?php if ($kpis['novos_mes'] > 0): ?>
                    <div class="k-trend" style="color:var(--profit);"><i class="bi bi-arrow-up"></i> +<?php echo $kpis['novos_mes']; ?> no mês</div>
                <?php else: ?>
                    <div class="k-trend">cadastrados no sistema</div>
                <?php endif; ?>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-green"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="k-label">Ativos (90 dias)</div>
                <div class="k-value"><?php echo $kpis['ativos']; ?></div>
                <div class="k-trend">com operação recente</div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-warn"><i class="bi bi-pause-circle"></i></div>
                <div class="k-label">Inativos</div>
                <div class="k-value"><?php echo $kpis['inativos']; ?></div>
                <div class="k-trend">sem operação há 6+ meses</div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-cash-stack"></i></div>
                <div class="k-label">Volume 12 meses</div>
                <div class="k-value"><?php echo moedaCompact($kpis['volume_12m']); ?></div>
                <div class="k-trend">total nominal</div>
            </div>
        </div>

        <!-- Filter chips -->
        <?php
        $quickLink = function($k) use ($search, $sort, $dir) {
            return '?' . http_build_query(array_filter([
                'quick' => $k === 'todos' ? null : $k,
                'search' => $search,
                'sort' => $sort,
                'dir' => $dir,
            ]));
        };
        ?>
        <div class="filter-bar">
            <span class="filter-label">Filtros rápidos:</span>
            <a href="<?php echo $quickLink('todos'); ?>" class="filter-chip <?php echo $quick === 'todos' ? 'active' : ''; ?>">
                Todos <span class="text-muted ms-1">(<?php echo $kpis['total']; ?>)</span>
            </a>
            <a href="<?php echo $quickLink('ativos'); ?>" class="filter-chip <?php echo $quick === 'ativos' ? 'active f-green' : ''; ?>">
                Ativos <span class="ms-1 opacity-75">(<?php echo $kpis['ativos']; ?>)</span>
            </a>
            <a href="<?php echo $quickLink('inativos'); ?>" class="filter-chip <?php echo $quick === 'inativos' ? 'active f-warn' : ''; ?>">
                Inativos <span class="ms-1 opacity-75">(<?php echo $kpis['inativos']; ?>)</span>
            </a>
            <a href="<?php echo $quickLink('novos'); ?>" class="filter-chip <?php echo $quick === 'novos' ? 'active' : ''; ?>">
                Novos no mês <span class="ms-1 opacity-75">(<?php echo $kpis['novos_mes']; ?>)</span>
            </a>
            <span class="ms-auto small text-muted">
                Ordenar por: <strong><?php echo htmlspecialchars(ucfirst($sort)); ?> <?php echo $dir === 'asc' ? '↑' : '↓'; ?></strong>
            </span>
        </div>

        <!-- Tabela -->
        <?php if (empty($clientes)): ?>
            <?php
            $isFiltered = $search !== '' || $quick !== 'todos';
            if ($search !== '') {
                $emptyTitulo = 'Nenhum cliente encontrado';
                $emptyMsg = 'Não encontramos clientes para a busca "' . $search . '".';
            } elseif ($quick !== 'todos') {
                $emptyTitulo = 'Nenhum cliente encontrado';
                $emptyMsg = 'Tente outro filtro rápido ou limpe os filtros.';
            } else {
                $emptyTitulo = 'Nenhum cliente cadastrado';
                $emptyMsg = 'Comece adicionando seu primeiro cliente.';
            }
            $emptyCta = $isFiltered
                ? ['label' => 'Limpar filtros', 'href' => 'listar_clientes.php', 'icon' => 'bi-x-circle']
                : ['label' => 'Novo Cliente', 'href' => 'form_cliente.php', 'icon' => 'bi-plus-lg'];
            echo ui_empty_state('bi-people', $emptyTitulo, $emptyMsg, $emptyCta);
            ?>
        <?php else: ?>
            <div class="data-table-wrap">
                <div class="data-table-head">
                    <h3><?php echo $total_results; ?> cliente<?php echo $total_results === 1 ? '' : 's'; ?></h3>
                    <div class="meta">Página <?php echo $page; ?> de <?php echo $total_pages; ?></div>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:60px;"><?php echo getSortLink('id', 'ID', $sort, $dir, $baseParams); ?></th>
                                <th><?php echo getSortLink('empresa', 'Cliente', $sort, $dir, $baseParams); ?></th>
                                <th>Contato</th>
                                <th class="text-center"><?php echo getSortLink('total_ops', 'Operações', $sort, $dir, $baseParams); ?></th>
                                <th class="text-end"><?php echo getSortLink('volume_12m', 'Volume 12m', $sort, $dir, $baseParams); ?></th>
                                <th class="text-center"><?php echo getSortLink('ultima_op', 'Última op.', $sort, $dir, $baseParams); ?></th>
                                <th class="text-center"><?php echo getSortLink('data_cadastro', 'Cadastro', $sort, $dir, $baseParams); ?></th>
                                <th class="text-center" style="width:130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientes as $cliente): ?>
                                <tr>
                                    <td class="text-muted">#<?php echo (int)$cliente['id']; ?></td>
                                    <td>
                                        <div class="client-cell">
                                            <div class="avatar <?php echo avatarColor($cliente['id']); ?>"><?php echo iniciais($cliente['empresa'] ?? $cliente['nome']); ?></div>
                                            <div class="text">
                                                <div class="name"><?php echo htmlspecialchars($cliente['empresa'] ?? $cliente['nome'] ?? '-'); ?></div>
                                                <div class="doc">
                                                    <?php echo formatDocumento($cliente['documento_principal'] ?? ''); ?>
                                                    <?php if (!empty($cliente['porte'])): ?>
                                                        · <?php echo htmlspecialchars($cliente['porte']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="contact-stack">
                                            <?php if (!empty($cliente['whatsapp'])): ?>
                                                <span class="mini-contact">
                                                    <a href="https://wa.me/55<?php echo preg_replace('/\D/','',$cliente['whatsapp']); ?>" target="_blank" rel="noopener noreferrer">
                                                        <i class="bi bi-whatsapp text-success"></i> <?php echo formatTelefone($cliente['whatsapp']); ?>
                                                    </a>
                                                </span>
                                            <?php elseif (!empty($cliente['telefone'])): ?>
                                                <span class="mini-contact">
                                                    <a href="tel:<?php echo preg_replace('/\D/','',$cliente['telefone']); ?>">
                                                        <i class="bi bi-telephone-fill"></i> <?php echo formatTelefone($cliente['telefone']); ?>
                                                    </a>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($cliente['email'])): ?>
                                                <span class="mini-contact">
                                                    <a href="mailto:<?php echo htmlspecialchars($cliente['email']); ?>" title="<?php echo htmlspecialchars($cliente['email']); ?>">
                                                        <i class="bi bi-envelope-fill"></i> <?php echo htmlspecialchars($cliente['email']); ?>
                                                    </a>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (empty($cliente['whatsapp']) && empty($cliente['telefone']) && empty($cliente['email'])): ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center nowrap">
                                        <?php if ((int)$cliente['total_ops'] > 0): ?>
                                            <span class="badge bg-light text-dark border"><?php echo (int)$cliente['total_ops']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end num">
                                        <?php echo (float)$cliente['volume_12m'] > 0 ? moedaCompact($cliente['volume_12m']) : '<span class="text-muted">—</span>'; ?>
                                    </td>
                                    <td class="text-center text-muted small nowrap">
                                        <?php echo ultimaOpLabel($cliente['ultima_op']); ?>
                                    </td>
                                    <td class="text-center text-muted small nowrap">
                                        <?php echo !empty($cliente['data_cadastro'])
                                            ? htmlspecialchars(date('d/m/Y', strtotime($cliente['data_cadastro'])))
                                            : '<span class="text-muted">—</span>'; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="row-actions">
                                            <a href="visualizar_cliente.php?id=<?php echo $cliente['id']; ?>" class="btn-ico" title="Visualizar"><i class="bi bi-eye-fill"></i></a>
                                            <a href="form_cliente.php?id=<?php echo $cliente['id']; ?>" class="btn-ico" title="Editar"><i class="bi bi-pencil-square"></i></a>
                                            <a href="simulacao.php?cliente_id=<?php echo $cliente['id']; ?>" class="btn-ico" title="Nova simulação"><i class="bi bi-calculator-fill"></i></a>
                                            <a href="excluir_cliente.php?id=<?php echo $cliente['id']; ?>" class="btn-ico danger" title="Excluir" data-confirm="Tem certeza que deseja excluir este cliente? Esta ação não pode ser desfeita."><i class="bi bi-trash3-fill"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                $first = $offset + 1;
                $last  = min($offset + count($clientes), $total_results);
                $pgParams = array_filter(['search'=>$search,'sort'=>$sort,'dir'=>$dir,'quick'=>$quick === 'todos' ? null : $quick]);
                $pgUrl = function($p) use ($pgParams) {
                    $params = $pgParams; $params['page'] = $p;
                    return '?' . http_build_query($params);
                };
                ?>
                <div class="pagination-bar">
                    <div class="info">
                        Mostrando <strong><?php echo $first; ?>–<?php echo $last; ?></strong> de <strong><?php echo $total_results; ?></strong> clientes
                    </div>
                    <?php if ($total_pages > 1): ?>
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $pgUrl($page - 1); ?>">«</a>
                        </li>
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        if ($page <= 3) $end_page = min($total_pages, 5);
                        if ($page >= $total_pages - 2) $start_page = max(1, $total_pages - 4);
                        if ($start_page > 1) {
                            echo '<li class="page-item"><a class="page-link" href="' . $pgUrl(1) . '">1</a></li>';
                            if ($start_page > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        }
                        for ($i = $start_page; $i <= $end_page; $i++) { ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo $pgUrl($i); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php }
                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                            echo '<li class="page-item"><a class="page-link" href="' . $pgUrl($total_pages) . '">' . $total_pages . '</a></li>';
                        }
                        ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $pgUrl($page + 1); ?>">»</a>
                        </li>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>
