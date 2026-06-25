<?php require_once 'auth_check.php'; ?>
<?php
require_once 'db_connection.php'; // Conexão $pdo

$pageTitle = "Visualizar Cliente";
$cliente = null;
$socios = [];

// Verifica se um ID foi passado via GET
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: listar_clientes.php?status=error&msg=" . urlencode("ID do cliente não fornecido."));
    exit;
}

$clienteId = (int)$_GET['id'];

try {
    // Buscar dados do cliente
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = :id");
    $stmt->bindParam(':id', $clienteId, PDO::PARAM_INT);
    $stmt->execute();
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        header("Location: listar_clientes.php?status=error&msg=" . urlencode("Cliente não encontrado."));
        exit;
    }

    // Sócios
    $stmt_socios = $pdo->prepare("SELECT * FROM clientes_socios WHERE cliente_id = :cliente_id ORDER BY id");
    $stmt_socios->bindParam(':cliente_id', $clienteId, PDO::PARAM_INT);
    $stmt_socios->execute();
    $socios = $stmt_socios->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro ao buscar dados do cliente: " . $e->getMessage());
}

// ===== KPIs / Operações =====
$kpis = [
    'total_ops' => 0,
    'volume_total' => 0,
    'volume_12m' => 0,
    'lucro_12m' => 0,
    'ticket_medio' => 0,
    'ultima_data' => null,
    'ultimas_ops' => [],
];
try {
    $stmtKpi = $pdo->prepare("
        SELECT
            COUNT(*) AS total_ops,
            COALESCE(SUM(total_original_calc), 0) AS volume_total,
            COALESCE(SUM(CASE WHEN data_operacao >= DATE_SUB(NOW(), INTERVAL 12 MONTH) THEN total_original_calc ELSE 0 END), 0) AS volume_12m,
            COALESCE(SUM(CASE WHEN data_operacao >= DATE_SUB(NOW(), INTERVAL 12 MONTH) THEN total_lucro_liquido_calc ELSE 0 END), 0) AS lucro_12m,
            MAX(data_operacao) AS ultima_data
        FROM operacoes
        WHERE cedente_id = :cid
    ");
    $stmtKpi->bindValue(':cid', $clienteId, PDO::PARAM_INT);
    $stmtKpi->execute();
    $row = $stmtKpi->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $kpis['total_ops']    = (int) $row['total_ops'];
        $kpis['volume_total'] = (float) $row['volume_total'];
        $kpis['volume_12m']   = (float) $row['volume_12m'];
        $kpis['lucro_12m']    = (float) $row['lucro_12m'];
        $kpis['ultima_data']  = $row['ultima_data'];
        $kpis['ticket_medio'] = $kpis['total_ops'] > 0 ? $kpis['volume_total'] / $kpis['total_ops'] : 0;
    }

    // Últimas 5 operações
    $stmtOps = $pdo->prepare("
        SELECT id, data_operacao, total_original_calc, total_liquido_pago_calc, tipo_operacao
        FROM operacoes
        WHERE cedente_id = :cid
        ORDER BY data_operacao DESC, id DESC
        LIMIT 5
    ");
    $stmtOps->bindValue(':cid', $clienteId, PDO::PARAM_INT);
    $stmtOps->execute();
    $kpis['ultimas_ops'] = $stmtOps->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Silencioso: KPIs ficam zerados se a tabela não existir ainda
}

// ===== Helpers =====
function formatDocumento($documento) {
    $docLimpo = preg_replace('/\D/', '', (string)$documento);
    if (empty($docLimpo)) return '-';
    if (strlen($docLimpo) === 11) {
        return substr($docLimpo, 0, 3) . '.' . substr($docLimpo, 3, 3) . '.' .
               substr($docLimpo, 6, 3) . '-' . substr($docLimpo, 9, 2);
    } elseif (strlen($docLimpo) === 14) {
        return substr($docLimpo, 0, 2) . '.' . substr($docLimpo, 2, 3) . '.' .
               substr($docLimpo, 5, 3) . '/' . substr($docLimpo, 8, 4) . '-' . substr($docLimpo, 12, 2);
    }
    return htmlspecialchars($documento);
}
function formatTelefone($telefone) {
    $tel = preg_replace('/\D/', '', (string)$telefone);
    if (strlen($tel) == 11) return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 5) . '-' . substr($tel, 7, 4);
    if (strlen($tel) == 10) return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 4) . '-' . substr($tel, 6, 4);
    return $telefone;
}
function formatCEP($cep) {
    $cepLimpo = preg_replace('/\D/', '', (string)$cep);
    if (strlen($cepLimpo) == 8) return substr($cepLimpo, 0, 5) . '-' . substr($cepLimpo, 5, 3);
    return $cep;
}
function valOuMuted($v, $padrao = 'Não informado') {
    if ($v === null || $v === '' ) {
        return '<span class="info-value muted">' . htmlspecialchars($padrao) . '</span>';
    }
    return htmlspecialchars($v);
}
function moedaBR($v) {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}
function moedaCompact($v) {
    $v = (float)$v;
    if ($v >= 1000000) return 'R$ ' . number_format($v / 1000000, 2, ',', '.') . ' mi';
    if ($v >= 10000)   return 'R$ ' . number_format($v / 1000, 1, ',', '.') . ' mil';
    return moedaBR($v);
}
function formatPorte($p) {
    $map = [
        'MEI' => 'MEI <span class="text-muted small">(até R$ 81 mil)</span>',
        'ME' => 'ME <span class="text-muted small">(até R$ 360 mil)</span>',
        'EPP' => 'EPP <span class="text-muted small">(até R$ 4,8 mi)</span>',
        'MEDIO' => 'Médio',
        'GRANDE' => 'Grande',
    ];
    return $map[$p] ?? ($p ?: '—');
}
function iniciais($nome) {
    $nome = trim((string)$nome);
    if ($nome === '') return '?';
    $partes = preg_split('/\s+/', $nome);
    $iniciais = '';
    foreach ($partes as $p) {
        if (strlen($iniciais) >= 2) break;
        $iniciais .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $iniciais;
}
function diasDesde($dataStr) {
    if (!$dataStr) return null;
    try {
        $dt = new DateTime($dataStr);
        $hoje = new DateTime();
        return (int) $hoje->diff($dt)->days;
    } catch (Exception $e) { return null; }
}

$ultimaDias = diasDesde($kpis['ultima_data']);
$cadastroFmt = '';
if (!empty($cliente['data_cadastro'])) {
    try { $cadastroFmt = (new DateTime($cliente['data_cadastro']))->format('d/m/Y'); } catch (Exception $e) {}
}

// Identificar representante atual entre os sócios
$repNomeNorm = strtolower(trim(preg_replace('/\s+/', ' ', $cliente['representante_nome'] ?? '')));
$repCpfNorm = preg_replace('/\D/', '', $cliente['representante_cpf'] ?? '');

$pageTitle = ($cliente['empresa'] ?? 'Cliente') . ' · Visualizar Cliente';
require_once 'head.php';
?>
    <style>
        /* Estilos específicos da página (tokens e componentes base vêm de theme.css) */
        body { font-size: 0.95rem; }

        :root {
            --view-grad: linear-gradient(135deg, #0a8754 0%, #15b079 100%);
        }

        .status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 999px;
            font-size: 0.8rem; font-weight: 600;
            background: var(--profit-soft); color: #0f5132;
        }

        /* Label/value styling — esta tela usa o grid do Bootstrap (row g-3 + col-md-X),
           fora de .info-cell, então mantém os estilos próprios. */
        .info-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--neutral);
            font-weight: 600;
            margin-bottom: 3px;
        }
        .info-value {
            font-size: 0.95rem;
            color: #212529;
            font-weight: 500;
            min-height: 22px;
            overflow-wrap: break-word;
        }
        .info-value.muted { color: #adb5bd; font-style: italic; font-weight: 400; }
        .info-value a { color: #0a58ca; text-decoration: none; }
        .info-value a:hover { text-decoration: underline; }

        /* Dieta de cor: cabeçalhos de seção unificados na cor da marca. */
        .section-card.s-people .section-head .step-num,
        .section-card.s-rep    .section-head .step-num,
        .section-card.s-addr   .section-head .step-num,
        .section-card.s-bank   .section-head .step-num { background: #0d6efd; }

        .socio-view-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            background: var(--surface);
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .socio-view-card .avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: var(--app-info-soft);
            color: var(--app-info);
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 700; flex-shrink: 0;
        }
        .socio-view-card .name { font-weight: 600; }
        .socio-view-card .doc  { font-size: 0.82rem; color: var(--neutral); }
        .socio-view-card .role-chip {
            display: inline-block;
            font-size: 0.7rem; font-weight: 600;
            padding: 2px 8px; border-radius: 999px;
            background: #eef0f3; color: #5a6470;
            margin-left: 6px;
        }

        /* Sticky panel — estrutura base vem de theme.css; aqui só o gradiente verde da tela */
        .sticky-panel .panel-head { background: var(--view-grad); }
        .view-hero {
            background: var(--view-grad);
            color: #fff;
            padding: 6px 18px 20px;
        }
        .view-hero .label { font-size: 0.74rem; opacity: 0.85; text-transform: uppercase; letter-spacing: 0.04em; }
        .view-hero .value { font-size: 1.6rem; font-weight: 700; line-height: 1.15; }
        .view-hero .sub { font-size: 0.82rem; opacity: 0.9; margin-top: 4px; }

        .view-kpi-grid {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 8px; padding: 14px;
            background: var(--surface);
        }
        .view-kpi {
            border-radius: 10px;
            padding: 10px 12px;
            background: var(--surface-2);
            border: 1px solid var(--border);
        }
        .view-kpi .k-label {
            font-size: 0.7rem; color: var(--neutral);
            text-transform: uppercase; letter-spacing: 0.04em;
            font-weight: 600;
        }
        .view-kpi .k-value { font-size: 1.05rem; font-weight: 700; margin-top: 2px; }
        .view-kpi.span2 { grid-column: 1 / -1; }
        .view-kpi.k-good { background: var(--profit-soft); border-color: #b3e3c4; }
        .view-kpi.k-good .k-value { color: var(--profit); }

        .quick-contacts {
            padding: 12px 16px;
            border-top: 1px solid var(--border);
            background: var(--surface);
            display: flex; flex-wrap: wrap; gap: 6px;
        }
        .contact-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 10px; border-radius: 999px;
            background: var(--surface-2); border: 1px solid var(--border);
            font-size: 0.8rem; color: #495057; text-decoration: none;
        }
        .contact-chip:hover { background: #e9ecef; color: #212529; }
        .contact-chip.wapp  { background: #e6f7eb; border-color: #b9e6c7; color: #146c43; }
        .contact-chip.email { background: #eef4ff; border-color: #c8dafc; color: #0a4ea8; }
        .contact-chip.tel   { background: #f3eeff; border-color: #ddd0f5; color: #5a32a3; }

        .info-tabs {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 18px;
        }
        .ops-table { font-size: 0.88rem; margin-bottom: 0; }
        .ops-table thead th {
            background: var(--surface-2);
            font-size: 0.72rem;
            text-transform: uppercase;
            color: var(--neutral);
            border-bottom: 1px solid var(--border);
            padding: 10px 8px;
        }
        .ops-table tbody td { vertical-align: middle; padding: 10px 8px; }

        .empty-block {
            padding: 28px 18px;
            text-align: center;
            color: var(--neutral);
            font-size: 0.88rem;
        }
        .empty-block i { font-size: 2.2rem; opacity: 0.4; }

        @media print {
            .page-toolbar .btn, .panel-actions, .quick-contacts { display: none !important; }
            body { background: #fff; }
            .sticky-panel { position: static; }
        }
    </style>

    <div class="container-fluid px-3 px-md-4 mt-4" style="max-width: 1500px;">

        <!-- Toolbar -->
        <div class="page-toolbar">
            <div>
                <h1>
                    <i class="bi bi-building text-success"></i>
                    <?php echo htmlspecialchars($cliente['empresa'] ?? '—'); ?>
                    <span class="id-pill"><i class="bi bi-hash"></i>ID <?php echo (int)$cliente['id']; ?></span>
                </h1>
                <div class="text-muted small mt-1">
                    CNPJ <strong><?php echo formatDocumento($cliente['documento_principal'] ?? ''); ?></strong>
                    <?php if ($cadastroFmt): ?> · Cliente desde <?php echo $cadastroFmt; ?><?php endif; ?>
                    · <span class="status-badge"><i class="bi bi-check-circle-fill"></i> Ativo</span>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="listar_clientes.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Lista
                </a>
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer"></i> Imprimir
                </button>
            </div>
        </div>

        <div class="row g-4">

            <!-- ====== LEFT: dados do cliente ====== -->
            <div class="col-xl-8">

                <!-- Dados Empresa -->
                <div class="section-card">
                    <div class="section-head">
                        <span class="step-num">1</span>
                        <h2>Dados da Empresa</h2>
                        <span class="head-meta">PJ · <?php echo formatPorte($cliente['porte'] ?? ''); ?></span>
                    </div>
                    <div class="section-body">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <div class="info-label">Razão Social</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['empresa'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-5">
                                <div class="info-label">CNPJ</div>
                                <div class="info-value"><?php echo formatDocumento($cliente['documento_principal'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">Porte</div>
                                <div class="info-value"><?php echo formatPorte($cliente['porte'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-8">
                                <div class="info-label">E-mail</div>
                                <div class="info-value">
                                    <?php if (!empty($cliente['email'])): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($cliente['email']); ?>"><?php echo htmlspecialchars($cliente['email']); ?></a>
                                    <?php else: echo '<span class="muted">Não informado</span>'; endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Telefone</div>
                                <div class="info-value">
                                    <?php if (!empty($cliente['telefone'])): ?>
                                        <a href="tel:<?php echo preg_replace('/\D/', '', $cliente['telefone']); ?>"><?php echo formatTelefone($cliente['telefone']); ?></a>
                                    <?php else: echo '<span class="muted">Não informado</span>'; endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label"><i class="bi bi-whatsapp text-success"></i> WhatsApp</div>
                                <div class="info-value">
                                    <?php if (!empty($cliente['whatsapp'])): ?>
                                        <a href="https://wa.me/55<?php echo preg_replace('/\D/', '', $cliente['whatsapp']); ?>" target="_blank" rel="noopener noreferrer"><?php echo formatTelefone($cliente['whatsapp']); ?></a>
                                    <?php else: echo '<span class="muted">Não informado</span>'; endif; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">Anotações</div>
                                <div class="info-value"><?php
                                    if (!empty($cliente['anotacoes'])) echo nl2br(htmlspecialchars($cliente['anotacoes']));
                                    else echo '<span class="muted">Sem anotações.</span>';
                                ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sócios -->
                <div class="section-card s-people">
                    <div class="section-head">
                        <span class="step-num">2</span>
                        <h2>Sócios da Empresa</h2>
                        <span class="head-meta"><strong><?php echo count($socios); ?></strong> <?php echo count($socios) === 1 ? 'sócio cadastrado' : 'sócios cadastrados'; ?></span>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($socios)): ?>
                            <div class="row g-2">
                                <?php foreach ($socios as $socio):
                                    $socioNomeNorm = strtolower(trim(preg_replace('/\s+/', ' ', $socio['nome'] ?? '')));
                                    $socioCpfNorm = preg_replace('/\D/', '', $socio['cpf'] ?? '');
                                    $isRep = ($repNomeNorm && $repCpfNorm && $socioNomeNorm === $repNomeNorm && $socioCpfNorm === $repCpfNorm);
                                ?>
                                    <div class="col-md-6">
                                        <div class="socio-view-card">
                                            <div class="avatar"><?php echo iniciais($socio['nome']); ?></div>
                                            <div class="flex-grow-1">
                                                <div class="name">
                                                    <?php echo htmlspecialchars($socio['nome']); ?>
                                                    <?php if ($isRep): ?>
                                                        <span class="role-chip"><i class="bi bi-person-check-fill"></i> Representante</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="doc">CPF <?php echo formatDocumento($socio['cpf']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-block">
                                <i class="bi bi-people"></i>
                                <div class="mt-2">Nenhum sócio cadastrado para esta empresa.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Representante -->
                <?php if (!empty($cliente['representante_nome']) || !empty($cliente['representante_cpf'])): ?>
                <div class="section-card s-rep">
                    <div class="section-head">
                        <span class="step-num">3</span>
                        <h2>Representante Legal</h2>
                        <span class="head-meta">Assina os contratos</span>
                    </div>
                    <div class="section-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <div class="info-label">Nome</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['representante_nome'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">CPF</div>
                                <div class="info-value"><?php echo formatDocumento($cliente['representante_cpf'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">RG</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['representante_rg'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Nacionalidade</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['representante_nacionalidade'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Estado Civil</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['representante_estado_civil'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Profissão</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['representante_profissao'] ?? ''); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">Endereço Completo</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['representante_endereco'] ?? ''); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Endereço -->
                <div class="section-card s-addr">
                    <div class="section-head">
                        <span class="step-num">4</span>
                        <h2>Endereço da Empresa</h2>
                        <span class="head-meta">
                            <?php
                            $cidUf = trim(($cliente['cidade'] ?? '') . ' / ' . ($cliente['estado'] ?? ''), ' /');
                            echo htmlspecialchars($cidUf ?: '—');
                            ?>
                        </span>
                    </div>
                    <div class="section-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="info-label">CEP</div>
                                <div class="info-value"><?php echo valOuMuted(formatCEP($cliente['cep'] ?? '')); ?></div>
                            </div>
                            <div class="col-md-7">
                                <div class="info-label">Logradouro</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['logradouro'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-2">
                                <div class="info-label">Número</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['numero'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">Complemento</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['complemento'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">Bairro</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['bairro'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Cidade</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['cidade'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-1">
                                <div class="info-label">UF</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['estado'] ?? ''); ?></div>
                            </div>
                            <?php if (!empty($cliente['endereco'])): ?>
                            <div class="col-12">
                                <div class="info-label">Observações</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($cliente['endereco'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Bancário -->
                <div class="section-card s-bank">
                    <div class="section-head">
                        <span class="step-num">5</span>
                        <h2>Dados Bancários</h2>
                        <span class="head-meta">
                            <?php
                            $bancoTxt = trim(($cliente['conta_banco'] ?? '') . ' · ' . ($cliente['conta_tipo'] ?? ''), ' ·');
                            echo $bancoTxt ? htmlspecialchars($bancoTxt) : '—';
                            ?>
                        </span>
                    </div>
                    <div class="section-body">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <div class="info-label">Titular</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['conta_titular'] ?? $cliente['empresa'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-5">
                                <div class="info-label">CNPJ Titular</div>
                                <div class="info-value"><?php echo formatDocumento($cliente['conta_documento'] ?? $cliente['documento_principal'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">Banco</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['conta_banco'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-2">
                                <div class="info-label">Agência</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['conta_agencia'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Conta</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['conta_numero'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Tipo</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['conta_tipo'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Tipo PIX</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['conta_pix_tipo'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-9">
                                <div class="info-label">Chave PIX</div>
                                <div class="info-value"><?php echo valOuMuted($cliente['conta_pix'] ?? ''); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ====== RIGHT: sticky panel ====== -->
            <div class="col-xl-4">
                <div class="sticky-panel">
                    <div class="panel-head">
                        <h3><i class="bi bi-bar-chart-fill"></i> Resumo do Cliente</h3>
                        <span class="badge bg-light text-success">Ativo</span>
                    </div>

                    <!-- Hero -->
                    <div class="view-hero">
                        <div class="label">Volume operado (12 meses)</div>
                        <div class="value"><?php echo moedaBR($kpis['volume_12m']); ?></div>
                        <div class="sub">
                            <?php
                            $totalOps = $kpis['total_ops'];
                            echo $totalOps . ' ' . ($totalOps === 1 ? 'operação total' : 'operações totais');
                            if ($kpis['ticket_medio'] > 0) {
                                echo ' · ticket médio ' . moedaBR($kpis['ticket_medio']);
                            }
                            ?>
                        </div>
                    </div>

                    <!-- KPIs -->
                    <div class="view-kpi-grid">
                        <div class="view-kpi">
                            <div class="k-label">Operações totais</div>
                            <div class="k-value"><?php echo $kpis['total_ops']; ?></div>
                        </div>
                        <div class="view-kpi">
                            <div class="k-label">Volume total</div>
                            <div class="k-value"><?php echo moedaCompact($kpis['volume_total']); ?></div>
                        </div>
                        <div class="view-kpi">
                            <div class="k-label">Última operação</div>
                            <div class="k-value">
                                <?php
                                if ($ultimaDias === null) echo '—';
                                elseif ($ultimaDias === 0) echo 'hoje';
                                elseif ($ultimaDias === 1) echo 'ontem';
                                else echo 'há ' . $ultimaDias . ' dias';
                                ?>
                            </div>
                        </div>
                        <div class="view-kpi k-good">
                            <div class="k-label">Lucro 12 meses</div>
                            <div class="k-value"><?php echo moedaCompact($kpis['lucro_12m']); ?></div>
                        </div>
                    </div>

                    <!-- Quick contacts -->
                    <?php if (!empty($cliente['whatsapp']) || !empty($cliente['telefone']) || !empty($cliente['email'])): ?>
                    <div class="quick-contacts">
                        <?php if (!empty($cliente['whatsapp'])): ?>
                            <a class="contact-chip wapp" href="https://wa.me/55<?php echo preg_replace('/\D/', '', $cliente['whatsapp']); ?>" target="_blank" rel="noopener noreferrer">
                                <i class="bi bi-whatsapp"></i> <?php echo formatTelefone($cliente['whatsapp']); ?>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($cliente['telefone'])): ?>
                            <a class="contact-chip tel" href="tel:<?php echo preg_replace('/\D/', '', $cliente['telefone']); ?>">
                                <i class="bi bi-telephone-fill"></i> <?php echo formatTelefone($cliente['telefone']); ?>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($cliente['email'])): ?>
                            <a class="contact-chip email" href="mailto:<?php echo htmlspecialchars($cliente['email']); ?>">
                                <i class="bi bi-envelope-fill"></i> <?php echo htmlspecialchars($cliente['email']); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div class="panel-actions">
                        <a href="simulacao.php?cliente_id=<?php echo (int)$cliente['id']; ?>" class="btn btn-primary">
                            <i class="bi bi-calculator-fill"></i> Nova Simulação
                        </a>
                        <a href="form_cliente.php?id=<?php echo (int)$cliente['id']; ?>" class="btn btn-outline-warning">
                            <i class="bi bi-pencil-square"></i> Editar Cadastro
                        </a>
                        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                            <i class="bi bi-printer"></i> Imprimir
                        </button>
                    </div>
                </div>

                <!-- Últimas operações -->
                <?php if (!empty($kpis['ultimas_ops'])): ?>
                <div class="info-tabs mt-3">
                    <div class="px-3 py-2 d-flex justify-content-between align-items-center" style="background:var(--surface-2);border-bottom:1px solid var(--border);">
                        <strong class="small text-uppercase text-muted">Últimas Operações</strong>
                    </div>
                    <div class="table-responsive">
                        <table class="table ops-table mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Data</th>
                                    <th class="text-end">Valor</th>
                                    <th>Tipo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($kpis['ultimas_ops'] as $op): ?>
                                    <tr>
                                        <td>
                                            <a href="detalhes_operacao.php?id=<?php echo (int)$op['id']; ?>" class="text-decoration-none">
                                                <strong>#<?php echo (int)$op['id']; ?></strong>
                                            </a>
                                        </td>
                                        <td><?php
                                            try { echo (new DateTime($op['data_operacao']))->format('d/m/Y'); }
                                            catch (Exception $e) { echo '—'; }
                                        ?></td>
                                        <td class="text-end"><?php echo moedaBR($op['total_original_calc']); ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?php echo $op['tipo_operacao'] === 'emprestimo' ? 'Empréstimo' : 'Antecipação'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</body>
</html>
