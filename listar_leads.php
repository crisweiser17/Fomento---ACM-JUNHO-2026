<?php require_once 'auth_check.php'; ?>
<?php
require_once 'db_connection.php';
require_once 'ui_helpers.php';

$results_per_page = 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'data_atualizacao';
$dir = isset($_GET['dir']) && in_array(strtolower($_GET['dir']), ['asc', 'desc'], true) ? strtolower($_GET['dir']) : 'desc';
$quick = isset($_GET['quick']) ? $_GET['quick'] : 'ativos';

// Drill-down vindo do relatorio_visitas.php
$drillResponsavel = isset($_GET['responsavel_id']) && $_GET['responsavel_id'] !== '' ? $_GET['responsavel_id'] : null;
$drillEvento      = isset($_GET['evento']) && $_GET['evento'] !== '' ? $_GET['evento'] : null;
$drillIni         = isset($_GET['data_evento_inicio']) && $_GET['data_evento_inicio'] !== '' ? $_GET['data_evento_inicio'] : null;
$drillFim         = isset($_GET['data_evento_fim'])    && $_GET['data_evento_fim']    !== '' ? $_GET['data_evento_fim']    : null;
$drillAtivo       = ($drillResponsavel !== null) || ($drillEvento !== null);
$eventosValidos   = ['visita_agendada', 'visita_feita', 'aprovado', 'convertido', 'perdido'];
if ($drillEvento !== null && !in_array($drillEvento, $eventosValidos, true)) $drillEvento = null;

// Em modo drill-down, ignora filtro rápido por estágio (mostra leads em qualquer estado)
if ($drillAtivo && !isset($_GET['quick'])) {
    $quick = 'todos_inclusive';
}

$allowed_sort = [
    'id' => 'l.id',
    'empresa' => 'l.empresa',
    'estagio' => 'l.estagio',
    'origem' => 'l.origem',
    'responsavel' => 'u.email',
    'data_cadastro' => 'l.data_cadastro',
    'data_atualizacao' => 'l.data_atualizacao',
];
if (!array_key_exists($sort, $allowed_sort)) $sort = 'data_atualizacao';
$sort_sql = $allowed_sort[$sort];

$offset = ($page - 1) * $results_per_page;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(l.empresa LIKE :s OR l.nome_contato LIKE :s OR l.telefone LIKE :s)';
    $params[':s'] = "%{$search}%";
}

// Drill-down: filtro por responsável (ID numérico ou 'sem' para sem responsável)
if ($drillResponsavel !== null) {
    if ($drillResponsavel === 'sem') {
        $where[] = 'l.responsavel_id IS NULL';
    } else {
        $where[] = 'l.responsavel_id = :drill_resp';
        $params[':drill_resp'] = (int)$drillResponsavel;
    }
}

// Drill-down: leads que tiveram um evento específico no período
if ($drillEvento !== null) {
    $cond = 'EXISTS (SELECT 1 FROM leads_historico h WHERE h.lead_id = l.id AND h.estagio_para = :drill_ev';
    $params[':drill_ev'] = $drillEvento;
    if ($drillIni && DateTime::createFromFormat('Y-m-d', $drillIni)) {
        $cond .= ' AND h.data_evento >= :drill_ini';
        $params[':drill_ini'] = $drillIni . ' 00:00:00';
    }
    if ($drillFim && DateTime::createFromFormat('Y-m-d', $drillFim)) {
        $cond .= ' AND h.data_evento <= :drill_fim';
        $params[':drill_fim'] = $drillFim . ' 23:59:59';
    }
    $cond .= ')';
    $where[] = $cond;
}

$ativos = ['novo', 'visita_agendada', 'visita_feita', 'aprovado'];

if ($quick === 'ativos' || $quick === 'todos') {
    $where[] = "l.estagio IN ('novo','visita_agendada','visita_feita','aprovado')";
} elseif (in_array($quick, $ativos, true)) {
    $where[] = 'l.estagio = :q_estagio';
    $params[':q_estagio'] = $quick;
} elseif ($quick === 'perdidos') {
    $where[] = "l.estagio = 'perdido'";
} elseif ($quick === 'convertidos') {
    $where[] = "l.estagio = 'convertido'";
} elseif ($quick === 'todos_inclusive') {
    // sem filtro
} else {
    $quick = 'ativos';
    $where[] = "l.estagio IN ('novo','visita_agendada','visita_feita','aprovado')";
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$total_results = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads l LEFT JOIN usuarios u ON l.responsavel_id = u.id {$whereSql}");
    $stmt->execute($params);
    $total_results = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Erro [Count]: " . htmlspecialchars($e->getMessage()) . "</div>";
}

$total_pages = max(1, (int)ceil($total_results / $results_per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $results_per_page;
}

$leads = [];
try {
    $sql = "SELECT l.*, u.email AS responsavel_email
            FROM leads l
            LEFT JOIN usuarios u ON l.responsavel_id = u.id
            {$whereSql}
            ORDER BY {$sort_sql} {$dir}
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $results_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Erro [Data]: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Contadores para os chips
$counts = ['ativos' => 0, 'novo' => 0, 'visita_agendada' => 0, 'visita_feita' => 0, 'aprovado' => 0, 'perdidos' => 0, 'convertidos' => 0, 'total' => 0];
try {
    $rows = $pdo->query("SELECT estagio, COUNT(*) AS qtd FROM leads GROUP BY estagio")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $counts['total'] += (int)$r['qtd'];
        switch ($r['estagio']) {
            case 'novo':
            case 'visita_agendada':
            case 'visita_feita':
            case 'aprovado':
                $counts[$r['estagio']] = (int)$r['qtd'];
                $counts['ativos'] += (int)$r['qtd'];
                break;
            case 'perdido':     $counts['perdidos']    = (int)$r['qtd']; break;
            case 'convertido':  $counts['convertidos'] = (int)$r['qtd']; break;
        }
    }
} catch (PDOException $e) { /* ignore */ }

function estagioLabel($e) {
    $map = [
        'novo' => 'Novo lead',
        'visita_agendada' => 'Visita agendada',
        'visita_feita' => 'Visita feita',
        'aprovado' => 'Aprovado',
        'perdido' => 'Perdido',
        'convertido' => 'Convertido',
    ];
    return $map[$e] ?? $e;
}
function estagioBadge($e) {
    $cls = ['novo' => 's-novo', 'visita_agendada' => 's-agendada', 'visita_feita' => 's-feita',
            'aprovado' => 's-aprovado', 'perdido' => 's-perdido', 'convertido' => 's-convertido'];
    return '<span class="status-pill ' . ($cls[$e] ?? '') . '">' . htmlspecialchars(estagioLabel($e)) . '</span>';
}
function origemBadge($o) {
    $label = $o === 'ativo' ? 'Ativo' : 'Receptivo';
    $cls = $o === 'ativo' ? 'o-ativo' : 'o-receptivo';
    return '<span class="origem-pill ' . $cls . '">' . $label . '</span>';
}
function formatTelefone($t) {
    $t = preg_replace('/\D/', '', (string)$t);
    if (strlen($t) === 11) return '(' . substr($t,0,2) . ') ' . substr($t,2,5) . '-' . substr($t,7,4);
    if (strlen($t) === 10) return '(' . substr($t,0,2) . ') ' . substr($t,2,4) . '-' . substr($t,6,4);
    return $t;
}
function iniciaisEmpresa($nome) {
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
function dataRelativa($v) {
    if (!$v) return '—';
    try {
        $dt = new DateTime($v);
        $hoje = new DateTime();
        $dias = (int)$hoje->diff($dt)->days;
        if ($dias === 0) return 'hoje';
        if ($dias === 1) return 'ontem';
        if ($dias < 30) return "há {$dias} dias";
        if ($dias < 365) return 'há ' . round($dias / 30) . ' meses';
        return 'há ' . round($dias / 365, 1) . ' anos';
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
$pageTitle = 'Leads';
require_once 'head.php';
?>
    <style>
        /* Estilos específicos da lista de leads (tokens e componentes vêm de theme.css) */
        body { font-size: 0.95rem; }

        .view-toggle { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
        .view-toggle a { padding: 6px 12px; font-size: 0.85rem; color: var(--neutral); text-decoration: none; background: var(--surface); border-right: 1px solid var(--border); }
        .view-toggle a:last-child { border-right: none; }
        .view-toggle a.active { background: var(--info); color: #fff; }

        .origem-pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 6px; font-size: 0.7rem; font-weight: 600; }
        .origem-pill.o-receptivo { background: var(--info-soft); color: var(--info); }
        .origem-pill.o-ativo     { background: #efe8fa; color: #6f42c1; }

        .motivo-perda { font-size: 0.78rem; color: var(--danger); margin-top: 4px; }
    </style>

    <div class="container-fluid px-3 px-md-4 mt-4" style="max-width: 1500px;">

        <div class="page-toolbar">
            <div>
                <h1>
                    <i class="bi bi-funnel-fill text-info"></i>
                    Leads
                    <span class="id-pill"><i class="bi bi-hash"></i><?php echo $counts['total']; ?> totais</span>
                </h1>
                <div class="text-muted small mt-1">Esteira de vendas · receptivo e ativo</div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="view-toggle">
                    <a href="listar_leads.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" class="active"><i class="bi bi-list-ul"></i> Lista</a>
                    <a href="kanban_leads.php"><i class="bi bi-kanban"></i> Kanban</a>
                </div>
                <form method="GET" action="listar_leads.php" class="d-flex gap-2">
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                    <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
                    <input type="hidden" name="quick" value="<?php echo htmlspecialchars($quick); ?>">
                    <div class="input-group input-group-sm" style="width: 280px;">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" name="search"
                               placeholder="Buscar por empresa, contato, telefone…"
                               value="<?php echo htmlspecialchars($search); ?>">
                        <?php if ($search !== ''): ?>
                            <a href="?<?php echo http_build_query(array_filter(['sort'=>$sort,'dir'=>$dir,'quick'=>$quick])); ?>" class="btn btn-outline-secondary" title="Limpar busca">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
                <a href="form_lead.php" class="btn btn-success btn-sm">
                    <i class="bi bi-plus-lg"></i> Novo Lead
                </a>
            </div>
        </div>

        <?php if ($drillAtivo):
            $eventosLabel = [
                'visita_agendada' => 'Visitas agendadas',
                'visita_feita'    => 'Visitas realizadas',
                'aprovado'        => 'Aprovados',
                'convertido'      => 'Convertidos',
                'perdido'         => 'Perdidos',
            ];
            $respLabel = '';
            if ($drillResponsavel === 'sem') {
                $respLabel = 'Sem responsável';
            } elseif ($drillResponsavel !== null) {
                try {
                    $stmtResp = $pdo->prepare('SELECT email FROM usuarios WHERE id = :id');
                    $stmtResp->execute([':id' => (int)$drillResponsavel]);
                    $respLabel = $stmtResp->fetchColumn() ?: '#' . (int)$drillResponsavel;
                } catch (PDOException $e) { $respLabel = '#' . (int)$drillResponsavel; }
            }
        ?>
            <div class="alert alert-info d-flex justify-content-between align-items-center" style="border-radius:12px; border:1px solid #c8dafc;">
                <div>
                    <i class="bi bi-funnel-fill"></i>
                    <strong>Drill-down do relatório:</strong>
                    <?php if ($drillEvento): ?>
                        <span class="ms-2"><?php echo htmlspecialchars($eventosLabel[$drillEvento] ?? $drillEvento); ?></span>
                    <?php endif; ?>
                    <?php if ($respLabel): ?>
                        <span class="ms-2">· responsável: <strong><?php echo htmlspecialchars($respLabel); ?></strong></span>
                    <?php endif; ?>
                    <?php if ($drillIni && $drillFim): ?>
                        <span class="ms-2">· período: <strong><?php echo date('d/m/Y', strtotime($drillIni)); ?> → <?php echo date('d/m/Y', strtotime($drillFim)); ?></strong></span>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <a href="relatorio_visitas.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar ao relatório</a>
                    <a href="listar_leads.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Limpar filtro</a>
                </div>
            </div>
        <?php endif; ?>

        <?php
        $quickLink = function($k) use ($search, $sort, $dir) {
            return '?' . http_build_query(array_filter([
                'quick' => $k,
                'search' => $search,
                'sort' => $sort,
                'dir' => $dir,
            ]));
        };
        ?>
        <div class="filter-bar">
            <span class="filter-label">Filtros rápidos:</span>
            <a href="<?php echo $quickLink('ativos'); ?>" class="filter-chip <?php echo $quick === 'ativos' ? 'active' : ''; ?>">
                Em andamento <span class="ms-1 opacity-75">(<?php echo $counts['ativos']; ?>)</span>
            </a>
            <a href="<?php echo $quickLink('novo'); ?>" class="filter-chip <?php echo $quick === 'novo' ? 'active' : ''; ?>">
                Novos <span class="ms-1 opacity-75">(<?php echo $counts['novo']; ?>)</span>
            </a>
            <a href="<?php echo $quickLink('visita_agendada'); ?>" class="filter-chip <?php echo $quick === 'visita_agendada' ? 'active f-warn' : ''; ?>">
                Visita agendada <span class="ms-1 opacity-75">(<?php echo $counts['visita_agendada']; ?>)</span>
            </a>
            <a href="<?php echo $quickLink('visita_feita'); ?>" class="filter-chip <?php echo $quick === 'visita_feita' ? 'active' : ''; ?>">
                Visita feita <span class="ms-1 opacity-75">(<?php echo $counts['visita_feita']; ?>)</span>
            </a>
            <a href="<?php echo $quickLink('aprovado'); ?>" class="filter-chip <?php echo $quick === 'aprovado' ? 'active f-green' : ''; ?>">
                Aprovados <span class="ms-1 opacity-75">(<?php echo $counts['aprovado']; ?>)</span>
            </a>
            <a href="<?php echo $quickLink('perdidos'); ?>" class="filter-chip <?php echo $quick === 'perdidos' ? 'active f-danger' : ''; ?>">
                Perdidos <span class="ms-1 opacity-75">(<?php echo $counts['perdidos']; ?>)</span>
            </a>
            <a href="<?php echo $quickLink('convertidos'); ?>" class="filter-chip <?php echo $quick === 'convertidos' ? 'active f-green' : ''; ?>">
                Convertidos <span class="ms-1 opacity-75">(<?php echo $counts['convertidos']; ?>)</span>
            </a>
            <span class="ms-auto small text-muted">
                Ordenado por: <strong><?php echo htmlspecialchars($sort); ?> <?php echo $dir === 'asc' ? '↑' : '↓'; ?></strong>
            </span>
        </div>

        <?php if (empty($leads)): ?>
            <?php
            $emptyMsg = $search !== ''
                ? 'Não encontramos leads para "' . $search . '".'
                : 'Cadastre seu primeiro lead ou ajuste os filtros.';
            echo ui_empty_state('bi-funnel', 'Nenhum lead encontrado', $emptyMsg, [
                'label' => 'Novo Lead',
                'href'  => 'form_lead.php',
                'icon'  => 'bi-plus-lg',
            ]);
            ?>
        <?php else: ?>
            <div class="data-table-wrap">
                <div class="data-table-head">
                    <h3><?php echo $total_results; ?> lead<?php echo $total_results === 1 ? '' : 's'; ?></h3>
                    <div class="meta">Página <?php echo $page; ?> de <?php echo $total_pages; ?></div>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:60px;"><?php echo getSortLink('id', 'ID', $sort, $dir, $baseParams); ?></th>
                                <th><?php echo getSortLink('empresa', 'Empresa / contato', $sort, $dir, $baseParams); ?></th>
                                <th>Telefone</th>
                                <th><?php echo getSortLink('origem', 'Origem', $sort, $dir, $baseParams); ?></th>
                                <th><?php echo getSortLink('estagio', 'Estágio', $sort, $dir, $baseParams); ?></th>
                                <th><?php echo getSortLink('responsavel', 'Responsável', $sort, $dir, $baseParams); ?></th>
                                <th><?php echo getSortLink('data_atualizacao', 'Atualizado', $sort, $dir, $baseParams); ?></th>
                                <th class="text-center" style="width:160px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leads as $lead): ?>
                                <tr>
                                    <td class="text-muted">#<?php echo (int)$lead['id']; ?></td>
                                    <td>
                                        <div class="client-cell">
                                            <div class="avatar <?php echo avatarColor($lead['id']); ?>"><?php echo iniciaisEmpresa($lead['empresa']); ?></div>
                                            <div class="text">
                                                <div class="name"><?php echo htmlspecialchars($lead['empresa']); ?></div>
                                                <div class="doc"><i class="bi bi-person"></i> <?php echo htmlspecialchars($lead['nome_contato']); ?></div>
                                                <?php if ($lead['estagio'] === 'perdido' && !empty($lead['motivo_perda'])): ?>
                                                    <div class="motivo-perda"><i class="bi bi-x-circle"></i> <?php echo htmlspecialchars($lead['motivo_perda']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($lead['telefone'])): ?>
                                            <a href="https://wa.me/55<?php echo preg_replace('/\D/', '', $lead['telefone']); ?>" target="_blank" rel="noopener" class="text-decoration-none small">
                                                <i class="bi bi-whatsapp text-success"></i> <?php echo htmlspecialchars(formatTelefone($lead['telefone'])); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo origemBadge($lead['origem']); ?></td>
                                    <td><?php echo estagioBadge($lead['estagio']); ?></td>
                                    <td class="small text-muted">
                                        <?php echo $lead['responsavel_email'] ? htmlspecialchars($lead['responsavel_email']) : '—'; ?>
                                    </td>
                                    <td class="small text-muted nowrap"><?php echo dataRelativa($lead['data_atualizacao']); ?></td>
                                    <td class="text-center">
                                        <div class="row-actions">
                                            <a href="form_lead.php?id=<?php echo (int)$lead['id']; ?>" class="btn-ico" title="Editar"><i class="bi bi-pencil-square"></i></a>
                                            <?php if ($lead['estagio'] === 'aprovado' && empty($lead['cliente_id'])): ?>
                                                <a href="converter_lead.php?id=<?php echo (int)$lead['id']; ?>" class="btn-ico success" title="Converter em cliente"><i class="bi bi-person-check-fill"></i></a>
                                            <?php endif; ?>
                                            <?php if (!in_array($lead['estagio'], ['perdido', 'convertido'], true)): ?>
                                                <button type="button" class="btn-ico danger btn-arquivar" data-id="<?php echo (int)$lead['id']; ?>" data-empresa="<?php echo htmlspecialchars($lead['empresa']); ?>" title="Marcar como perdido">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="excluir_lead.php?id=<?php echo (int)$lead['id']; ?>" class="btn-ico danger" title="Excluir definitivamente" data-confirm="Tem certeza? Esta ação não pode ser desfeita."><i class="bi bi-trash3-fill"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                $first = $offset + 1;
                $last = min($offset + count($leads), $total_results);
                $pgParams = array_filter(['search'=>$search,'sort'=>$sort,'dir'=>$dir,'quick'=>$quick]);
                $pgUrl = function($p) use ($pgParams) {
                    $params = $pgParams; $params['page'] = $p;
                    return '?' . http_build_query($params);
                };
                ?>
                <div class="pagination-bar">
                    <div class="info">
                        Mostrando <strong><?php echo $first; ?>–<?php echo $last; ?></strong> de <strong><?php echo $total_results; ?></strong>
                    </div>
                    <?php if ($total_pages > 1): ?>
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $pgUrl(max(1, $page - 1)); ?>">«</a>
                        </li>
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        if ($page <= 3) $end_page = min($total_pages, 5);
                        if ($page >= $total_pages - 2) $start_page = max(1, $total_pages - 4);
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo $pgUrl($i); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $pgUrl(min($total_pages, $page + 1)); ?>">»</a>
                        </li>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal arquivar (perdido com motivo) -->
    <div class="modal fade" id="modalArquivar" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" action="arquivar_lead.php" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger"></i> Marcar lead como perdido</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Lead: <strong id="arquivarEmpresa"></strong></p>
                    <input type="hidden" name="id" id="arquivarId">
                    <label class="form-label-strong">Motivo da perda *</label>
                    <textarea name="motivo_perda" class="form-control" rows="3" required maxlength="255" placeholder="Ex: optou pelo concorrente, sem necessidade no momento, etc."></textarea>
                    <div class="form-text">Aparece nos filtros e relatórios — escreva pra você poder consultar depois.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle"></i> Marcar como perdido</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.btn-arquivar').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('arquivarId').value = btn.dataset.id;
                    document.getElementById('arquivarEmpresa').textContent = btn.dataset.empresa;
                    new bootstrap.Modal(document.getElementById('modalArquivar')).show();
                });
            });
        });
    </script>
</body>
</html>
