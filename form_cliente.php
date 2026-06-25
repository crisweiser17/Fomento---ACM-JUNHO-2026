<?php require_once 'auth_check.php'; ?>
<?php
require_once 'db_connection.php'; // Conexão $pdo

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pageTitle = "Adicionar Novo Cliente";
$cliente = [ // Valores padrão para um novo cliente
    'id' => null,
    'nome' => '',
    'email' => '',
    'telefone' => '',
    'whatsapp' => '',
    'tipo_pessoa' => 'JURIDICA', // Apenas PJ
    'porte' => '',
    'documento_principal' => '',
    'empresa' => '',
    'endereco' => '',
    'cep' => '',
    'logradouro' => '',
    'numero' => '',
    'complemento' => '',
    'bairro' => '',
    'cidade' => '',
    'estado' => '',
    'conta_banco' => '',
    'conta_agencia' => '',
    'conta_numero' => '',
    'conta_pix' => '',
    'conta_tipo' => '',
    'conta_titular' => '',
    'conta_documento' => ''
];
$socios = []; // Array de sócios
$editMode = false; // Flag para saber se estamos editando
$alertStatus = $_GET['status'] ?? '';
$alertMessage = trim($_GET['msg'] ?? '');
$flashClienteForm = $_SESSION['cliente_form_flash'] ?? null;

// Verifica se um ID foi passado via GET (para edição)
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $editMode = true;
    $clienteId = (int)$_GET['id'];
    $pageTitle = "Editar Cliente";

    try {
        // Buscar dados do cliente
        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = :id");
        $stmt->bindParam(':id', $clienteId, PDO::PARAM_INT);
        $stmt->execute();
        $fetchedData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fetchedData) {
             ob_clean();
             header("Location: listar_clientes.php?status=error&msg=" . urlencode("Cliente não encontrado."));
             exit;
        }

        $cliente = array_merge($cliente, $fetchedData);

        // Buscar sócios do cliente
        $stmt_socios = $pdo->prepare("SELECT * FROM clientes_socios WHERE cliente_id = :cliente_id ORDER BY id");
        $stmt_socios->bindParam(':cliente_id', $clienteId, PDO::PARAM_INT);
        $stmt_socios->execute();
        $socios = $stmt_socios->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        die("Erro ao buscar dados do cliente para edição: " . $e->getMessage());
    }
}

$flashAplicaNoContextoAtual = is_array($flashClienteForm)
    && (($editMode && !empty($flashClienteForm['cliente_id']) && (int) $flashClienteForm['cliente_id'] === (int) $cliente['id'])
        || (!$editMode && empty($flashClienteForm['cliente_id'])));

if ($alertStatus === 'error' && $flashAplicaNoContextoAtual) {
    $flashCliente = $flashClienteForm['cliente'] ?? [];
    $flashSocios = $flashClienteForm['socios'] ?? [];

    if (is_array($flashCliente)) {
        $cliente = array_merge($cliente, $flashCliente);
    }

    if (is_array($flashSocios)) {
        $socios = $flashSocios;
    }
}

unset($_SESSION['cliente_form_flash']);

$contaTitularReadonlyValue = $cliente['empresa'] ?? '';
$contaDocumentoReadonlyValue = $cliente['documento_principal'] ?? '';

?>
<?php
$pageTitle = 'Cliente';
require_once 'head.php';
?>
    <style>
        /* Estilos específicos do formulário (tokens e componentes base vêm de theme.css) */
        :root {
            --hero-bg-grad: linear-gradient(135deg, #0d3a6e 0%, #1d5fb0 100%);
        }
        body { font-size: 0.95rem; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-badge.editing { background: var(--app-warn-soft); color: var(--app-warn); }
        .status-badge.new { background: var(--app-info-soft); color: var(--app-info); }

        /* Dieta de cor: cabeçalhos de seção unificados na cor da marca (base em theme.css). */
        .section-card.s-people .section-head .step-num,
        .section-card.s-rep    .section-head .step-num,
        .section-card.s-addr   .section-head .step-num,
        .section-card.s-bank   .section-head .step-num { background: #0d6efd; }

        .section-status {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 999px;
        }
        .section-status.ok    { background: var(--profit-soft); color: var(--profit); }
        .section-status.warn  { background: var(--warn-soft); color: var(--warn); }
        .section-status.empty { background: var(--surface-2); color: var(--neutral); }

        /* Form polish */
        .form-label-strong {
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--neutral);
            font-weight: 600;
            margin-bottom: 4px;
            display: block;
        }
        .form-label-strong .req { color: var(--danger); margin-left: 2px; }
        .field-hint {
            font-size: 0.74rem;
            color: var(--neutral);
            margin-top: 4px;
        }
        .field-locked input,
        .field-locked select { background: #f1f3f5; color: #495057; }

        /* Sticky panel */
        .sticky-panel {
            position: sticky;
            top: 16px;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--border);
            background: #fff;
        }
        .sticky-panel .panel-head {
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
            background: var(--hero-bg-grad);
        }
        .sticky-panel .panel-head h3 {
            font-size: 0.85rem;
            margin: 0;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            opacity: 0.92;
        }

        .completion-hero {
            background: var(--hero-bg-grad);
            color: #fff;
            padding: 8px 18px 22px;
        }
        .completion-hero .pct-label {
            font-size: 0.78rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .completion-hero .pct-value {
            font-size: 2.1rem;
            font-weight: 700;
            line-height: 1;
        }
        .completion-hero .progress {
            margin-top: 10px;
            height: 8px;
            background: rgba(255,255,255,0.18);
        }
        .completion-hero .progress-bar { background: #ffd166; transition: width 0.3s ease; }

        .panel-section-list {
            list-style: none;
            margin: 0;
            padding: 8px 0;
        }
        .panel-section-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            font-size: 0.88rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.15s;
        }
        .panel-section-list li:hover { background: var(--surface-2); }
        .panel-section-list li:last-child { border-bottom: none; }
        .panel-section-list .ico {
            width: 24px; height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            flex-shrink: 0;
            color: #fff;
        }
        .panel-section-list .ico.ok    { background: var(--profit); }
        .panel-section-list .ico.warn  { background: var(--warn); }
        .panel-section-list .ico.empty { background: #adb5bd; }
        .panel-section-list .label { flex: 1; font-weight: 500; }
        .panel-section-list .meta  { font-size: 0.74rem; color: var(--neutral); }

        .panel-actions {
            padding: 14px 16px;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: var(--surface);
        }
        .panel-actions .btn { font-weight: 600; }

        /* Sócios cards */
        .socio-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
            background: var(--surface-2);
            position: relative;
            transition: border-color 0.15s, box-shadow 0.15s;
            margin-bottom: 0;
        }
        .socio-card:hover { border-color: #adb5bd; }
        .socio-card .socio-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .socio-card .badge-num {
            background: #0d6efd;
            color: #fff;
            font-size: 0.72rem;
            padding: 3px 9px;
            border-radius: 999px;
            font-weight: 700;
        }
        .socio-card .btn-remove-socio {
            background: transparent;
            border: none;
            color: var(--danger);
            font-size: 0.95rem;
            padding: 2px 6px;
            cursor: pointer;
        }
        .socio-card .btn-remove-socio:hover { background: var(--danger-soft); border-radius: 6px; }

        .empty-block {
            padding: 28px 18px;
            text-align: center;
            color: var(--neutral);
            font-size: 0.88rem;
        }
        .empty-block i { font-size: 2.2rem; opacity: 0.4; }

        .info-tabs {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 18px;
        }

        .loading-cep { display: none; }
        .loading-cep.show { display: block; }
    </style>

    <div class="container-fluid px-3 px-md-4 mt-4" style="max-width: 1500px;">
        <?php if (in_array($alertStatus, ['success', 'error'], true) && $alertMessage !== ''): ?>
            <div class="alert alert-<?php echo $alertStatus === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <i class="bi <?php echo $alertStatus === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?>"></i>
                <?php echo htmlspecialchars($alertMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form action="salvar_cliente.php" method="post" id="form-cliente">
            <?php if ($editMode): ?>
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($cliente['id']); ?>">
            <?php endif; ?>
            <input type="hidden" id="tipo_pessoa" name="tipo_pessoa" value="JURIDICA">

            <!-- Toolbar -->
            <div class="page-toolbar">
                <div>
                    <h1>
                        <?php if ($editMode): ?>
                            <i class="bi bi-pencil-square text-warning"></i>
                            Editar Cliente
                            <?php if (!empty($cliente['empresa'])): ?>
                                <span class="subtitle">— <?php echo htmlspecialchars($cliente['empresa']); ?></span>
                            <?php endif; ?>
                            <span class="id-pill"><i class="bi bi-hash"></i>ID <?php echo (int) $cliente['id']; ?></span>
                        <?php else: ?>
                            <i class="bi bi-plus-circle text-primary"></i>
                            Novo Cliente
                        <?php endif; ?>
                    </h1>
                    <div class="text-muted small mt-1">
                        <?php if ($editMode): ?>
                            <span class="status-badge editing"><i class="bi bi-pencil-fill"></i> Modo edição</span>
                        <?php else: ?>
                            Preencha os dados básicos para começar ·
                            <span class="status-badge new"><i class="bi bi-stars"></i> Em rascunho</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="<?php echo $editMode ? 'visualizar_cliente.php?id=' . (int)$cliente['id'] : 'listar_clientes.php'; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-lg"></i> Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save-fill"></i> <?php echo $editMode ? 'Salvar Alterações' : 'Salvar Cliente'; ?>
                    </button>
                </div>
            </div>

            <div class="row g-4">

                <!-- ====== LEFT: form ====== -->
                <div class="col-xl-8">

                    <!-- 1. Dados Empresa -->
                    <div class="section-card" data-section="empresa">
                        <div class="section-head">
                            <span class="step-num">1</span>
                            <h2>Dados da Empresa</h2>
                            <span class="section-status empty" data-status-for="empresa">—</span>
                        </div>
                        <div class="section-body">
                            <div class="row g-3">
                                <div class="col-md-7">
                                    <label class="form-label-strong" for="empresa">Razão Social <span class="req">*</span></label>
                                    <input type="text" class="form-control" id="empresa" name="empresa"
                                           value="<?php echo htmlspecialchars($cliente['empresa'] ?? ''); ?>"
                                           placeholder="Ex.: ACME Indústria Ltda." required>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label-strong" for="documento_principal">CNPJ <span class="req">*</span></label>
                                    <input type="text" class="form-control" id="documento_principal" name="documento_principal"
                                           value="<?php echo htmlspecialchars($cliente['documento_principal'] ?? ''); ?>"
                                           placeholder="00.000.000/0000-00" required>
                                    <div class="invalid-feedback" id="documento_principal-feedback"></div>
                                    <div class="field-hint">Formatação automática enquanto digita.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label-strong" for="porte">Porte</label>
                                    <select class="form-select" id="porte" name="porte">
                                        <option value="">Selecione…</option>
                                        <option value="MEI" <?php echo ($cliente['porte'] ?? '') == 'MEI' ? 'selected' : ''; ?>>MEI → até R$ 81 mil</option>
                                        <option value="ME" <?php echo ($cliente['porte'] ?? '') == 'ME' ? 'selected' : ''; ?>>ME → até R$ 360 mil</option>
                                        <option value="EPP" <?php echo ($cliente['porte'] ?? '') == 'EPP' ? 'selected' : ''; ?>>EPP → até R$ 4,8 milhões</option>
                                        <option value="MEDIO" <?php echo ($cliente['porte'] ?? '') == 'MEDIO' ? 'selected' : ''; ?>>Médio</option>
                                        <option value="GRANDE" <?php echo ($cliente['porte'] ?? '') == 'GRANDE' ? 'selected' : ''; ?>>Grande</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label-strong" for="email">E-mail</label>
                                    <input type="email" class="form-control" id="email" name="email"
                                           value="<?php echo htmlspecialchars($cliente['email'] ?? ''); ?>"
                                           placeholder="contato@empresa.com.br">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-strong" for="telefone">Telefone</label>
                                    <input type="tel" class="form-control" id="telefone" name="telefone"
                                           value="<?php echo htmlspecialchars($cliente['telefone'] ?? ''); ?>"
                                           placeholder="(00) 0000-0000">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-strong" for="whatsapp"><i class="bi bi-whatsapp text-success"></i> WhatsApp</label>
                                    <input type="tel" class="form-control" id="whatsapp" name="whatsapp"
                                           value="<?php echo htmlspecialchars($cliente['whatsapp'] ?? ''); ?>"
                                           placeholder="(00) 00000-0000">
                                </div>
                                <div class="col-12">
                                    <label class="form-label-strong" for="anotacoes">Anotações</label>
                                    <textarea class="form-control" id="anotacoes" name="anotacoes" rows="2"
                                              placeholder="Observações internas sobre o cliente…"><?php echo htmlspecialchars($cliente['anotacoes'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Sócios -->
                    <div class="section-card s-people" id="socios_card" data-section="socios">
                        <div class="section-head">
                            <span class="step-num">2</span>
                            <h2>Sócios da Empresa</h2>
                            <span class="section-status empty" data-status-for="socios">—</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-adicionar-socio">
                                <i class="bi bi-plus-circle"></i> Adicionar Sócio
                            </button>
                        </div>
                        <div class="section-body">
                            <div id="socios-container" class="row g-2">
                                <?php if (!empty($socios)): ?>
                                    <?php foreach ($socios as $index => $socio): ?>
                                        <div class="col-md-6 socio-item" data-index="<?php echo $index; ?>">
                                            <div class="socio-card">
                                                <div class="socio-head">
                                                    <span class="badge-num">SÓCIO <?php echo $index + 1; ?></span>
                                                    <button type="button" class="btn-remove-socio" title="Remover sócio">
                                                        <i class="bi bi-trash-fill"></i>
                                                    </button>
                                                </div>
                                                <div class="row g-2">
                                                    <div class="col-12">
                                                        <label class="form-label-strong">Nome <span class="req">*</span></label>
                                                        <input type="text" class="form-control form-control-sm socio-nome"
                                                               name="socios[<?php echo $index; ?>][nome]"
                                                               value="<?php echo htmlspecialchars($socio['nome']); ?>" required>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label-strong">CPF <span class="req">*</span></label>
                                                        <input type="text" class="form-control form-control-sm socio-cpf"
                                                               name="socios[<?php echo $index; ?>][cpf]"
                                                               value="<?php echo htmlspecialchars($socio['cpf']); ?>" required>
                                                        <div class="invalid-feedback"></div>
                                                    </div>
                                                </div>
                                                <?php if (isset($socio['id'])): ?>
                                                    <input type="hidden" name="socios[<?php echo $index; ?>][id]" value="<?php echo $socio['id']; ?>">
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div id="no-socios-message" class="empty-block" <?php echo !empty($socios) ? 'style="display: none;"' : ''; ?>>
                                <i class="bi bi-people"></i>
                                <div class="mt-2">Adicione os sócios da empresa para vinculá-los como representantes ou avalistas.</div>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Representante -->
                    <div class="section-card s-rep" id="representante_card" data-section="representante">
                        <div class="section-head">
                            <span class="step-num">3</span>
                            <h2>Representante Legal</h2>
                            <span class="section-status empty" data-status-for="representante">—</span>
                        </div>
                        <div class="section-body">
                            <div class="row g-3">
                                <div class="col-12" id="representante_socio_container">
                                    <label class="form-label-strong" for="representante_socio_select">Selecionar sócio como representante</label>
                                    <select class="form-select" id="representante_socio_select">
                                        <option value="">Selecione um sócio…</option>
                                    </select>
                                    <div class="field-hint"><i class="bi bi-info-circle"></i> Os campos abaixo são preenchidos automaticamente.</div>
                                </div>
                                <div class="col-md-8 field-locked">
                                    <label class="form-label-strong" for="representante_nome">Nome</label>
                                    <input type="text" class="form-control" id="representante_nome" name="representante_nome"
                                           value="<?php echo htmlspecialchars($cliente['representante_nome'] ?? ''); ?>" readonly>
                                </div>
                                <div class="col-md-4 field-locked">
                                    <label class="form-label-strong" for="representante_cpf">CPF</label>
                                    <input type="text" class="form-control cpf-mask" id="representante_cpf" name="representante_cpf"
                                           value="<?php echo htmlspecialchars($cliente['representante_cpf'] ?? ''); ?>" readonly>
                                </div>
                                <div class="col-12" id="campos_adicionais_representante" style="display: none;">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label-strong" for="representante_rg">RG</label>
                                            <input type="text" class="form-control" id="representante_rg" name="representante_rg"
                                                   value="<?php echo htmlspecialchars($cliente['representante_rg'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label-strong" for="representante_nacionalidade">Nacionalidade</label>
                                            <input type="text" class="form-control" id="representante_nacionalidade" name="representante_nacionalidade"
                                                   value="<?php echo htmlspecialchars($cliente['representante_nacionalidade'] ?? 'brasileiro(a)'); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label-strong" for="representante_estado_civil">Estado Civil</label>
                                            <select class="form-select" id="representante_estado_civil" name="representante_estado_civil">
                                                <option value="">Selecione...</option>
                                                <option value="Solteiro(a)" <?php echo ($cliente['representante_estado_civil'] ?? '') === 'Solteiro(a)' ? 'selected' : ''; ?>>Solteiro(a)</option>
                                                <option value="Casado(a)" <?php echo ($cliente['representante_estado_civil'] ?? '') === 'Casado(a)' ? 'selected' : ''; ?>>Casado(a)</option>
                                                <option value="Separado(a)" <?php echo ($cliente['representante_estado_civil'] ?? '') === 'Separado(a)' ? 'selected' : ''; ?>>Separado(a)</option>
                                                <option value="Divorciado(a)" <?php echo ($cliente['representante_estado_civil'] ?? '') === 'Divorciado(a)' ? 'selected' : ''; ?>>Divorciado(a)</option>
                                                <option value="Viúvo(a)" <?php echo ($cliente['representante_estado_civil'] ?? '') === 'Viúvo(a)' ? 'selected' : ''; ?>>Viúvo(a)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label-strong" for="representante_profissao">Profissão</label>
                                            <input type="text" class="form-control" id="representante_profissao" name="representante_profissao"
                                                   value="<?php echo htmlspecialchars($cliente['representante_profissao'] ?? ''); ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label-strong" for="representante_endereco">Endereço Completo</label>
                                            <input type="text" class="form-control" id="representante_endereco" name="representante_endereco"
                                                   value="<?php echo htmlspecialchars($cliente['representante_endereco'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Endereço -->
                    <div class="section-card s-addr" data-section="endereco">
                        <div class="section-head">
                            <span class="step-num">4</span>
                            <h2>Endereço da Empresa</h2>
                            <span class="section-status empty" data-status-for="endereco">—</span>
                        </div>
                        <div class="section-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label-strong" for="cep">CEP</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="cep" name="cep"
                                               value="<?php echo htmlspecialchars($cliente['cep'] ?? ''); ?>" placeholder="00000-000">
                                        <button class="btn btn-outline-secondary" type="button" id="btn-buscar-cep" title="Buscar CEP">
                                            <i class="bi bi-search"></i>
                                        </button>
                                    </div>
                                    <div class="loading-cep field-hint">
                                        <i class="bi bi-arrow-clockwise"></i> Buscando…
                                    </div>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label-strong" for="logradouro">Logradouro</label>
                                    <input type="text" class="form-control" id="logradouro" name="logradouro"
                                           value="<?php echo htmlspecialchars($cliente['logradouro'] ?? ''); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label-strong" for="numero">Número</label>
                                    <input type="text" class="form-control" id="numero" name="numero"
                                           value="<?php echo htmlspecialchars($cliente['numero'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label-strong" for="complemento">Complemento</label>
                                    <input type="text" class="form-control" id="complemento" name="complemento"
                                           value="<?php echo htmlspecialchars($cliente['complemento'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label-strong" for="bairro">Bairro</label>
                                    <input type="text" class="form-control" id="bairro" name="bairro"
                                           value="<?php echo htmlspecialchars($cliente['bairro'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label-strong" for="cidade">Cidade</label>
                                    <input type="text" class="form-control" id="cidade" name="cidade"
                                           value="<?php echo htmlspecialchars($cliente['cidade'] ?? ''); ?>">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label-strong" for="estado">UF</label>
                                    <input type="text" class="form-control" id="estado" name="estado"
                                           value="<?php echo htmlspecialchars($cliente['estado'] ?? ''); ?>" maxlength="2">
                                </div>
                                <div class="col-12">
                                    <label class="form-label-strong" for="endereco">Observações</label>
                                    <textarea class="form-control" id="endereco" name="endereco" rows="2"><?php echo htmlspecialchars($cliente['endereco'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 5. Bancário -->
                    <div class="section-card s-bank" data-section="bancario">
                        <div class="section-head">
                            <span class="step-num">5</span>
                            <h2>Dados Bancários</h2>
                            <span class="section-status empty" data-status-for="bancario">—</span>
                        </div>
                        <div class="section-body">
                            <div class="row g-3">
                                <div class="col-md-7 field-locked">
                                    <label class="form-label-strong" for="conta_titular">Titular da Conta <span class="text-muted small">(auto)</span></label>
                                    <input type="text" class="form-control" id="conta_titular" name="conta_titular"
                                           value="<?php echo htmlspecialchars($contaTitularReadonlyValue); ?>" readonly>
                                </div>
                                <div class="col-md-5 field-locked">
                                    <label class="form-label-strong" for="conta_documento">CNPJ Titular <span class="text-muted small">(auto)</span></label>
                                    <input type="text" class="form-control" id="conta_documento" name="conta_documento"
                                           value="<?php echo htmlspecialchars($contaDocumentoReadonlyValue); ?>" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label-strong" for="conta_banco">Banco</label>
                                    <input type="text" class="form-control" id="conta_banco" name="conta_banco"
                                           value="<?php echo htmlspecialchars($cliente['conta_banco'] ?? ''); ?>" placeholder="Ex.: Itaú">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label-strong" for="conta_agencia">Agência</label>
                                    <input type="text" class="form-control" id="conta_agencia" name="conta_agencia"
                                           value="<?php echo htmlspecialchars($cliente['conta_agencia'] ?? ''); ?>" placeholder="0000">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label-strong" for="conta_numero">Conta</label>
                                    <input type="text" class="form-control" id="conta_numero" name="conta_numero"
                                           value="<?php echo htmlspecialchars($cliente['conta_numero'] ?? ''); ?>" placeholder="00000-0">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label-strong" for="conta_tipo">Tipo</label>
                                    <select class="form-select" id="conta_tipo" name="conta_tipo">
                                        <option value="" <?php echo empty($cliente['conta_tipo']) ? 'selected' : ''; ?>>Selecione...</option>
                                        <option value="Corrente" <?php echo ($cliente['conta_tipo'] ?? '') === 'Corrente' ? 'selected' : ''; ?>>Conta Corrente</option>
                                        <option value="Poupanca" <?php echo ($cliente['conta_tipo'] ?? '') === 'Poupanca' ? 'selected' : ''; ?>>Conta Poupança</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label-strong" for="conta_pix_tipo">Tipo PIX</label>
                                    <select class="form-select" id="conta_pix_tipo" name="conta_pix_tipo">
                                        <option value="" <?php echo empty($cliente['conta_pix_tipo']) ? 'selected' : ''; ?>>Selecione...</option>
                                        <option value="CPF" <?php echo ($cliente['conta_pix_tipo'] ?? '') === 'CPF' ? 'selected' : ''; ?>>CPF</option>
                                        <option value="CNPJ" <?php echo ($cliente['conta_pix_tipo'] ?? '') === 'CNPJ' ? 'selected' : ''; ?>>CNPJ</option>
                                        <option value="Email" <?php echo ($cliente['conta_pix_tipo'] ?? '') === 'Email' ? 'selected' : ''; ?>>E-mail</option>
                                        <option value="Telefone" <?php echo ($cliente['conta_pix_tipo'] ?? '') === 'Telefone' ? 'selected' : ''; ?>>Telefone</option>
                                        <option value="Aleatoria" <?php echo ($cliente['conta_pix_tipo'] ?? '') === 'Aleatoria' ? 'selected' : ''; ?>>Chave Aleatória</option>
                                    </select>
                                </div>
                                <div class="col-md-9">
                                    <label class="form-label-strong" for="conta_pix">Chave PIX</label>
                                    <input type="text" class="form-control" id="conta_pix" name="conta_pix"
                                           value="<?php echo htmlspecialchars($cliente['conta_pix'] ?? ''); ?>" placeholder="Informe a chave PIX">
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ====== RIGHT: progresso + ações ====== -->
                <div class="col-xl-4">
                    <div class="sticky-panel">
                        <div class="panel-head">
                            <h3><i class="bi bi-list-check"></i> Progresso do Cadastro</h3>
                            <span class="badge bg-light text-primary"><?php echo $editMode ? 'Editando' : 'Novo'; ?></span>
                        </div>

                        <div class="completion-hero">
                            <div class="pct-label">Completo</div>
                            <div class="pct-value" id="completion-pct">0%</div>
                            <div class="progress">
                                <div class="progress-bar" id="completion-bar" style="width:0%;"></div>
                            </div>
                        </div>

                        <ul class="panel-section-list">
                            <li data-jump="empresa">
                                <span class="ico empty" data-ico-for="empresa"><i class="bi bi-circle"></i></span>
                                <span class="label">Dados da Empresa</span>
                                <span class="meta" data-meta-for="empresa">obrigatório</span>
                            </li>
                            <li data-jump="socios">
                                <span class="ico empty" data-ico-for="socios"><i class="bi bi-circle"></i></span>
                                <span class="label">Sócios</span>
                                <span class="meta" data-meta-for="socios">recomendado</span>
                            </li>
                            <li data-jump="representante">
                                <span class="ico empty" data-ico-for="representante"><i class="bi bi-circle"></i></span>
                                <span class="label">Representante</span>
                                <span class="meta" data-meta-for="representante">após sócios</span>
                            </li>
                            <li data-jump="endereco">
                                <span class="ico empty" data-ico-for="endereco"><i class="bi bi-circle"></i></span>
                                <span class="label">Endereço</span>
                                <span class="meta" data-meta-for="endereco">opcional</span>
                            </li>
                            <li data-jump="bancario">
                                <span class="ico empty" data-ico-for="bancario"><i class="bi bi-circle"></i></span>
                                <span class="label">Dados Bancários</span>
                                <span class="meta" data-meta-for="bancario">opcional</span>
                            </li>
                        </ul>

                        <div class="panel-actions">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-save-fill"></i> <?php echo $editMode ? 'Salvar Alterações' : 'Salvar Cliente'; ?>
                            </button>
                            <a href="<?php echo $editMode ? 'visualizar_cliente.php?id=' . (int)$cliente['id'] : 'listar_clientes.php'; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-x-lg"></i> Cancelar
                            </a>
                        </div>
                    </div>

                    <?php if (!$editMode): ?>
                    <div class="info-tabs mt-3" style="background:#fff8e1;border-color:#f1d999;">
                        <div class="px-3 py-2" style="background:#fff3d6;border-bottom:1px solid #f1d999;">
                            <strong class="small text-uppercase" style="color:var(--app-warn);"><i class="bi bi-lightbulb-fill"></i> Dica</strong>
                        </div>
                        <div class="p-3 small" style="color:var(--app-warn);">
                            Você só precisa preencher <strong>Razão Social</strong> e <strong>CNPJ</strong> para salvar.
                            Os demais dados podem ser completados depois.
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </form>

    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.inputmask/5.0.9/jquery.inputmask.min.js"></script>

    <script>
        // ===== Validações =====
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        function isValidCPF(cpf) {
            cpf = cpf.replace(/[^\d]+/g, '');
            if (cpf.length !== 11) return false;
            if (cpf === '00000000000') return true;
            if (/^(\d)\1{10}$/.test(cpf)) return false;
            let soma = 0, resto;
            for (let i = 1; i <= 9; i++) soma += parseInt(cpf.substring(i - 1, i)) * (11 - i);
            resto = (soma * 10) % 11;
            if (resto === 10 || resto === 11) resto = 0;
            if (resto !== parseInt(cpf.substring(9, 10))) return false;
            soma = 0;
            for (let i = 1; i <= 10; i++) soma += parseInt(cpf.substring(i - 1, i)) * (12 - i);
            resto = (soma * 10) % 11;
            if (resto === 10 || resto === 11) resto = 0;
            return resto === parseInt(cpf.substring(10, 11));
        }

        function isValidCNPJ(cnpj) {
            cnpj = cnpj.replace(/[^\d]+/g, '');
            if (cnpj.length !== 14) return false;
            if (cnpj === '00000000000000') return true;
            if (/^(\d)\1{13}$/.test(cnpj)) return false;
            let tamanho = cnpj.length - 2;
            let numeros = cnpj.substring(0, tamanho);
            let digitos = cnpj.substring(tamanho);
            let soma = 0, pos = tamanho - 7;
            for (let i = tamanho; i >= 1; i--) {
                soma += numeros.charAt(tamanho - i) * pos--;
                if (pos < 2) pos = 9;
            }
            let resultado = soma % 11 < 2 ? 0 : 11 - soma % 11;
            if (resultado !== parseInt(digitos.charAt(0))) return false;
            tamanho += 1;
            numeros = cnpj.substring(0, tamanho);
            soma = 0;
            pos = tamanho - 7;
            for (let i = tamanho; i >= 1; i--) {
                soma += numeros.charAt(tamanho - i) * pos--;
                if (pos < 2) pos = 9;
            }
            resultado = soma % 11 < 2 ? 0 : 11 - soma % 11;
            return resultado === parseInt(digitos.charAt(1));
        }

        $(document).ready(function(){
            let socioIndex = <?php echo count($socios); ?>;

            // Máscaras
            $('#documento_principal').inputmask("99.999.999/9999-99", { clearIncomplete: true, placeholder: "_" });
            $('#telefone').inputmask({ mask: "(99) 9999[9]-9999", greedy: false, clearIncomplete: true, placeholder: "_" });
            $('#whatsapp').inputmask({ mask: "(99) 9999[9]-9999", greedy: false, clearIncomplete: true, placeholder: "_" });
            $('#cep').inputmask("99999-999", { clearIncomplete: true, placeholder: "_" });
            $('.cpf-mask').inputmask("999.999.999-99", { clearIncomplete: true, placeholder: "_" });
            $('.socio-cpf').each(function() {
                $(this).inputmask("999.999.999-99", { clearIncomplete: true, placeholder: "_" });
            });

            // ===== Buscar CEP via ViaCEP =====
            $('#btn-buscar-cep').on('click', function(e) { e.preventDefault(); buscarCEP(true); });
            $('#cep').on('keypress', function(e) {
                if (e.which === 13) { e.preventDefault(); buscarCEP(true); }
            });
            $('#cep').on('blur', function() { buscarCEP(false); });

            function buscarCEP(mostrarAlerta) {
                const cep = $('#cep').val().replace(/\D/g, '');
                if (cep.length !== 8) {
                    if (mostrarAlerta) alert('CEP deve ter 8 dígitos');
                    return;
                }
                $('.loading-cep').addClass('show');
                $.ajax({
                    url: `https://viacep.com.br/ws/${cep}/json/`,
                    type: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        $('.loading-cep').removeClass('show');
                        if (data.erro) {
                            if (mostrarAlerta) alert('CEP não encontrado');
                            return;
                        }
                        $('#logradouro').val(data.logradouro || '');
                        $('#bairro').val(data.bairro || '');
                        $('#cidade').val(data.localidade || '');
                        $('#estado').val(data.uf || '');
                        $('#numero').focus();
                        atualizarProgresso();
                    },
                    error: function() {
                        $('.loading-cep').removeClass('show');
                        if (mostrarAlerta) alert('Erro ao buscar CEP. Verifique sua conexão.');
                    }
                });
            }

            // ===== Sócios =====
            $('#btn-adicionar-socio').click(function() {
                const numero = socioIndex + 1;
                const socioHtml = `
                    <div class="col-md-6 socio-item" data-index="${socioIndex}">
                        <div class="socio-card">
                            <div class="socio-head">
                                <span class="badge-num">SÓCIO ${numero}</span>
                                <button type="button" class="btn-remove-socio" title="Remover sócio">
                                    <i class="bi bi-trash-fill"></i>
                                </button>
                            </div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label-strong">Nome <span class="req">*</span></label>
                                    <input type="text" class="form-control form-control-sm socio-nome" name="socios[${socioIndex}][nome]" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label-strong">CPF <span class="req">*</span></label>
                                    <input type="text" class="form-control form-control-sm socio-cpf" name="socios[${socioIndex}][cpf]" required>
                                    <div class="invalid-feedback"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                $('#socios-container').append(socioHtml);
                $('#no-socios-message').hide();
                $(`.socio-item[data-index="${socioIndex}"] .socio-cpf`).inputmask("999.999.999-99", {
                    clearIncomplete: true, placeholder: "_"
                });
                socioIndex++;
                renumerarSocios();
                updateDynamicSelects();
                atualizarProgresso();
            });

            $(document).on('click', '.btn-remove-socio', function() {
                $(this).closest('.socio-item').remove();
                if ($('.socio-item').length === 0) $('#no-socios-message').show();
                renumerarSocios();
                updateDynamicSelects();
                atualizarProgresso();
            });

            function renumerarSocios() {
                $('.socio-item').each(function(idx) {
                    $(this).find('.badge-num').text('SÓCIO ' + (idx + 1));
                });
            }

            // ===== Representante (vinculado a sócio) =====
            function normalizeRepresentativeName(value) {
                return (value || '').trim().replace(/\s+/g, ' ').toLowerCase();
            }
            function normalizeRepresentativeDocument(value) {
                return (value || '').replace(/\D/g, '');
            }
            function findRepresentativeOptionValue() {
                const nomeAtual = normalizeRepresentativeName($('#representante_nome').val());
                const cpfAtual = normalizeRepresentativeDocument($('#representante_cpf').val());
                if (!nomeAtual || !cpfAtual) return '';
                let matchedValue = '';
                $('#representante_socio_select option').each(function() {
                    const optionValue = $(this).val();
                    if (!optionValue) return;
                    const parts = optionValue.split('|');
                    const optionNome = normalizeRepresentativeName(parts[0] || '');
                    const optionCpf = normalizeRepresentativeDocument(parts[1] || '');
                    if (optionNome === nomeAtual && optionCpf === cpfAtual) {
                        matchedValue = optionValue;
                        return false;
                    }
                });
                return matchedValue;
            }
            function updateRepresentativeDetailsVisibility() {
                const hasRepresentative = Boolean(
                    normalizeRepresentativeName($('#representante_nome').val()) &&
                    normalizeRepresentativeDocument($('#representante_cpf').val())
                );
                $('#campos_adicionais_representante').toggle(hasRepresentative);
            }
            function updateDynamicSelects() {
                let sociosOptions = '';
                $('.socio-item').each(function() {
                    const nome = $(this).find('.socio-nome').val().trim();
                    const cpf = $(this).find('.socio-cpf').val().trim();
                    if (nome || cpf) {
                        sociosOptions += `<option value="${nome}|${cpf}">${nome} (Sócio)</option>`;
                    }
                });
                const repSelect = $('#representante_socio_select');
                const currentRep = repSelect.val();
                repSelect.html('<option value="">Selecione um sócio…</option>');
                repSelect.append(sociosOptions);
                const hasCurrentRepOption = repSelect.find('option').filter(function() {
                    return $(this).val() === currentRep;
                }).length > 0;
                if (currentRep && hasCurrentRepOption) {
                    repSelect.val(currentRep);
                } else {
                    repSelect.val(findRepresentativeOptionValue());
                }
            }
            $(document).on('input', '.socio-nome, .socio-cpf', function() {
                updateDynamicSelects();
                atualizarProgresso();
            });
            updateDynamicSelects();

            $('#representante_socio_select').on('change', function() {
                const val = $(this).val();
                if (val) {
                    const parts = val.split('|');
                    $('#representante_nome').val(parts[0]);
                    $('#representante_cpf').val(parts[1]);
                    $('#campos_adicionais_representante').slideDown();
                } else {
                    $('#representante_nome').val('');
                    $('#representante_cpf').val('');
                    $('#campos_adicionais_representante').slideUp();
                    $('#representante_rg, #representante_nacionalidade, #representante_estado_civil, #representante_profissao, #representante_endereco').val('');
                    $('#representante_nacionalidade').val('brasileiro(a)');
                }
                atualizarProgresso();
            });
            updateRepresentativeDetailsVisibility();

            // ===== Sync titular da conta =====
            function syncContaTitularFields() {
                $('#conta_titular').val($('#empresa').val());
                $('#conta_documento').val($('#documento_principal').val()).trigger('input');
            }
            $('#empresa').on('input', function() { syncContaTitularFields(); atualizarProgresso(); });
            $('#documento_principal').on('input', function() { syncContaTitularFields(); atualizarProgresso(); });
            syncContaTitularFields();

            $('#conta_documento').on('input', function() {
                let val = $(this).val().replace(/\D/g, '');
                if (val.length <= 11) {
                    $(this).inputmask("999.999.999-99", { clearIncomplete: false });
                } else {
                    $(this).inputmask("99.999.999/9999-99", { clearIncomplete: false });
                }
            });

            // ===== Validações em tempo real =====
            $('#email').on('blur', function() {
                const email = $(this).val().trim();
                if (email && !isValidEmail(email)) {
                    $(this).addClass('is-invalid');
                    if (!$(this).next('.invalid-feedback').length) {
                        $(this).after('<div class="invalid-feedback">Por favor, insira um e-mail válido.</div>');
                    }
                } else {
                    $(this).removeClass('is-invalid');
                    $(this).next('.invalid-feedback').remove();
                }
            });

            $('#documento_principal').on('blur', function() {
                const documento = $(this).val().replace(/\D/g, '');
                let valid = documento.length === 14 && isValidCNPJ(documento);
                if (!valid) {
                    $(this).addClass('is-invalid');
                    $('#documento_principal-feedback').text('CNPJ inválido');
                } else {
                    $(this).removeClass('is-invalid');
                    $('#documento_principal-feedback').text('');
                }
            });

            // ===== Submit validation =====
            $('#form-cliente').on('submit', function(e) {
                let isValid = true;

                const email = $('#email').val().trim();
                if (email && !isValidEmail(email)) {
                    $('#email').addClass('is-invalid');
                    if (!$('#email').next('.invalid-feedback').length) {
                        $('#email').after('<div class="invalid-feedback">Por favor, insira um e-mail válido.</div>');
                    }
                    isValid = false;
                } else {
                    $('#email').removeClass('is-invalid');
                    $('#email').next('.invalid-feedback').remove();
                }

                const documento = $('#documento_principal').val().replace(/\D/g, '');
                if (documento.length !== 14 || !isValidCNPJ(documento)) {
                    $('#documento_principal').addClass('is-invalid');
                    $('#documento_principal-feedback').text('CNPJ inválido');
                    isValid = false;
                } else {
                    $('#documento_principal').removeClass('is-invalid');
                }

                $('.socio-cpf').each(function() {
                    const cpf = $(this).val().replace(/\D/g, '');
                    if (cpf.length !== 11 || !isValidCPF(cpf)) {
                        $(this).addClass('is-invalid');
                        $(this).next('.invalid-feedback').text('CPF inválido');
                        isValid = false;
                    } else {
                        $(this).removeClass('is-invalid');
                    }
                });

                const repCpf = $('#representante_cpf').val().replace(/\D/g, '');
                if (repCpf && (repCpf.length !== 11 || !isValidCPF(repCpf))) {
                    $('#representante_cpf').addClass('is-invalid');
                    isValid = false;
                } else {
                    $('#representante_cpf').removeClass('is-invalid');
                }

                const contaDoc = $('#conta_documento').val().replace(/\D/g, '');
                if (contaDoc && (contaDoc.length !== 14 || !isValidCNPJ(contaDoc))) {
                    $('#conta_documento').addClass('is-invalid');
                    isValid = false;
                } else {
                    $('#conta_documento').removeClass('is-invalid');
                }

                if (!isValid) e.preventDefault();
            });

            // ===== Painel de progresso =====
            function setStatus(section, state, label) {
                const $badge = $(`[data-status-for="${section}"]`);
                const $ico = $(`[data-ico-for="${section}"]`);
                const $meta = $(`[data-meta-for="${section}"]`);

                $badge.removeClass('ok warn empty').addClass(state);
                $ico.removeClass('ok warn empty').addClass(state);

                let badgeIcon = '';
                if (state === 'ok')   badgeIcon = '<i class="bi bi-check-circle-fill"></i> ';
                if (state === 'warn') badgeIcon = '<i class="bi bi-exclamation-circle-fill"></i> ';
                $badge.html(badgeIcon + label);
                if ($meta.length) $meta.text(label);

                $ico.html(state === 'ok' ? '<i class="bi bi-check-lg"></i>'
                       : state === 'warn' ? '<i class="bi bi-exclamation"></i>'
                       : '<i class="bi bi-circle"></i>');
            }

            function val(id) { return ($('#' + id).val() || '').trim(); }

            function atualizarProgresso() {
                let totalSec = 5;
                let okSec = 0;

                // Empresa: razão social, cnpj válido, telefone, email, porte
                const empresaCampos = ['empresa', 'documento_principal'];
                const empresaOpcionais = ['email', 'telefone', 'whatsapp', 'porte'];
                const cnpjLimpo = val('documento_principal').replace(/\D/g, '');
                const empresaOk = val('empresa') && cnpjLimpo.length === 14 && isValidCNPJ(cnpjLimpo);
                const empresaOpcPreench = empresaOpcionais.filter(c => val(c)).length;
                if (empresaOk && empresaOpcPreench >= 2) {
                    setStatus('empresa', 'ok', 'Completo');
                    okSec++;
                } else if (empresaOk) {
                    setStatus('empresa', 'warn', 'Faltam contatos');
                } else {
                    setStatus('empresa', 'empty', 'obrigatório');
                }

                // Sócios
                const numSocios = $('.socio-item').length;
                if (numSocios > 0) {
                    setStatus('socios', 'ok', numSocios + (numSocios === 1 ? ' sócio' : ' sócios'));
                    okSec++;
                } else {
                    setStatus('socios', 'empty', 'recomendado');
                }

                // Representante
                const temRep = val('representante_nome') && val('representante_cpf');
                if (temRep) {
                    const opcionais = ['representante_rg', 'representante_nacionalidade', 'representante_estado_civil', 'representante_profissao', 'representante_endereco'];
                    const preench = opcionais.filter(c => val(c)).length;
                    if (preench >= 4) {
                        setStatus('representante', 'ok', 'Completo');
                        okSec++;
                    } else {
                        setStatus('representante', 'warn', (5 - preench) + ' campos vazios');
                    }
                } else {
                    setStatus('representante', 'empty', numSocios > 0 ? 'selecione um sócio' : 'após sócios');
                }

                // Endereço
                const enderecoCampos = ['cep', 'logradouro', 'numero', 'bairro', 'cidade', 'estado'];
                const endPreench = enderecoCampos.filter(c => val(c)).length;
                if (endPreench === enderecoCampos.length) {
                    setStatus('endereco', 'ok', 'Completo');
                    okSec++;
                } else if (endPreench > 0) {
                    setStatus('endereco', 'warn', endPreench + '/' + enderecoCampos.length);
                } else {
                    setStatus('endereco', 'empty', 'opcional');
                }

                // Bancário
                const bancCampos = ['conta_banco', 'conta_agencia', 'conta_numero', 'conta_tipo'];
                const bancPreench = bancCampos.filter(c => val(c)).length;
                const temPix = val('conta_pix') && val('conta_pix_tipo');
                if (bancPreench === bancCampos.length || temPix) {
                    setStatus('bancario', 'ok', temPix && bancPreench === bancCampos.length ? 'Conta + PIX' : (temPix ? 'Só PIX' : 'Conta'));
                    okSec++;
                } else if (bancPreench > 0) {
                    setStatus('bancario', 'warn', bancPreench + '/' + bancCampos.length);
                } else {
                    setStatus('bancario', 'empty', 'opcional');
                }

                const pct = Math.round((okSec / totalSec) * 100);
                $('#completion-pct').text(pct + '%');
                $('#completion-bar').css('width', pct + '%');
            }

            // Atualiza progresso ao digitar/alterar qualquer campo do form
            $('#form-cliente').on('input change', 'input, select, textarea', atualizarProgresso);

            // Jump nav
            $('.panel-section-list li').on('click', function() {
                const target = $(this).data('jump');
                const $sec = $(`[data-section="${target}"]`);
                if ($sec.length) {
                    $('html, body').animate({ scrollTop: $sec.offset().top - 12 }, 250);
                }
            });

            // Cálculo inicial
            atualizarProgresso();
        });
    </script>
</body>
</html>
