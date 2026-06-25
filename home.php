<?php require_once 'auth_check.php'; ?><?php
require_once 'db_connection.php';

// --- App config (nome/versão) ---
$homeConfigPath = __DIR__ . '/config.json';
$homeAppCfg = file_exists($homeConfigPath) ? (json_decode(file_get_contents($homeConfigPath), true) ?: []) : [];
$homeAppName    = $homeAppCfg['app_name']    ?? 'FACTOR';
$homeAppVersion = $homeAppCfg['app_version'] ?? '';

// --- Saudação pelo horário ---
$hora = (int) date('H');
if ($hora < 12) {
    $saudacao = 'Bom dia';
} elseif ($hora < 18) {
    $saudacao = 'Boa tarde';
} else {
    $saudacao = 'Boa noite';
}
$usuarioNome = $_SESSION['user_email'] ?? null;
if ($usuarioNome) {
    $usuarioNome = ucfirst(explode('@', $usuarioNome)[0]);
}

// --- KPIs do ecossistema (degradam silenciosamente se a query falhar) ---
$kpis = [
    'leads_ativos'      => null,
    'clientes'          => null,
    'ops_abertas'       => null,
    'volume_aberto'     => null,
];

$openStatuses = "('Em Aberto', 'Parcialmente Compensado', 'Problema')";

try {
    $kpis['leads_ativos'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM leads WHERE estagio IN ('novo','visita_agendada','visita_feita','aprovado')"
    )->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

try {
    $kpis['clientes'] = (int) $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

try {
    $kpis['ops_abertas'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM operacoes WHERE status IN $openStatuses"
    )->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

try {
    $kpis['volume_aberto'] = (float) $pdo->query(
        "SELECT COALESCE(SUM(valor), 0) FROM operacoes WHERE status IN $openStatuses"
    )->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

function homeFmtNum(?float $n): string
{
    return $n === null ? '—' : number_format($n, 0, ',', '.');
}
function homeFmtMoeda(?float $n): string
{
    return $n === null ? '—' : 'R$ ' . number_format($n, 0, ',', '.');
}
function homeFmtMoeda2(?float $n): string
{
    return $n === null ? '—' : 'R$ ' . number_format($n, 2, ',', '.');
}

// --- Recebíveis vencendo nos próximos 7 dias (em aberto) ---
$recVencendo      = [];
$recVencendoTotal = 0.0;
try {
    $stmt = $pdo->query(
        "SELECT r.id, r.operacao_id, r.data_vencimento,
                (r.valor_original - COALESCE(r.valor_recebido, 0)) AS valor_aberto,
                sac.empresa AS sacado_nome,
                ced.empresa AS cedente_nome
         FROM recebiveis r
         LEFT JOIN operacoes o   ON r.operacao_id = o.id
         LEFT JOIN clientes ced  ON o.cedente_id = ced.id
         LEFT JOIN clientes sac  ON r.sacado_id = sac.id
         WHERE r.status NOT IN ('Recebido', 'Compensado', 'Totalmente Compensado')
           AND r.data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
         ORDER BY r.data_vencimento ASC, valor_aberto DESC"
    );
    $recVencendo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($recVencendo as $r) {
        $recVencendoTotal += (float) $r['valor_aberto'];
    }
} catch (Throwable $e) { /* degrada silenciosamente */ }

// Rótulo relativo do vencimento (hoje / amanhã / em N dias)
function homeVencimentoLabel(string $dataVenc): array
{
    $hoje = new DateTime('today');
    $venc = new DateTime($dataVenc);
    $dias = (int) $hoje->diff($venc)->format('%r%a');

    if ($dias <= 0) {
        return ['Hoje', 'danger'];
    }
    if ($dias === 1) {
        return ['Amanhã', 'warn'];
    }
    return ["Em {$dias} dias", 'warn'];
}

// --- Ações prioritárias do dia a dia (fluxo de operação: simular → operar → conferir) ---
$acoesPrioritarias = [
    [
        'url'       => 'simulacao.php',
        'icone'     => 'bi-calculator',
        'titulo'    => 'Simular nova operação',
        'descricao' => 'Calcule taxas e monte uma nova antecipação em segundos.',
        'cta'       => 'Começar simulação',
    ],
    [
        'url'       => 'listar_operacoes.php',
        'icone'     => 'bi-list-ul',
        'titulo'    => 'Operações existentes',
        'descricao' => 'Acompanhe, edite e gerencie as operações em andamento.',
        'cta'       => 'Ver operações',
    ],
    [
        'url'       => 'listar_recebiveis.php',
        'icone'     => 'bi-list-check',
        'titulo'    => 'Recebíveis',
        'descricao' => 'Controle os recebíveis, vencimentos e compensações.',
        'cta'       => 'Ver recebíveis',
    ],
];

// --- Acessos rápidos secundários (cadastros, consultas e gestão) ---
$acessosRapidos = [
    ['url' => 'form_lead.php',           'icon' => 'bi-plus-circle',  'label' => 'Novo Lead',       'accent' => 'leads'],
    ['url' => 'form_cliente.php',        'icon' => 'bi-person-plus',  'label' => 'Novo Cliente',    'accent' => 'clientes'],
    ['url' => 'kanban_leads.php',        'icon' => 'bi-kanban',       'label' => 'Esteira de Venda', 'accent' => 'leads'],
    ['url' => 'listar_clientes.php',     'icon' => 'bi-people',       'label' => 'Listar Clientes', 'accent' => 'clientes'],
    ['url' => 'dashboard_financeiro.php', 'icon' => 'bi-graph-up',    'label' => 'Relatórios',      'accent' => 'relatorios'],
    ['url' => 'config.php',              'icon' => 'bi-gear-fill',    'label' => 'Configurações',   'accent' => 'config'],
];

$pageTitle = 'Início — ' . $homeAppName;
require_once 'head.php';
?>
    <style>
        /* Home: visual de ecossistema. Tokens vêm de theme.css. */
        .home-hero {
            background: var(--app-hero-grad);
            color: #fff;
            border-radius: var(--app-radius-lg);
            padding: 30px 32px;
            margin-bottom: var(--app-gap);
            box-shadow: var(--app-shadow);
            position: relative;
            overflow: hidden;
        }
        .home-hero::after {
            content: "";
            position: absolute;
            right: -60px;
            top: -60px;
            width: 260px;
            height: 260px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 50%;
        }
        .home-hero .hero-brand {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
            opacity: 0.85;
        }
        .home-hero h1 {
            font-size: 1.7rem;
            font-weight: 700;
            margin: 10px 0 4px;
        }
        .home-hero p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.95rem;
            max-width: 640px;
        }

        /* Faixa de KPIs do ecossistema */
        .home-kpis {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: var(--app-gap);
        }
        @media (max-width: 992px) { .home-kpis { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 576px) { .home-kpis { grid-template-columns: 1fr; } }

        /* Título de seção do hub */
        .home-section-title {
            display: flex;
            align-items: baseline;
            gap: 10px;
            margin: 4px 0 14px;
        }
        .home-section-title h2 { font-size: 1.05rem; font-weight: 700; margin: 0; }
        .home-section-title .hint { font-size: 0.82rem; color: var(--app-neutral); }

        /* Ações prioritárias — cards grandes, o foco do dia a dia */
        .primary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--app-gap);
            margin-bottom: 26px;
        }
        @media (max-width: 992px) { .primary-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 576px) { .primary-grid { grid-template-columns: 1fr; } }

        .action-card {
            background: var(--app-surface);
            border: 1px solid var(--app-border);
            border-radius: var(--app-radius-lg);
            padding: 22px;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--app-shadow);
            border-color: var(--app-info-border);
            color: inherit;
        }
        .action-card.is-primary {
            background: var(--app-hero-grad);
            border-color: transparent;
            color: #fff;
        }
        .action-card .ac-icon {
            width: 50px;
            height: 50px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.55rem;
            margin-bottom: 14px;
            background: var(--app-info-soft);
            color: var(--app-info);
        }
        .action-card.is-primary .ac-icon { background: rgba(255, 255, 255, 0.16); color: #fff; }
        .action-card .ac-title { font-size: 1.12rem; font-weight: 700; margin: 0 0 4px; }
        .action-card .ac-desc {
            font-size: 0.88rem;
            color: var(--app-neutral);
            margin: 0 0 16px;
            line-height: 1.45;
            flex: 1;
        }
        .action-card.is-primary .ac-desc { color: rgba(255, 255, 255, 0.88); }
        .action-card .ac-cta {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--app-info);
        }
        .action-card.is-primary .ac-cta { color: #fff; }
        .action-card:hover .ac-cta .arrow { transform: translateX(3px); }
        .action-card .ac-cta .arrow { transition: transform 0.15s ease; }

        /* Acessos rápidos — tiles compactos */
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
        }
        @media (max-width: 992px) { .quick-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 576px) { .quick-grid { grid-template-columns: repeat(2, 1fr); } }

        .quick-tile {
            background: var(--app-surface);
            border: 1px solid var(--app-border);
            border-radius: var(--app-radius);
            padding: 16px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 8px;
            text-decoration: none;
            color: var(--app-ink);
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .quick-tile:hover {
            transform: translateY(-2px);
            box-shadow: var(--app-shadow);
            border-color: var(--app-info-border);
            color: var(--app-ink);
        }
        .quick-tile .qt-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            background: var(--app-info-soft);
            color: var(--app-info);
        }
        /* Acentos por domínio — dieta de cor, variações suaves da marca */
        .quick-tile .qt-icon.a-leads      { background: var(--app-info-soft);   color: var(--app-info); }
        .quick-tile .qt-icon.a-clientes   { background: var(--app-profit-soft); color: var(--app-profit); }
        .quick-tile .qt-icon.a-relatorios { background: var(--app-warn-soft);   color: var(--app-warn); }
        .quick-tile .qt-icon.a-config     { background: #eef0f3;                color: var(--app-neutral); }
        .quick-tile .qt-label { font-size: 0.84rem; font-weight: 600; line-height: 1.2; }
    </style>

    <div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">

        <!-- Hero -->
        <section class="home-hero">
            <span class="hero-brand"><i class="bi bi-cash-coin"></i> <?php echo htmlspecialchars($homeAppName); ?></span>
            <h1><?php echo $saudacao; ?><?php echo $usuarioNome ? ', ' . htmlspecialchars($usuarioNome) : ''; ?>!</h1>
            <p>Bem-vindo ao painel central. Escolha um módulo abaixo para gerenciar leads, operações, clientes e relatórios do seu ecossistema de factoring.</p>
        </section>

        <!-- KPIs -->
        <section class="home-kpis">
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-funnel-fill"></i></div>
                <div class="k-label">Leads ativos</div>
                <div class="k-value"><?php echo homeFmtNum($kpis['leads_ativos']); ?></div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-green"><i class="bi bi-people-fill"></i></div>
                <div class="k-label">Clientes</div>
                <div class="k-value"><?php echo homeFmtNum($kpis['clientes']); ?></div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-warn"><i class="bi bi-journal-text"></i></div>
                <div class="k-label">Operações em aberto</div>
                <div class="k-value"><?php echo homeFmtNum($kpis['ops_abertas']); ?></div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-cash-stack"></i></div>
                <div class="k-label">Volume em aberto</div>
                <div class="k-value"><?php echo homeFmtMoeda($kpis['volume_aberto']); ?></div>
            </div>
        </section>

        <!-- Ações prioritárias: o fluxo de operação do dia a dia -->
        <div class="home-section-title">
            <h2>Operar agora</h2>
            <span class="hint">o que você mais usa no dia a dia</span>
        </div>
        <section class="primary-grid">
            <?php foreach ($acoesPrioritarias as $i => $acao): ?>
            <a class="action-card <?php echo $i === 0 ? 'is-primary' : ''; ?>" href="<?php echo htmlspecialchars($acao['url']); ?>">
                <span class="ac-icon"><i class="bi <?php echo $acao['icone']; ?>"></i></span>
                <h3 class="ac-title"><?php echo htmlspecialchars($acao['titulo']); ?></h3>
                <p class="ac-desc"><?php echo htmlspecialchars($acao['descricao']); ?></p>
                <span class="ac-cta"><?php echo htmlspecialchars($acao['cta']); ?> <i class="bi bi-arrow-right arrow"></i></span>
            </a>
            <?php endforeach; ?>
        </section>

        <!-- Recebíveis vencendo nos próximos 7 dias -->
        <div class="home-section-title">
            <h2>Vencendo em 7 dias</h2>
            <span class="hint">recebíveis em aberto que vencem nesta semana</span>
        </div>
        <section class="section-card s-recb mb-4">
            <div class="data-table-head">
                <h3><i class="bi bi-calendar-week me-1"></i> Próximos vencimentos</h3>
                <div class="d-flex align-items-center gap-3">
                    <span class="meta">
                        Total em aberto:
                        <strong class="ms-1"><?php echo homeFmtMoeda2($recVencendoTotal); ?></strong>
                        <span class="text-muted">(<?php echo count($recVencendo); ?>)</span>
                    </span>
                    <a class="btn btn-outline-secondary btn-sm" href="listar_recebiveis.php?quick_filter=vencendo_7_dias">
                        Ver todos
                    </a>
                </div>
            </div>

            <?php if (empty($recVencendo)): ?>
                <div class="empty-state">
                    <i class="bi bi-check2-circle empty-ico"></i>
                    <div class="empty-title">Nenhum recebível vencendo nos próximos 7 dias</div>
                    <div class="empty-msg">Você está em dia com os vencimentos desta semana.</div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Recebível</th>
                                <th>Sacado</th>
                                <th class="col-optional">Cedente</th>
                                <th>Vencimento</th>
                                <th class="text-end">Valor em aberto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recVencendo as $r): ?>
                                <?php [$vLabel, $vTone] = homeVencimentoLabel($r['data_vencimento']); ?>
                                <tr>
                                    <td>
                                        <a class="fw-semibold text-decoration-none" href="detalhes_operacao.php?id=<?php echo (int) $r['operacao_id']; ?>">
                                            #<?php echo (int) $r['id']; ?>
                                        </a>
                                        <div class="doc text-muted" style="font-size:.76rem;">Op. <?php echo (int) $r['operacao_id']; ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($r['sacado_nome'] ?? '—'); ?></td>
                                    <td class="col-optional"><?php echo htmlspecialchars($r['cedente_nome'] ?? '—'); ?></td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($r['data_vencimento'])); ?>
                                        <span class="status-pill <?php echo $vTone === 'danger' ? 's-problema' : 's-parcial'; ?> ms-1">
                                            <?php echo htmlspecialchars($vLabel); ?>
                                        </span>
                                    </td>
                                    <td class="num text-end"><?php echo homeFmtMoeda2((float) $r['valor_aberto']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-end">Total em aberto (7 dias)</td>
                                <td class="num text-end"><?php echo homeFmtMoeda2($recVencendoTotal); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Acessos rápidos: cadastros, consultas e gestão -->
        <div class="home-section-title">
            <h2>Acesso rápido</h2>
            <span class="hint">cadastros, consultas e gestão</span>
        </div>
        <section class="quick-grid">
            <?php foreach ($acessosRapidos as $atalho): ?>
            <a class="quick-tile" href="<?php echo htmlspecialchars($atalho['url']); ?>">
                <span class="qt-icon a-<?php echo $atalho['accent']; ?>"><i class="bi <?php echo $atalho['icon']; ?>"></i></span>
                <span class="qt-label"><?php echo htmlspecialchars($atalho['label']); ?></span>
            </a>
            <?php endforeach; ?>
        </section>

    </div>
</body>
</html>
