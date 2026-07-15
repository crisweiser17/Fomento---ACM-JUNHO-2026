<?php
require_once 'auth_check.php';
require_once 'db_connection.php'; // Garante que a conexão $pdo esteja disponível

// --- Função para ler o arquivo de configuração ---
function readConfig($filePath) {
    if (!file_exists($filePath)) {
        // Criar arquivo padrão se não existir
        $defaultConfig = [
            "default_taxa_mensal" => 5.00,
            "iof_adicional_rate" => 0.0038,
            "iof_diaria_rate" => 0.000082
        ];
        file_put_contents($filePath, json_encode($defaultConfig, JSON_PRETTY_PRINT));
        return $defaultConfig;
    }
    $configContent = file_get_contents($filePath);
    return json_decode($configContent, true);
}

// Caminho para o arquivo de configuração
$configFilePath = __DIR__ . '/config.json';
$appConfig = readConfig($configFilePath);

// Usar valores do config para preencher padrões
$defaultTaxaMensal = $appConfig['default_taxa_mensal'] ?? 5.00;

$clientes = [];
$erro_clientes = null;

try {
    // COALESCE: pessoa física não tem `empresa` preenchido e ficaria sem nome na lista.
    $stmt = $pdo->query(
        "SELECT id, COALESCE(empresa, nome) AS nome, documento_principal
           FROM clientes ORDER BY COALESCE(empresa, nome) ASC"
    );
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar Clientes no DB: " . $e->getMessage());
    $erro_clientes = "Erro ao carregar lista de clientes.";
    $clientes = [];
}

// Para manter compatibilidade com o HTML existente sem mudar muita coisa
$cedentes = $clientes;
$erro_cedentes = $erro_clientes;
$sacados = $clientes;
$erro_sacados = $erro_clientes;

// Pré-seleção via querystring: ?cliente_id=N seleciona o cliente nos dropdowns Cedente/Tomador
$preselect_cliente_id = isset($_GET['cliente_id']) && is_numeric($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : null;
if ($preselect_cliente_id !== null) {
    $idsValidos = array_column($clientes, 'id');
    if (!in_array($preselect_cliente_id, $idsValidos)) {
        $preselect_cliente_id = null; // ID inválido — ignora
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Simulação / Registro - Calculadora</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="theme.css">
    <?php $styleCssVersion = file_exists(__DIR__ . '/style.css') ? (string) filemtime(__DIR__ . '/style.css') : 'missing'; ?>
    <link rel="stylesheet" href="style.css?v=<?php echo rawurlencode($styleCssVersion); ?>">
    <script src="app.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        /* Estilos CSS (mantidos como no seu arquivo) */
        input#taxaMensal::-webkit-outer-spin-button, input#taxaMensal::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        input#taxaMensal[type=number] { appearance: textfield; -moz-appearance: textfield; }
        input#taxaMensal { text-align: center; }
        .chart-wrapper { position: relative; max-height: 350px; margin-top: 20px; margin-bottom: 30px; }
        .chart-wrapper canvas { max-width: 100%; max-height: 100%; }
        .resultado-total-item { padding: 10px 12px; margin-bottom: 10px; text-align: center; }
        .result-row-1 .resultado-total-item .value { font-size: 1.1em; font-weight: normal; }
        .result-row-1 .resultado-total-item .label { font-size: 0.8em; }
        .result-row-2 .resultado-total-item .value { font-size: 1.3em; font-weight: bold; }
        .result-row-2 .resultado-total-item .label { font-size: 0.85em; }
        .value.profit { color: #198754; }
        .value.loss { color: var(--app-danger, #b02a37); }
        .cobrar-iof-wrapper.d-none { display: none !important; }
        .dias-restantes-cell { text-align: center; vertical-align: middle; font-style: italic; color: #6c757d; font-size: 0.9em;}
        /* NOVA CLASSE CSS PARA A NOVA COLUNA */
        .valor-pago-cell { text-align: right; vertical-align: middle; }
        .calc-output-compact .form-label { font-size: 0.85rem; color: #6c757d; }
        .calc-output-compact .form-control,
        .calc-output-compact .btn { font-size: 0.9rem; padding-top: 0.375rem; padding-bottom: 0.375rem; }
        .calc-output-hidden-note { border: 1px dashed #ced4da; border-radius: 0.75rem; background: #f8f9fa; color: #6c757d; padding: 0.7rem 0.9rem; }
        .resumo-modo-badge { letter-spacing: 0.02em; }
        .resumo-item-card { height: 100%; border: 1px solid #dee2e6; border-radius: 0.9rem; padding: 0.85rem 0.6rem; background: #fff; transition: all 0.2s ease; }
        .resumo-item-card.is-active { border-color: #0d6efd; background: linear-gradient(180deg, rgba(13,110,253,0.08), rgba(13,110,253,0.16)); box-shadow: 0 0.4rem 1rem rgba(13,110,253,0.12); }
        .resumo-item-card.is-active small { color: #0d6efd !important; font-weight: 700; }
        .resumo-item-card.is-active .fs-5 { color: #0a58ca; }
    </style>
</head>
<body class="sim-page-body">
  <?php require_once 'menu.php'; ?>

  <div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">

      <!-- Toolbar -->
      <div class="sim-toolbar">
          <div>
              <h1><i class="bi bi-calculator-fill text-primary"></i> Nova Simulação / Registro</h1>
              <div class="text-muted small mt-1">Preencha os dados ao lado e veja o resumo no painel à direita.</div>
          </div>
          <div class="d-flex align-items-center gap-2">
              <span id="simStatusBadge" class="sim-status-badge is-pending">
                  <i class="bi bi-hourglass-split"></i> <span id="simStatusBadgeText">Aguardando cálculo</span>
              </span>
          </div>
      </div>

      <!-- Stepper -->
      <ol class="sim-stepper" id="simStepper">
          <li data-step="1" class="is-active"><span class="step-num">1</span> Modalidade</li>
          <li data-step="2"><span class="step-num">2</span> Cliente &amp; Parâmetros</li>
          <li data-step="3"><span class="step-num">3</span> Tributação &amp; Pagamento</li>
          <li data-step="4"><span class="step-num">4</span> Títulos</li>
          <li data-step="5"><span class="step-num">5</span> Conferir &amp; Registrar</li>
      </ol>

      <form id="calculationForm" method="post">
        <div class="row g-4">

          <!-- ============ COLUNA ESQUERDA: formulário ============ -->
          <div class="col-xl-8">

              <!-- Card 1: Modalidade -->
              <div class="sim-card" id="simStep1">
                  <div class="sim-card-head">
                      <span class="step-num">1</span>
                      <h2>Modalidade da Operação</h2>
                      <span class="head-meta">Define o cálculo aplicado</span>
                  </div>
                  <div class="sim-card-body">
                      <div class="btn-group w-100 sim-modality-toggle" role="group" aria-label="Tipo de Operação">
                          <input type="radio" class="btn-check" name="tipoOperacao" id="tipoAntecipacao" value="antecipacao" autocomplete="off" checked>
                          <label class="btn btn-outline-primary" for="tipoAntecipacao"><i class="bi bi-graph-down-arrow"></i> Antecipação de Recebíveis</label>

                          <input type="radio" class="btn-check" name="tipoOperacao" id="tipoEmprestimo" value="emprestimo" autocomplete="off">
                          <label class="btn btn-outline-primary" for="tipoEmprestimo"><i class="bi bi-cash-coin"></i> Empréstimo (Gerar Parcelas)</label>
                      </div>
                  </div>
              </div>

              <!-- Card 2: Cliente & Parâmetros -->
              <div class="sim-card" id="simStep2">
                  <div class="sim-card-head">
                      <span class="step-num">2</span>
                      <h2>Cliente &amp; Parâmetros</h2>
                      <span class="head-meta">Quem e em que condições</span>
                  </div>
                  <div class="sim-card-body">
                      <div class="row g-3 align-items-end">
                          <div class="col-md-5" id="containerCedente">
                              <label for="cedente" class="form-label-strong" id="labelCedente">Cedente</label>
                              <select id="cedente" name="cedente_id" class="form-select">
                                  <option value="" <?php echo $preselect_cliente_id === null ? 'selected' : ''; ?>>-- Selecione (Obrigatório p/ Registrar) --</option>
                                  <?php foreach ($cedentes as $cedente): ?>
                                      <option value="<?php echo htmlspecialchars($cedente['id']); ?>" <?php echo ((int)$cedente['id'] === $preselect_cliente_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cedente['nome']); ?></option>
                                  <?php endforeach; ?>
                                  <?php if (empty($cedentes) && $erro_cedentes): ?>
                                      <option value="" disabled><?php echo htmlspecialchars($erro_cedentes); ?></option>
                                  <?php elseif (empty($cedentes)):?>
                                      <option value="" disabled>Nenhum cedente cadastrado</option>
                                  <?php endif; ?>
                              </select>
                          </div>
                          <div class="col-md-5" id="containerTomador" style="display: none;">
                              <label for="tomador" class="form-label-strong">Tomador de Empréstimo (Sacado)</label>
                              <select id="tomador" name="tomador_id" class="form-select">
                                  <option value="" <?php echo $preselect_cliente_id === null ? 'selected' : ''; ?>>-- Selecione Tomador --</option>
                                  <?php foreach ($sacados as $sacado): ?>
                                      <option value="<?php echo htmlspecialchars($sacado['id']); ?>" <?php echo ((int)$sacado['id'] === $preselect_cliente_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sacado['nome']); ?></option>
                                  <?php endforeach; ?>
                                  <?php if (empty($sacados) && $erro_sacados): ?>
                                      <option value="" disabled><?php echo htmlspecialchars($erro_sacados); ?></option>
                                  <?php elseif (empty($sacados)): ?>
                                      <option value="" disabled>Nenhum sacado cadastrado</option>
                                  <?php endif; ?>
                              </select>
                          </div>
                          <div class="col-md-4" id="containerTaxaPrincipal">
                              <div id="taxaFieldContainer"><label for="taxaMensal" class="form-label-strong" id="labelTaxa">Taxa de Desconto (% a.m.)</label><div class="input-group"><button class="btn btn-outline-secondary" type="button" id="taxaDecrementBtn">-</button><input type="number" class="form-control" id="taxaMensal" name="taxaMensal" step="0.25" min="0" value="<?php echo htmlspecialchars($defaultTaxaMensal); ?>" required><button class="btn btn-outline-secondary" type="button" id="taxaIncrementBtn">+</button><button class="btn btn-outline-primary" type="button" id="btnAbrirModalTaxa" title="Descobrir taxa a partir de um valor líquido alvo" data-bs-toggle="modal" data-bs-target="#descobrirTaxaModal"><i class="bi bi-calculator"></i></button></div></div>
                          </div>
                          <div class="col-md-3" id="containerDataOperacaoPrincipal">
                              <div id="dataOperacaoFieldContainer"><label for="data_operacao" class="form-label-strong">Data Base de Cálculo</label><?php $dataOperacaoDefault = date('Y-m-d'); ?><input type="date" class="form-control" id="data_operacao" name="data_operacao" value="<?php echo $dataOperacaoDefault; ?>" required></div>
                          </div>
                          <div class="col-md-3 d-none" id="containerTemGarantiaPrincipal">
                              <div id="temGarantiaFieldContainer"><label for="temGarantia" class="form-label-strong">Possui Garantia?</label><select id="temGarantia" name="tem_garantia" class="form-select"><option value="Nao" selected>Não</option><option value="Sim">Sim</option></select></div>
                          </div>
                      </div>
                  </div>
              </div>

              <!-- Card 3: Tributação & Pagamento (somente Antecipação) -->
              <div class="sim-card sim-card-tax" id="cardTributacao">
                  <div class="sim-card-head">
                      <span class="step-num">3</span>
                      <h2>Tributação &amp; Pagamento</h2>
                      <span class="head-meta">IOF e fluxo do dinheiro</span>
                  </div>
                  <div class="sim-card-body">
                      <div class="row g-3 align-items-end">
                          <div class="col-md-4" id="containerTipoPagamento"><label for="tipoPagamento" class="form-label-strong">Tipo de Pagamento</label><select id="tipoPagamento" name="tipoPagamento" class="form-select" required><option value="direto">Pagamento Direto (Devedor Notificado)</option><option value="escrow">Pagamento via Conta Escrow</option><option value="indireto">Pagamento Indireto (Repasse via Cedente)</option></select></div>
                          <div class="col-md-4" id="containerIncorreIOF"><label for="incorreIOF" class="form-label-strong">Você Incorre Custo IOF?</label><select id="incorreIOF" name="incorreIOF" class="form-select"><option value="Sim">Sim</option><option value="Nao" selected>Não</option></select></div>
                          <div class="col-md-4 cobrar-iof-wrapper d-none" id="containerCobrarIOF"><label for="cobrarIOF" class="form-label-strong">Cobrar IOF do Cliente?</label><select id="cobrarIOF" name="cobrarIOF" class="form-select"><option value="Sim">Sim (Taxa Extra)</option><option value="Nao">Não</option></select></div>
                      </div>
                  </div>
              </div>

              <!-- Card de Empréstimo (mantém id e display original toggleados pelo JS) -->
              <fieldset class="sim-card sim-card-loan p-0 border-0 mb-4" id="emprestimoParamsSection" style="display: none;">
              <div class="sim-card-head">
                  <span class="step-num"><i class="bi bi-cash-coin"></i></span>
                  <h2>Calculadora Flexível de Empréstimo</h2>
                  <span class="head-meta">Configuração das parcelas</span>
              </div>
              <div class="sim-card-body" style="background:#fafbfd;">
              
              <!-- Seletor de Modo de Cálculo -->
              <div class="row g-3 mb-3">
                  <div class="col-md-12">
                      <label class="form-label fw-bold">O que você deseja descobrir?</label>
                      <div class="btn-group w-100" role="group" aria-label="Modo de Cálculo">
                          <input type="radio" class="btn-check" name="modoCalculo" id="modoDescobrirParcela" value="parcela" autocomplete="off" checked>
                          <label class="btn btn-outline-secondary" for="modoDescobrirParcela">Descobrir Parcela</label>

                          <input type="radio" class="btn-check" name="modoCalculo" id="modoDescobrirTaxa" value="taxa" autocomplete="off">
                          <label class="btn btn-outline-secondary" for="modoDescobrirTaxa">Descobrir Taxa de Juros</label>

                          <input type="radio" class="btn-check" name="modoCalculo" id="modoDescobrirEmprestimo" value="emprestimo" autocomplete="off">
                          <label class="btn btn-outline-secondary" for="modoDescobrirEmprestimo">Descobrir Valor do Empréstimo</label>
                      </div>
                  </div>
              </div>

              <div class="row g-3 mb-3 align-items-end">
                  <div class="col-lg-4 col-md-6" id="containerTaxaEmprestimo"></div>
                  <div class="col-lg-4 col-md-3" id="containerDataOperacaoEmprestimo"></div>
                  <div class="col-lg-4 col-md-3" id="containerTemGarantia"></div>
              </div>
              <div class="row mb-3">
                  <div class="col-12">
                      <div class="calc-output-hidden-note d-none" id="campoCalculadoHint"></div>
                  </div>
              </div>

              <div class="row g-3 align-items-end">
                  <div class="col-lg col-md-6" id="containerValorEmprestimo">
                      <label for="valorEmprestimo" class="form-label">Valor do Empréstimo (R$)</label>
                      <input type="number" class="form-control" id="valorEmprestimo" name="valor_emprestimo" step="0.01" min="0.01" placeholder="10000.00">
                  </div>
                  <div class="col-lg col-md-6" id="containerValorParcela" style="display: none;">
                      <label for="valorParcela" class="form-label text-primary fw-bold">Valor da Parcela (R$)</label>
                      <input type="number" class="form-control border-primary" id="valorParcela" name="valor_parcela" step="0.01" min="0.01" placeholder="1000.00">
                  </div>
                  <div class="col-lg col-md-6">
                      <label for="frequenciaParcelas" class="form-label">Frequência</label>
                      <select id="frequenciaParcelas" class="form-select">
                          <option value="mensal" selected>Mensal (30 dias)</option>
                          <option value="quinzenal">Quinzenal (15 dias)</option>
                          <option value="semanal">Semanal (7 dias)</option>
                          <option value="pagamento_unico">Pagamento Único</option>
                      </select>
                  </div>
                  <div class="col-lg col-md-6">
                      <label for="quantidadeParcelas" class="form-label">Num. de Parcelas</label>
                      <input type="number" class="form-control" id="quantidadeParcelas" min="1" max="120" value="1">
                  </div>
                  <div class="col-lg col-md-6">
                      <label for="dataPrimeiroVencimento" class="form-label">1º Vencimento</label>
                      <input type="date" class="form-control" id="dataPrimeiroVencimento">
                      <div class="invalid-feedback" id="dataPrimeiroVencimentoFeedback">Deve ser depois da data da operação.</div>
                  </div>
              </div>
              <div class="row g-3 mt-2">
                  <div class="col-12" id="containerDescricaoGarantia" style="display: none;">
                      <label for="descricaoGarantia" class="form-label">Descrição da Garantia (anexe fotos/docs após registrar)</label>
                      <input type="text" class="form-control" id="descricaoGarantia" name="descricao_garantia" placeholder="Ex: Veículo placa ABC-1234, Imóvel...">
                  </div>
              </div>
              <div class="row g-3 mt-1">
                  <div class="col-md-12 text-end">
                      <button type="button" class="btn btn-outline-danger btn-sm me-2" id="btnLimparRascunho"><i class="bi bi-trash"></i> Limpar / Nova Simulação</button>
                      <button type="button" class="btn btn-primary" id="btnGerarParcelas"><i class="bi bi-gear-fill"></i> Gerar Parcelas</button>
                  </div>
              </div>

              <!-- O resumo do empréstimo agora aparece no painel sticky à direita (#resumoEmprestimoSection). -->
              </div>
              </fieldset>

              <!-- Card 4: Títulos a Descontar -->
              <div class="sim-card sim-card-titles" id="simStep4">
                  <div class="sim-card-head">
                      <span class="step-num">4</span>
                      <h2 id="legendTitulos">Títulos a Descontar</h2>
                      <span class="head-meta">Valores e datas</span>
                  </div>
                  <div class="sim-card-body p-0">
                      <div class="table-responsive">
                          <table class="table mb-0" id="titulosTable">
                              <thead>
                                  <tr>
                                      <th scope="col">Valor Original (R$)</th>
                                      <th scope="col">Data Vencimento</th>
                                      <th scope="col">Sacado (Devedor)</th>
                                      <th scope="col">Tipo Recebível</th>
                                      <th scope="col" class="text-end" id="colHeaderValorPago">Valor Líquido Pago (R$)</th>
                                      <th scope="col" class="text-center" style="width: 80px;">Dias Rest.</th>
                                      <th scope="col" style="width: 50px;">Ação</th>
                                  </tr>
                              </thead>
                              <tbody id="titulosBody">
                                  <tr>
                                      <td><input type="text" inputmode="decimal" name="titulo_valor[]" class="form-control valor-original text-end" placeholder="0,00" required></td>
                                      <td><input type="date" name="titulo_data[]" class="form-control data-vencimento" required></td>
                                      <td>
                                          <select name="titulo_sacado[]" class="form-select sacado-select">
                                              <option value="">-- Selecione Sacado --</option>
                                              <?php foreach ($sacados as $sacado): ?>
                                                  <option value="<?php echo htmlspecialchars($sacado['id']); ?>"><?php echo htmlspecialchars($sacado['nome']); ?></option>
                                              <?php endforeach; ?>
                                              <?php if (empty($sacados) && $erro_sacados): ?>
                                                  <option value="" disabled><?php echo htmlspecialchars($erro_sacados); ?></option>
                                              <?php elseif (empty($sacados)): ?>
                                                  <option value="" disabled>Nenhum sacado cadastrado</option>
                                              <?php endif; ?>
                                          </select>
                                      </td>
                                      <td>
                                          <select name="titulo_tipo[]" class="form-select tipo-recebivel-select">
                                              <option value="duplicata" selected>Duplicata</option>
                                              <option value="cheque">Cheque</option>
                                              <option value="nota_promissoria">Nota Promissória</option>
                                              <option value="boleto">Boleto</option>
                                              <option value="fatura">Fatura</option>
                                              <option value="nota_fiscal">Nota Fiscal</option>
                                              <option value="parcela_emprestimo">Parcela de Empréstimo</option>
                                              <option value="outros">Outros</option>
                                          </select>
                                      </td>
                                      <td class="valor-pago-cell">--</td>
                                      <td class="dias-restantes-cell"></td>
                                      <td></td>
                                  </tr>
                              </tbody>
                          </table>
                      </div>
                      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top">
                          <button type="button" class="btn btn-outline-success btn-sm" id="addTituloBtn"><i class="bi bi-plus-circle"></i> Adicionar Título</button>
                          <button type="button" class="btn btn-sm" id="encontroContasBtn" style="display: none; background-color: #d2691e; color: white; border: 1px solid #b8621a;">
                              <i class="bi bi-arrow-left-right"></i> Listar Recebíveis Indiretos
                          </button>
                      </div>
                      <div id="compensacaoInfo" class="px-3 pb-3" style="display: none;">
                          <div class="alert alert-warning d-flex justify-content-between align-items-start mb-0">
                              <div>
                                  <small>ID do Recebível: <span id="compensacaoRecebiveis"></span></small><br>
                                  <small>Valor Original: <span id="compensacaoValorOriginal">R$ 0,00</span></small><br>
                                  <strong>Compensação Aplicada: <span id="compensacaoValor">R$ 0,00</span></strong><br>
                                  <small>Saldo Remanescente: <span id="compensacaoSaldoRemanescente">R$ 0,00</span></small><br>
                                  <small>Valor Presente (Antecipado): <span id="compensacaoValorPresente" class="text-info fw-bold">R$ 0,00</span></small><br>
                                  <small>Crédito por Antecipação: <span id="compensacaoRemuneracao" class="text-success fw-bold">R$ 0,00</span></small><br>
                                  <small>Status: <span id="compensacaoStatus" class="badge bg-warning">Parcialmente Quitado</span></small>
                              </div>
                              <button type="button" class="btn btn-outline-danger btn-sm" id="removerCompensacaoBtn" title="Remover Compensação">
                                  <i class="bi bi-x-circle"></i> Remover
                              </button>
                          </div>
                      </div>
                  </div>
              </div>

              <!-- Anotações da operação (sempre visível, ambos os modos) -->
              <details class="sim-collapsible">
                  <summary><i class="bi bi-pencil-square"></i> Anotações da operação <span class="text-muted small ms-auto">opcional</span></summary>
                  <div class="details-body">
                      <textarea class="form-control" id="notas" name="notas" rows="3" placeholder="Detalhes da operação..."></textarea>
                  </div>
              </details>

              <!-- Anexos: Anexar Documentos (collapse) -->
              <details class="sim-collapsible" id="arquivosSection" style="display: none;">
                  <summary><i class="bi bi-paperclip"></i> Anexar Documentos (Garantias, Contratos, etc.) <span class="text-muted small ms-auto">opcional</span></summary>
                  <div class="details-body">
                      <div class="row">
                          <div class="col-md-8">
                              <label for="arquivos" class="form-label">Selecionar Arquivos</label>
                              <input type="file" class="form-control" id="arquivos" name="arquivos[]" multiple
                                     accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.txt">
                              <div class="form-text">
                                  Tipos aceitos: PDF, JPG, PNG, GIF, WebP, DOC, DOCX, XLS, XLSX, TXT<br>
                                  Tamanho máximo por arquivo: 10MB | Máximo: 20 arquivos por operação
                              </div>
                          </div>
                          <div class="col-md-4">
                              <label for="descricaoArquivos" class="form-label">Descrição (opcional)</label>
                              <textarea class="form-control" id="descricaoArquivos" rows="3"
                                        placeholder="Descrição dos documentos anexados..."></textarea>
                          </div>
                      </div>
                      <div id="arquivos-preview" class="mt-3" style="display: none;">
                          <h6>Arquivos Selecionados:</h6>
                          <div id="arquivos-list" class="list-group"></div>
                      </div>
                  </div>
              </details>

              <!-- Fluxo de Caixa (collapse) -->
              <details class="sim-collapsible">
                  <summary><i class="bi bi-bar-chart-line"></i> Fluxo de Caixa por Mês <span class="text-muted small ms-auto">após calcular</span></summary>
                  <div class="details-body">
                      <div class="chart-wrapper"><canvas id="fluxoCaixaChart"></canvas></div>
                  </div>
              </details>

              <!-- Template oculto para clonagem de linhas (mantido na coluna esquerda) -->
              <table style="display:none;">
                  <tr id="tituloTemplateRow">
                      <td><input type="text" inputmode="decimal" name="titulo_valor[]" class="form-control valor-original text-end" placeholder="0,00" required disabled></td>
                      <td><input type="date" name="titulo_data[]" class="form-control data-vencimento" required disabled></td>
                      <td>
                          <select name="titulo_sacado[]" class="form-select sacado-select" disabled>
                              <option value="">-- Selecione Sacado --</option>
                              <?php foreach ($sacados as $sacado): ?>
                                  <option value="<?php echo htmlspecialchars($sacado['id']); ?>"><?php echo htmlspecialchars($sacado['nome']); ?></option>
                              <?php endforeach; ?>
                              <?php if (empty($sacados) && $erro_sacados): ?>
                                  <option value="" disabled><?php echo htmlspecialchars($erro_sacados); ?></option>
                              <?php elseif (empty($sacados)): ?>
                                  <option value="" disabled>Nenhum sacado cadastrado</option>
                              <?php endif; ?>
                          </select>
                      </td>
                      <td>
                          <select name="titulo_tipo[]" class="form-select tipo-recebivel-select" disabled>
                              <option value="duplicata" selected>Duplicata</option>
                              <option value="cheque">Cheque</option>
                              <option value="nota_promissoria">Nota Promissória</option>
                              <option value="boleto">Boleto</option>
                              <option value="fatura">Fatura</option>
                              <option value="nota_fiscal">Nota Fiscal</option>
                              <option value="parcela_emprestimo">Parcela de Empréstimo</option>
                              <option value="outros">Outros</option>
                          </select>
                      </td>
                      <td class="valor-pago-cell">--</td>
                      <td class="dias-restantes-cell"></td>
                      <td><button type="button" class="btn btn-danger btn-sm remove-row-btn" title="Remover Título" disabled><i class="bi bi-trash"></i></button></td>
                  </tr>
              </table>

          </div>
          <!-- /col-xl-8 -->

          <!-- ============ COLUNA DIREITA: painel sticky ============ -->
          <div class="col-xl-4">
              <div class="sim-summary-panel" id="summaryPanel">

                  <div class="sim-summary-head">
                      <h3>Resumo da Simulação</h3>
                      <span class="badge bg-light text-primary" id="summaryModeBadge">Antecipação</span>
                  </div>

                  <!-- Resumo da simulação de Empréstimo (controlado por JS via #resumoEmprestimoSection) -->
                  <div id="resumoEmprestimoSection" class="sim-loan-resumo" style="display: none;">
                      <div class="sim-loan-resumo-head">
                          <span class="badge bg-light text-primary resumo-modo-badge" id="resumoModoBadge">Modo: Descobrir Parcela</span>
                          <div class="sim-loan-resumo-desc small text-muted mt-1" id="resumoModoDescricao">O resultado principal aparece destacado conforme o modo selecionado.</div>
                      </div>
                      <div class="sim-loan-resumo-grid">
                          <div class="resumo-item-card" id="resumoItemPv">
                              <small class="text-muted d-block">Empréstimo</small>
                              <span class="fw-bold" id="resumoPv">--</span>
                          </div>
                          <div class="resumo-item-card" id="resumoItemPmt">
                              <small class="text-muted d-block">Parcela</small>
                              <span class="fw-bold text-primary" id="resumoPmt">--</span>
                          </div>
                          <div class="resumo-item-card" id="resumoItemPrazo">
                              <small class="text-muted d-block">Prazo</small>
                              <span class="fw-bold" id="resumoPrazo">--</span>
                          </div>
                          <div class="resumo-item-card" id="resumoItemTaxa">
                              <small class="text-muted d-block">Taxa a.m.</small>
                              <span class="fw-bold text-danger" id="resumoTaxa">--</span>
                          </div>
                          <div class="resumo-item-card" id="resumoItemTotal">
                              <small class="text-muted d-block">Total a Pagar</small>
                              <span class="fw-bold" id="resumoTotal">--</span>
                          </div>
                          <div class="resumo-item-card" id="resumoItemLucro">
                              <small class="text-muted d-block">Lucro</small>
                              <span class="fw-bold text-success" id="resumoLucro">--</span>
                          </div>
                      </div>
                  </div>

                  <div class="sim-summary-hero">
                      <div class="hero-label">Total líquido pago</div>
                      <div class="hero-value" id="resTotalLiquido">--</div>
                      <div class="hero-sub">Valor a entregar ao cliente</div>
                  </div>

                  <div class="sim-summary-grid">
                      <div class="sim-cell cell-profit cell-span">
                          <div class="cell-label"><i class="bi bi-arrow-up-right"></i> Lucro líquido</div>
                          <div class="cell-value" id="resTotalLucro">--</div>
                      </div>
                      <div class="sim-cell cell-profit">
                          <div class="cell-label">Margem (%)</div>
                          <div class="cell-value" id="resMargemTotal">--</div>
                      </div>
                      <div class="sim-cell cell-profit">
                          <div class="cell-label">Retorno mensal</div>
                          <div class="cell-value" id="resRetornoMensal">--</div>
                      </div>
                      <div class="sim-cell">
                          <div class="cell-label">Total Original</div>
                          <div class="cell-value" id="resTotalOriginal">--</div>
                      </div>
                      <div class="sim-cell">
                          <div class="cell-label">Vl. Presente</div>
                          <div class="cell-value" id="resTotalPresente">--</div>
                      </div>
                      <div class="sim-cell">
                          <div class="cell-label">Média Dias</div>
                          <div class="cell-value" id="resMediaDias">--</div>
                      </div>
                      <div class="sim-cell cell-warn" id="containerResTotalIOF">
                          <div class="cell-label"><i class="bi bi-receipt"></i> IOF Calc.</div>
                          <div class="cell-value" id="resTotalIOF">--</div>
                      </div>

                      <div class="sim-cell cell-warn cell-span" id="compensacaoRow" style="display: none;">
                          <div class="row g-2 m-0">
                              <div class="col-6 p-0 pe-2">
                                  <div class="cell-label">Antecipação</div>
                                  <div class="cell-value" id="resAntecipacao">--</div>
                              </div>
                              <div class="col-6 p-0 ps-2 border-start">
                                  <div class="cell-label" style="color: var(--sim-profit);">Crédito Antecipação</div>
                                  <div class="cell-value" id="resCreditoAntecipacao" style="color: var(--sim-profit);">--</div>
                              </div>
                          </div>
                      </div>
                  </div>

                  <div id="error-message" class="alert alert-danger d-none mx-3" role="alert"></div>

                  <div class="sim-summary-actions">
                      <button type="button" id="calculateBtn" class="btn btn-primary btn-lg"><i class="bi bi-calculator"></i> Calcular Totais</button>
                      <div class="btn-row">
                          <button type="button" id="exportPdfClienteBtn" class="btn btn-outline-secondary btn-sm" disabled><i class="bi bi-file-earmark-person"></i> Gerar PDF Simulação Cliente</button>
                      </div>

                      <hr class="sim-actions-divider">

                      <button type="button" id="registerBtn" class="btn btn-success" disabled><i class="bi bi-check-lg"></i> Registrar Operação</button>
                      <div class="form-check mt-1" id="containerNotificarSacado">
                          <input class="form-check-input" type="checkbox" id="notificar_sacado" name="notificar_sacado" checked>
                          <label class="form-check-label small" for="notificar_sacado">
                              <i class="bi bi-envelope"></i> Notificar Sacado(s) por E-mail após o registro
                          </label>
                      </div>
                      <div id="register-feedback" class="text-center small mt-1" style="min-height: 1.2em;"></div>
                  </div>
              </div>
          </div>
          <!-- /col-xl-4 -->

        </div>
        <!-- /.row -->

        <input type="hidden" name="chartImageData" id="chartImageData">
        <input type="hidden" name="compensacao_data" id="compensacaoData">
      </form>
  </div>

  <!-- Modal Descobrir Taxa -->
  <div class="modal fade" id="descobrirTaxaModal" tabindex="-1" aria-labelledby="descobrirTaxaModalLabel" aria-hidden="true">
      <div class="modal-dialog">
          <div class="modal-content">
              <div class="modal-header">
                  <h5 class="modal-title" id="descobrirTaxaModalLabel">Descobrir Taxa por Valor Líquido</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                  <p>Insira o valor líquido total que você deseja pagar ao cliente para que o sistema calcule a taxa de desconto correspondente.</p>
                  <div class="mb-3">
                      <label for="valorAlvoInput" class="form-label">Valor Líquido Desejado (R$)</label>
                      <input type="text" class="form-control" id="valorAlvoInput" placeholder="Ex: R$ 22.000,00" data-mask="currency">
                  </div>
                  <div id="descobrirTaxaError" class="alert alert-danger d-none"></div>
              </div>
              <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                  <button type="button" class="btn btn-primary" id="calcularTaxaAlvoBtn">Calcular Taxa</button>
              </div>
          </div>
      </div>
  </div>

  <!-- Modal de Encontro de Contas -->
  <div class="modal fade" id="encontroContasModal" tabindex="-1" aria-labelledby="encontroContasModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg">
          <div class="modal-content">
              <div class="modal-header">
                  <h5 class="modal-title" id="encontroContasModalLabel">Encontro de Contas - Recebíveis Indiretos</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                  <!-- Card 1: Parâmetros de Cálculo -->
                  <div class="card mb-4">
                      <div class="card-header bg-primary text-white">
                          <h6 class="mb-0"><i class="bi bi-gear"></i> Parâmetros de Cálculo</h6>
                      </div>
                      <div class="card-body">
                          <div class="row">
                              <div class="col-md-3">
                                  <label for="taxaAntecipacao" class="form-label">Taxa de Antecipação (% a.m.)</label>
                                  <input type="number" class="form-control" id="taxaAntecipacao" step="0.25" min="0" value="2.00" placeholder="2.00">
                              </div>
                              <div class="col-md-3">
                                  <label for="valorCustomizadoCompensacao" class="form-label">Valor Customizado (R$)</label>
                                  <input type="text" class="form-control" id="valorCustomizadoCompensacao" placeholder="Ex: R$ 50.000,00" data-mask="currency">
                                  <small class="form-text text-muted">Deixe vazio para usar valor total</small>
                              </div>
                              <div class="col-md-3">
                                  <label class="form-label">Data Base de Cálculo</label>
                                  <div class="form-control-plaintext fw-bold text-info" id="dataBaseCalculoModal"><?php echo date('d/m/Y'); ?></div>
                                  <small class="form-text text-muted">Data base para cálculos</small>
                              </div>
                              <div class="col-md-3">
                                  <label class="form-label">Valor Presente (Antecipado)</label>
                                  <div class="form-control-plaintext fw-bold text-success" id="valorPresenteAntecipado">R$ 0,00</div>
                                  <small class="form-text text-muted">Valor que você receberá hoje</small>
                              </div>
                          </div>
                      </div>
                  </div>

                  <!-- Card 2: Recebíveis Disponíveis -->
                  <div class="card mb-4">
                      <div class="card-header bg-info text-white">
                          <h6 class="mb-0"><i class="bi bi-list-check"></i> Recebíveis Disponíveis</h6>
                      </div>
                      <div class="card-body">
                          <div id="recebiveisLoading" class="text-center">
                              <div class="spinner-border" role="status">
                                  <span class="visually-hidden">Carregando...</span>
                              </div>
                          </div>
                          
                          <div id="recebiveisContainer" style="display: none;">
                              <div class="table-responsive">
                                  <table class="table table-striped">
                                      <thead>
                                          <tr>
                                              <th>Selecionar</th>
                                              <th>ID</th>
                                              <th>Valor Nominal</th>
                                              <th>Data Vencimento</th>
                                              <th>Dias para Venc.</th>
                                              <th>Valor Presente</th>
                                          </tr>
                                      </thead>
                                      <tbody id="recebiveisTableBody">
                                          <!-- Recebíveis serão carregados aqui -->
                                      </tbody>
                                  </table>
                              </div>
                          </div>
                          
                          <div id="recebiveisError" class="alert alert-danger" style="display: none;"></div>
                      </div>
                  </div>

                  <!-- Card 3: Totais da Compensação -->
                  <div class="card mb-3">
                      <div class="card-header bg-success text-white">
                          <h6 class="mb-0"><i class="bi bi-calculator"></i> Totais da Compensação</h6>
                      </div>
                      <div class="card-body">
                          <div class="row">
                              <div class="col-md-6">
                                  <label class="form-label">Total da Compensação</label>
                                  <div class="form-control-plaintext fw-bold text-primary fs-5" id="valorTotalCompensacao">R$ 0,00</div>
                                  <small class="form-text text-muted">Valor nominal do recebível</small>
                              </div>
                              <div class="col-md-6">
                                  <label class="form-label">Crédito por Antecipação</label>
                                  <div class="form-control-plaintext fw-bold text-warning fs-5" id="creditoAntecipacaoModal">R$ 0,00</div>
                                  <small class="form-text text-muted">Custo da antecipação</small>
                              </div>
                          </div>
                      </div>
                  </div>

                  <div id="validationError" class="alert alert-warning" style="display: none;">
                      O valor da compensação excede o valor líquido da nova operação. Não é possível prosseguir.
                  </div>
              </div>
              <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                  <button type="button" class="btn btn-primary" id="aprovarCompensacaoBtn" disabled>Aprovar Compensação</button>
              </div>
          </div>
      </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?php $financeMathVersion = file_exists(__DIR__ . '/financeMath.js') ? (string) filemtime(__DIR__ . '/financeMath.js') : 'missing'; ?>
  <script>
      window.__financeMathLoadFailed = false;
  </script>
  <script src="financeMath.js?v=<?= rawurlencode($financeMathVersion) ?>" onerror="window.__financeMathLoadFailed = true;"></script>
  <script>
      // --- Funções para formatar moeda em JavaScript (MOVIDA PARA CIMA) ---
      function formatCurrencyJS(value) {
          if (typeof value === 'number') {
              return 'R$ ' + value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          }
          return '--';
      }

      // --- Helpers BR p/ inputs de valor (suporta "1.000,50" e "1000.50") ---
      function parseValorBR(s) {
          if (typeof s === 'number') return s;
          if (s == null || s === '') return 0;
          let str = String(s).replace(/[R$\s]/g, '');
          if (str.indexOf(',') !== -1) {
              str = str.replace(/\./g, '').replace(',', '.');
          }
          const n = parseFloat(str);
          return isNaN(n) ? 0 : n;
      }
      function formatValorBR(value) {
          const n = typeof value === 'number' ? value : parseValorBR(value);
          return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }
      function applyMoneyMaskBR(input) {
          let raw = (input.value || '').replace(/\D/g, '');
          if (raw === '') { input.value = ''; return; }
          raw = raw.replace(/^0+/, '') || '0';
          while (raw.length < 3) raw = '0' + raw;
          const cents = raw.slice(-2);
          let intPart = raw.slice(0, -2);
          intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
          input.value = intPart + ',' + cents;
      }
      function attachMoneyMask(input) {
          if (!input || input.dataset.moneyMask === '1') return;
          input.dataset.moneyMask = '1';
          input.addEventListener('input', () => applyMoneyMaskBR(input));
          input.addEventListener('blur', () => { if (input.value) applyMoneyMaskBR(input); });
      }

      // --- DOM Elements ---
      const tipoAntecipacaoRadio = document.getElementById('tipoAntecipacao');
      const tipoEmprestimoRadio = document.getElementById('tipoEmprestimo');
      const emprestimoParamsSection = document.getElementById('emprestimoParamsSection');
      const form = document.getElementById('calculationForm');
      const cedenteSelect = document.getElementById('cedente');
      const tipoPagamentoSelect = document.getElementById('tipoPagamento');
      const taxaMensalInput = document.getElementById('taxaMensal');
      const taxaDecrementBtn = document.getElementById('taxaDecrementBtn');
      const taxaIncrementBtn = document.getElementById('taxaIncrementBtn');
      const dataOperacaoInput = document.getElementById('data_operacao');
      const incorreIOFSelect = document.getElementById('incorreIOF');
      const cobrarIOFSelect = document.getElementById('cobrarIOF');
      const cobrarIofWrapper = document.querySelector('.cobrar-iof-wrapper');
      const notasInput = document.getElementById('notas');
      const titulosBody = document.getElementById('titulosBody');
      const addTituloBtn = document.getElementById('addTituloBtn');
      const tituloTemplateRow = document.getElementById('tituloTemplateRow');
      const calculateBtn = document.getElementById('calculateBtn');
      const registerBtn = document.getElementById('registerBtn');
      const resMediaDias = document.getElementById('resMediaDias');
      const resTotalOriginal = document.getElementById('resTotalOriginal');
      const resTotalPresente = document.getElementById('resTotalPresente');
      const resTotalIOF = document.getElementById('resTotalIOF');
      const resTotalLiquido = document.getElementById('resTotalLiquido');
      const resTotalLucro = document.getElementById('resTotalLucro');
      const resMargemTotal = document.getElementById('resMargemTotal');
      const resRetornoMensal = document.getElementById('resRetornoMensal');
      const resAntecipacao = document.getElementById('resAntecipacao');
      const resCreditoAntecipacao = document.getElementById('resCreditoAntecipacao');
      const compensacaoRow = document.getElementById('compensacaoRow');
      const errorMessageDiv = document.getElementById('error-message');
      const chartCanvas = document.getElementById('fluxoCaixaChart');
      const chartImageDataInput = document.getElementById('chartImageData');
      const registerFeedback = document.getElementById('register-feedback');
      const exportPdfClienteBtn = document.getElementById('exportPdfClienteBtn');

      let myFluxoChart = null; let calculationResults = null; let lastInputDataForRegister = null;

      // --- Elementos do Encontro de Contas ---
      const encontroContasBtn = document.getElementById('encontroContasBtn');
      let encontroContasModal; // Será inicializado no DOMContentLoaded
      const taxaAntecipacaoInput = document.getElementById('taxaAntecipacao');
      const valorCustomizadoInput = document.getElementById('valorCustomizadoCompensacao');
      const valorTotalCompensacaoSpan = document.getElementById('valorTotalCompensacao');
      const valorPresenteAntecipado = document.getElementById('valorPresenteAntecipado');
      const recebiveisLoading = document.getElementById('recebiveisLoading');
      const recebiveisContainer = document.getElementById('recebiveisContainer');
      const recebiveisTableBody = document.getElementById('recebiveisTableBody');
      const recebiveisError = document.getElementById('recebiveisError');
      const validationError = document.getElementById('validationError');
      const aprovarCompensacaoBtn = document.getElementById('aprovarCompensacaoBtn');
      const compensacaoInfo = document.getElementById('compensacaoInfo');
      const compensacaoValor = document.getElementById('compensacaoValor');
      const compensacaoRecebiveis = document.getElementById('compensacaoRecebiveis');
      const compensacaoValorOriginal = document.getElementById('compensacaoValorOriginal');
      const compensacaoSaldoRemanescente = document.getElementById('compensacaoSaldoRemanescente');
      const compensacaoValorPresente = document.getElementById('compensacaoValorPresente');
      const compensacaoRemuneracao = document.getElementById('compensacaoRemuneracao');
      const compensacaoStatus = document.getElementById('compensacaoStatus');
      const removerCompensacaoBtn = document.getElementById('removerCompensacaoBtn');
      const compensacaoDataInput = document.getElementById('compensacaoData');

      let recebiveisDisponiveis = [];
      let compensacaoAtiva = null;

      // --- Elementos do Modo Flexível ---
      const radiosModoCalculo = document.querySelectorAll('input[name="modoCalculo"]');
      const containerValorEmprestimo = document.getElementById('containerValorEmprestimo');
      const valorEmprestimoInput = document.getElementById('valorEmprestimo');
      const containerValorParcela = document.getElementById('containerValorParcela');
      const valorParcelaInput = document.getElementById('valorParcela');
      const quantidadeParcelasInput = document.getElementById('quantidadeParcelas');
      const frequenciaParcelasSelect = document.getElementById('frequenciaParcelas');
      const dataPrimeiroVencimentoInput = document.getElementById('dataPrimeiroVencimento');
      const temGarantiaSelect = document.getElementById('temGarantia');
      const btnAbrirModalTaxa = document.getElementById('btnAbrirModalTaxa');
      const containerTemGarantia = document.getElementById('containerTemGarantia');
      const temGarantiaFieldContainer = document.getElementById('temGarantiaFieldContainer');
      const containerTemGarantiaPrincipal = document.getElementById('containerTemGarantiaPrincipal');
      const containerDescricaoGarantia = document.getElementById('containerDescricaoGarantia');
      const descricaoGarantiaInput = document.getElementById('descricaoGarantia');
      const taxaFieldContainer = document.getElementById('taxaFieldContainer');
      const taxaFieldOriginalParent = taxaFieldContainer.parentElement;
      const containerTaxaPrincipal = document.getElementById('containerTaxaPrincipal');
      const containerDataOperacaoPrincipal = document.getElementById('containerDataOperacaoPrincipal');
      const dataOperacaoFieldContainer = document.getElementById('dataOperacaoFieldContainer');
      const dataOperacaoFieldOriginalParent = dataOperacaoFieldContainer.parentElement;
      const temGarantiaFieldOriginalParent = temGarantiaFieldContainer.parentElement;
      const containerTaxaEmprestimo = document.getElementById('containerTaxaEmprestimo');
      const containerDataOperacaoEmprestimo = document.getElementById('containerDataOperacaoEmprestimo');
      const campoCalculadoHint = document.getElementById('campoCalculadoHint');
      const resumoModoBadge = document.getElementById('resumoModoBadge');
      const resumoModoDescricao = document.getElementById('resumoModoDescricao');
      const resumoItemPv = document.getElementById('resumoItemPv');
      const resumoItemPmt = document.getElementById('resumoItemPmt');
      const resumoItemPrazo = document.getElementById('resumoItemPrazo');
      const resumoItemTaxa = document.getElementById('resumoItemTaxa');
      const resumoItemTotal = document.getElementById('resumoItemTotal');
      const resumoItemLucro = document.getElementById('resumoItemLucro');
      const resumoPvValue = document.getElementById('resumoPv');
      const resumoPmtValue = document.getElementById('resumoPmt');
      const resumoPrazoValue = document.getElementById('resumoPrazo');
      const resumoTaxaValue = document.getElementById('resumoTaxa');
      const resumoTotalValue = document.getElementById('resumoTotal');
      const resumoLucroValue = document.getElementById('resumoLucro');
      const btnGerarParcelas = document.getElementById('btnGerarParcelas');

      // Card de Resumo do Empréstimo
      const resumoEmprestimoSection = document.getElementById('resumoEmprestimoSection');
      if (!resumoEmprestimoSection) {
          // Criaremos dinamicamente se não existir, mas o ideal é injetar no HTML
      }

      function getFinanceMathApi() {
          const namespaceApi = window.FinanceMath && typeof window.FinanceMath === 'object'
              ? window.FinanceMath
              : null;
          const api = {
              calculatePMTFromDays: (namespaceApi && namespaceApi.calculatePMTFromDays) || window.calculatePMTFromDays,
              calculatePVFromDays: (namespaceApi && namespaceApi.calculatePVFromDays) || window.calculatePVFromDays,
              calculateRATEFromDays: (namespaceApi && namespaceApi.calculateRATEFromDays) || window.calculateRATEFromDays,
          };

          return Object.values(api).every(fn => typeof fn === 'function') ? api : null;
      }

      function showFinanceMathDependencyError() {
          const motivo = window.__financeMathLoadFailed
              ? 'O arquivo de matematica financeira nao carregou corretamente.'
              : 'As funcoes de matematica financeira nao estao disponiveis ou estao desatualizadas.';
          errorMessageDiv.textContent = `${motivo} Atualize a pagina para sincronizar os scripts e tente novamente.`;
          errorMessageDiv.classList.remove('d-none');
      }

      function clearFinanceMathDependencyError() {
          const currentMessage = errorMessageDiv.textContent || '';
          if (currentMessage.includes('matematica financeira') || currentMessage.includes('sincronizar os scripts')) {
              errorMessageDiv.textContent = '';
              errorMessageDiv.classList.add('d-none');
          }
      }

      function updateFinanceMathDependentUI() {
          const financeMathAvailable = !!getFinanceMathApi();
          if (btnGerarParcelas) {
              btnGerarParcelas.disabled = tipoEmprestimoRadio.checked && !financeMathAvailable;
              btnGerarParcelas.title = financeMathAvailable
                  ? ''
                  : 'Atualize a pagina para carregar a matematica financeira.';
          }
      }

      function clearCalculatedFieldForMode(modo) {
          if (modo === 'parcela') {
              valorParcelaInput.value = '';
          } else if (modo === 'taxa') {
              taxaMensalInput.value = '';
          } else if (modo === 'emprestimo') {
              valorEmprestimoInput.value = '';
          }
      }

      function parseLocalDate(dateStr) {
          if (!dateStr) return null;
          const date = new Date(`${dateStr}T00:00:00`);
          return Number.isNaN(date.getTime()) ? null : date;
      }

      function diffDays(startDate, endDate) {
          return Math.round((endDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24));
      }

      function buildEmprestimoSchedule(dataBaseStr, primeiroVencimentoStr, quantidade, frequencia) {
          const dataBase = parseLocalDate(dataBaseStr);
          const primeiroVencimento = parseLocalDate(primeiroVencimentoStr);
          const totalParcelas = frequencia === 'pagamento_unico' ? 1 : quantidade;

          if (!dataBase || !primeiroVencimento || !totalParcelas || totalParcelas <= 0) {
              return { days: [], dates: [] };
          }

          const dates = [];
          const days = [];
          let dataAtual = new Date(primeiroVencimento.getTime());

          for (let step = 0; step < totalParcelas; step++) {
              dates.push(new Date(dataAtual.getTime()));
              days.push(diffDays(dataBase, dataAtual));

              if (frequencia === 'mensal') {
                  dataAtual.setMonth(dataAtual.getMonth() + 1);
              } else if (frequencia === 'quinzenal') {
                  dataAtual.setDate(dataAtual.getDate() + 15);
              } else if (frequencia === 'semanal') {
                  dataAtual.setDate(dataAtual.getDate() + 7);
              }
          }

          return { days, dates };
      }

      function syncQuantidadeParcelasComFrequencia() {
          if (frequenciaParcelasSelect.value === 'pagamento_unico') {
              quantidadeParcelasInput.value = '1';
              quantidadeParcelasInput.disabled = true;
              quantidadeParcelasInput.classList.add('bg-light');
          } else {
              quantidadeParcelasInput.disabled = false;
              quantidadeParcelasInput.classList.remove('bg-light');
              if (!quantidadeParcelasInput.value || parseInt(quantidadeParcelasInput.value, 10) < 1) {
                  quantidadeParcelasInput.value = '1';
              }
          }
      }

      function posicionarCamposLayoutEmprestimo() {
          if (tipoEmprestimoRadio.checked) {
              containerTaxaPrincipal.classList.add('d-none');
              containerDataOperacaoPrincipal.classList.add('d-none');
              containerTaxaEmprestimo.appendChild(taxaFieldContainer);
              containerDataOperacaoEmprestimo.appendChild(dataOperacaoFieldContainer);
              containerTemGarantia.appendChild(temGarantiaFieldContainer);
          } else {
              containerTaxaPrincipal.classList.remove('d-none');
              containerDataOperacaoPrincipal.classList.remove('d-none');
              taxaFieldOriginalParent.appendChild(taxaFieldContainer);
              dataOperacaoFieldOriginalParent.appendChild(dataOperacaoFieldContainer);
              temGarantiaFieldOriginalParent.appendChild(temGarantiaFieldContainer);
          }
      }

      function syncGarantiaVisibility() {
          const mostrarGarantia = tipoEmprestimoRadio.checked;
          containerTemGarantia.classList.toggle('d-none', !mostrarGarantia);
          containerTemGarantiaPrincipal.classList.add('d-none');

          if (!mostrarGarantia) {
              temGarantiaSelect.value = 'Nao';
          }

          const possuiGarantia = mostrarGarantia && temGarantiaSelect.value === 'Sim';
          containerDescricaoGarantia.style.display = possuiGarantia ? 'block' : 'none';
          descricaoGarantiaInput.required = possuiGarantia;

          if (!possuiGarantia) {
              descricaoGarantiaInput.value = '';
          }
      }

      function resetVisualModoFlexivel() {
          taxaFieldContainer.classList.remove('calc-output-compact');
          containerValorEmprestimo.style.display = 'block';
          campoCalculadoHint.classList.add('d-none');
          campoCalculadoHint.textContent = '';
          [resumoItemPv, resumoItemPmt, resumoItemPrazo, resumoItemTaxa, resumoItemTotal, resumoItemLucro].forEach(item => {
              if (item) item.classList.remove('is-active');
          });
      }

      function atualizarResumoPorModo(modo) {
          const configuracoesModo = {
              parcela: {
                  badge: 'Modo: Descobrir Parcela',
                  descricao: 'A parcela calculada automaticamente fica em destaque no resumo.',
                  destaque: resumoItemPmt
              },
              taxa: {
                  badge: 'Modo: Descobrir Taxa',
                  descricao: 'A taxa calculada automaticamente fica em destaque no resumo.',
                  destaque: resumoItemTaxa
              },
              emprestimo: {
                  badge: 'Modo: Descobrir Empréstimo',
                  descricao: 'O valor do empréstimo calculado automaticamente fica em destaque no resumo.',
                  destaque: resumoItemPv
              }
          };

          const config = configuracoesModo[modo] || configuracoesModo.parcela;
          if (resumoModoBadge) resumoModoBadge.textContent = config.badge;
          if (resumoModoDescricao) resumoModoDescricao.textContent = config.descricao;

          [resumoItemPv, resumoItemPmt, resumoItemPrazo, resumoItemTaxa, resumoItemTotal, resumoItemLucro].forEach(item => {
              if (item) item.classList.toggle('is-active', item === config.destaque);
          });
      }

      function getResumoFrequenciaLabel(freq) {
          if (freq === 'mensal') return '/m';
          if (freq === 'quinzenal') return '/15d';
          if (freq === 'semanal') return '/sem';
          return '/unico';
      }

      function getQuantidadeParcelasResumo(freq) {
          if (freq === 'pagamento_unico') return 1;
          return parseInt(quantidadeParcelasInput.value, 10) || 0;
      }

      function atualizarCampoCalculadoSuperior(modo) {
          taxaFieldContainer.classList.remove('calc-output-compact');
          campoCalculadoHint.classList.add('d-none');
          campoCalculadoHint.textContent = '';
          containerTaxaEmprestimo.style.display = 'block';
          containerValorEmprestimo.style.display = modo === 'emprestimo' ? 'none' : 'block';

          if (modo === 'parcela') {
              campoCalculadoHint.textContent = 'A parcela calculada automaticamente aparece destacada no resumo abaixo.';
              campoCalculadoHint.classList.remove('d-none');
          } else if (modo === 'taxa') {
              containerTaxaEmprestimo.style.display = 'none';
              campoCalculadoHint.textContent = 'A taxa calculada automaticamente foi ocultada no topo e destacada no resumo abaixo.';
              campoCalculadoHint.classList.remove('d-none');
          } else if (modo === 'emprestimo') {
              campoCalculadoHint.textContent = 'O campo superior do valor do empréstimo foi ocultado e o resultado calculado aparece destacado no resumo abaixo.';
              campoCalculadoHint.classList.remove('d-none');
          }
      }

      function updateModoFlexivel() {
          const modo = document.querySelector('input[name="modoCalculo"]:checked').value;
          
          // Reset styles
          valorEmprestimoInput.readOnly = false;
          valorEmprestimoInput.classList.remove('bg-light', 'text-success', 'fw-bold');
          valorParcelaInput.readOnly = false;
          valorParcelaInput.classList.remove('bg-light', 'text-success', 'fw-bold');
          taxaMensalInput.readOnly = false;
          taxaMensalInput.classList.remove('bg-light', 'text-success', 'fw-bold');
          taxaDecrementBtn.disabled = false;
          taxaIncrementBtn.disabled = false;
          btnAbrirModalTaxa.disabled = false;

          if (modo === 'parcela') {
              containerValorEmprestimo.style.display = 'block';
              containerValorParcela.style.display = 'none';
          } else if (modo === 'taxa') {
              containerValorEmprestimo.style.display = 'block';
              containerValorParcela.style.display = 'block';
              
              taxaMensalInput.readOnly = true;
              taxaMensalInput.classList.add('bg-light', 'text-success', 'fw-bold');
              taxaMensalInput.placeholder = "Calculada auto.";
              taxaDecrementBtn.disabled = true;
              taxaIncrementBtn.disabled = true;
              btnAbrirModalTaxa.disabled = true;
          } else if (modo === 'emprestimo') {
              containerValorEmprestimo.style.display = 'block';
              containerValorParcela.style.display = 'block';
              
              valorEmprestimoInput.readOnly = true;
              valorEmprestimoInput.classList.add('bg-light', 'text-success', 'fw-bold');
              valorEmprestimoInput.placeholder = "Calculado auto.";
          }

          atualizarResumoPorModo(modo);
          atualizarCampoCalculadoSuperior(modo);
          
          calcularValoresFlexiveis();
          salvarRascunho();
      }

      radiosModoCalculo.forEach(r => r.addEventListener('change', updateModoFlexivel));

      function setModoCalculoError(message) {
          if (!campoCalculadoHint) return;
          campoCalculadoHint.textContent = message;
          campoCalculadoHint.classList.remove('d-none', 'text-muted');
          campoCalculadoHint.classList.add('text-danger', 'fw-bold');
          taxaMensalInput.classList.add('is-invalid');
          valorEmprestimoInput.classList.add('is-invalid');
          valorParcelaInput.classList.add('is-invalid');
      }

      function clearModoCalculoError() {
          if (campoCalculadoHint) {
              campoCalculadoHint.classList.remove('text-danger', 'fw-bold');
          }
          taxaMensalInput.classList.remove('is-invalid');
          valorEmprestimoInput.classList.remove('is-invalid');
          valorParcelaInput.classList.remove('is-invalid');
      }

      function calcularValoresFlexiveis() {
          const modo = document.querySelector('input[name="modoCalculo"]:checked').value;
          const pv = parseFloat(valorEmprestimoInput.value) || 0;
          const pmt = parseFloat(valorParcelaInput.value) || 0;
          const nper = frequenciaParcelasSelect.value === 'pagamento_unico'
              ? 1
              : (parseInt(quantidadeParcelasInput.value, 10) || 1);
          let taxa = parseFloat(taxaMensalInput.value) / 100 || 0;
          const freq = frequenciaParcelasSelect.value;
          const schedule = buildEmprestimoSchedule(dataOperacaoInput.value, dataPrimeiroVencimentoInput.value, nper, freq);
          const scheduleValido = schedule.days.length === nper && schedule.days.every(days => days >= 0);
          const primeiroVencDia = schedule.days[0];
          const primeiroVencimentoInvalido =
              dataPrimeiroVencimentoInput.value &&
              dataOperacaoInput.value &&
              Number.isFinite(primeiroVencDia) &&
              primeiroVencDia <= 0;
          const precisaFinanceMath =
              (modo === 'parcela' && pv > 0 && nper > 0 && scheduleValido) ||
              (modo === 'taxa' && pv > 0 && pmt > 0 && nper > 0 && scheduleValido) ||
              (modo === 'emprestimo' && pmt > 0 && nper > 0 && scheduleValido);

          clearModoCalculoError();
          dataPrimeiroVencimentoInput.classList.toggle('is-invalid', !!primeiroVencimentoInvalido);

          if (primeiroVencimentoInvalido) {
              clearCalculatedFieldForMode(modo);
              setModoCalculoError('1º vencimento deve ser posterior à data da operação. Vencimento na mesma data inflaciona a 1ª parcela.');
              atualizarCardResumoEmprestimo();
              return;
          }

          if (precisaFinanceMath) {
              const financeMath = getFinanceMathApi();
              if (!financeMath) {
                  clearCalculatedFieldForMode(modo);
                  updateFinanceMathDependentUI();
                  showFinanceMathDependencyError();
                  atualizarCardResumoEmprestimo();
                  return;
              }

              clearFinanceMathDependencyError();
              updateFinanceMathDependentUI();

              if (modo === 'parcela') {
                  const calcPmt = financeMath.calculatePMTFromDays(taxa, schedule.days, pv);
                  valorParcelaInput.value = calcPmt.toFixed(2);
              } else if (modo === 'taxa') {
                  // Combinação impossível: parcelas que somadas dão menos que o empréstimo
                  // não admitem taxa positiva. Avisar antes de chamar Newton-Raphson.
                  if (pmt * nper <= pv) {
                      taxaMensalInput.value = '';
                      setModoCalculoError(
                          'Soma das parcelas (' + formatCurrencyJS(pmt * nper) +
                          ') deve ser maior que o empréstimo (' + formatCurrencyJS(pv) +
                          ') para calcular uma taxa positiva.'
                      );
                  } else {
                      const calcI = financeMath.calculateRATEFromDays(schedule.days, pmt, pv, 0.05);
                      if (calcI !== null) {
                          taxaMensalInput.value = (calcI * 100).toFixed(4);
                      } else {
                          taxaMensalInput.value = '';
                          setModoCalculoError('Não foi possível calcular a taxa para esses valores. Revise empréstimo, parcela e prazo.');
                      }
                  }
              } else if (modo === 'emprestimo') {
                  const calcPv = financeMath.calculatePVFromDays(taxa, schedule.days, pmt);
                  valorEmprestimoInput.value = calcPv.toFixed(2);
              }
          } else {
              updateFinanceMathDependentUI();
              clearFinanceMathDependencyError();
          }

          atualizarCardResumoEmprestimo();
      }

      function atualizarCardResumoEmprestimo() {
          if (!resumoEmprestimoSection) {
              return;
          }

          const pv = parseFloat(valorEmprestimoInput.value) || 0;
          const pmt = parseFloat(valorParcelaInput.value) || 0;
          const nper = getQuantidadeParcelasResumo(frequenciaParcelasSelect.value);
          const taxaMensal = parseFloat(taxaMensalInput.value) || 0;
          const freq = frequenciaParcelasSelect.value;
          const mostrarResumoParcial = tipoEmprestimoRadio.checked && (pv > 0 || pmt > 0 || nper > 0 || taxaMensal > 0);
          
          if (pv > 0 && pmt > 0 && nper > 0) {
              const total = pmt * nper;
              const lucro = total - pv;
              
              if (resumoPvValue) resumoPvValue.textContent = formatCurrencyJS(pv);
              if (resumoPmtValue) resumoPmtValue.textContent = formatCurrencyJS(pmt) + getResumoFrequenciaLabel(freq);
              if (resumoPrazoValue) resumoPrazoValue.textContent = nper + 'x';
              if (resumoTaxaValue) resumoTaxaValue.textContent = taxaMensal.toFixed(2) + '%';
              if (resumoTotalValue) resumoTotalValue.textContent = formatCurrencyJS(total);
              if (resumoLucroValue) resumoLucroValue.textContent = formatCurrencyJS(lucro);
              
              resumoEmprestimoSection.style.display = 'block';
          } else {
              if (resumoPvValue) resumoPvValue.textContent = pv > 0 ? formatCurrencyJS(pv) : '--';
              if (resumoPmtValue) {
                  resumoPmtValue.textContent = pmt > 0
                      ? formatCurrencyJS(pmt) + getResumoFrequenciaLabel(freq)
                      : '--';
              }
              if (resumoPrazoValue) resumoPrazoValue.textContent = nper > 0 ? nper + 'x' : '--';
              if (resumoTaxaValue) resumoTaxaValue.textContent = taxaMensal > 0 ? taxaMensal.toFixed(2) + '%' : '--';
              if (resumoTotalValue) resumoTotalValue.textContent = '--';
              if (resumoLucroValue) resumoLucroValue.textContent = '--';
              resumoEmprestimoSection.style.display = mostrarResumoParcial ? 'block' : 'none';
          }
      }

      function salvarRascunho() {
          const isEmprestimo = tipoEmprestimoRadio.checked;
          const draft = {
              tipoOperacao: document.querySelector('input[name="tipoOperacao"]:checked').value,
          };
          if (isEmprestimo) {
              draft.modoCalculo = document.querySelector('input[name="modoCalculo"]:checked').value;
              draft.valorEmprestimo = valorEmprestimoInput.value;
              draft.valorParcela = valorParcelaInput.value;
              draft.quantidadeParcelas = quantidadeParcelasInput.value;
              draft.frequenciaParcelas = frequenciaParcelasSelect.value;
              draft.taxaMensal = taxaMensalInput.value;
              draft.dataPrimeiroVencimento = document.getElementById('dataPrimeiroVencimento').value;
          }
          localStorage.setItem('calculadoraFlexivelDraft', JSON.stringify(draft));
      }

      function carregarRascunho() {
          const draftStr = localStorage.getItem('calculadoraFlexivelDraft');
          if (draftStr) {
              try {
                  const draft = JSON.parse(draftStr);
                  if (draft.tipoOperacao) {
                      document.querySelector(`input[name="tipoOperacao"][value="${draft.tipoOperacao}"]`).checked = true;
                      toggleModoOperacao();
                  }
                  if (draft.tipoOperacao === 'emprestimo') {
                      if (draft.modoCalculo) {
                          document.querySelector(`input[name="modoCalculo"][value="${draft.modoCalculo}"]`).checked = true;
                      }
                      if (draft.valorEmprestimo) valorEmprestimoInput.value = draft.valorEmprestimo;
                      if (draft.valorParcela) valorParcelaInput.value = draft.valorParcela;
                      if (draft.quantidadeParcelas) quantidadeParcelasInput.value = draft.quantidadeParcelas;
                      if (draft.frequenciaParcelas) frequenciaParcelasSelect.value = draft.frequenciaParcelas;
                      if (draft.taxaMensal) taxaMensalInput.value = draft.taxaMensal;
                      if (draft.dataPrimeiroVencimento) dataPrimeiroVencimentoInput.value = draft.dataPrimeiroVencimento;
                      syncQuantidadeParcelasComFrequencia();
                  }
              } catch (e) {
                  console.error("Erro ao carregar rascunho", e);
              }
          }
          if (tipoEmprestimoRadio.checked) {
              updateModoFlexivel();
          }
      }

      document.getElementById('btnLimparRascunho').addEventListener('click', () => {
          localStorage.removeItem('calculadoraFlexivelDraft');
          valorEmprestimoInput.value = '';
          valorParcelaInput.value = '';
          quantidadeParcelasInput.value = '1';
          frequenciaParcelasSelect.value = 'mensal';
          taxaMensalInput.value = '5.00';
          document.querySelector('input[name="modoCalculo"][value="parcela"]').checked = true;
          const today = new Date();
          today.setDate(today.getDate() + 30);
          dataPrimeiroVencimentoInput.value = today.toISOString().split('T')[0];
          syncQuantidadeParcelasComFrequencia();
          
          titulosBody.innerHTML = ''; // Limpa as parcelas geradas
          clearResultsAndRegister(); // Limpa os totais da simulação anterior
          
          updateModoFlexivel();
      });

      // Salvar rascunho também ao trocar de aba
      tipoAntecipacaoRadio.addEventListener('change', salvarRascunho);
      tipoEmprestimoRadio.addEventListener('change', salvarRascunho);

      // Carregar rascunho ao iniciar
      document.addEventListener('DOMContentLoaded', () => {
          carregarRascunho();
          updateFinanceMathDependentUI();
      });

      frequenciaParcelasSelect.addEventListener('change', () => {
          syncQuantidadeParcelasComFrequencia();
          calcularValoresFlexiveis();
          salvarRascunho();
      });

      temGarantiaSelect.addEventListener('change', () => {
          syncGarantiaVisibility();
          salvarRascunho();
      });

      [valorEmprestimoInput, valorParcelaInput, quantidadeParcelasInput, taxaMensalInput, dataPrimeiroVencimentoInput].forEach(el => {
          if(el) el.addEventListener('input', () => {
              calcularValoresFlexiveis();
              salvarRascunho();
          });
      });

      // Reavaliar a calculadora flexível quando a data da operação muda
      // (afeta a validação do 1º vencimento e o schedule de dias).
      if (dataOperacaoInput) {
          dataOperacaoInput.addEventListener('change', () => {
              if (tipoEmprestimoRadio && tipoEmprestimoRadio.checked) {
                  calcularValoresFlexiveis();
              }
          });
      }

      // --- Funções do Empréstimo ---
      
      function toggleModoOperacao() {
          const labelTaxa = document.getElementById('labelTaxa');
          const containerCedente = document.getElementById('containerCedente');
          const containerTomador = document.getElementById('containerTomador');
          const legendTitulos = document.getElementById('legendTitulos');
          const colHeaderValorPago = document.getElementById('colHeaderValorPago');
          const containerIncorreIOF = document.getElementById('containerIncorreIOF');
          const containerCobrarIOF = document.getElementById('containerCobrarIOF');
          const containerTipoPagamento = document.getElementById('containerTipoPagamento');
          const containerNotificarSacado = document.getElementById('containerNotificarSacado');
          const containerResTotalIOF = document.getElementById('containerResTotalIOF');
          const cardTributacao = document.getElementById('cardTributacao');
          const encontroContasBtnEl = document.getElementById('encontroContasBtn');
          const displayBotaoTaxa = tipoEmprestimoRadio.checked ? 'none' : 'inline-block';

          if (btnAbrirModalTaxa) {
              btnAbrirModalTaxa.style.display = displayBotaoTaxa;
          }

          if (tipoEmprestimoRadio.checked) {
              emprestimoParamsSection.style.display = 'block';
              addTituloBtn.style.display = 'none';
              posicionarCamposLayoutEmprestimo();
              syncGarantiaVisibility();

              // Updates text and visibility
              labelTaxa.textContent = 'Taxa de Juros (% a.m.)';
              legendTitulos.textContent = 'Parcelas do Empréstimo';
              containerCedente.style.display = 'none';
              containerTomador.style.display = 'block';
              colHeaderValorPago.style.display = 'none';
              containerIncorreIOF.style.display = 'none';
              containerCobrarIOF.classList.add('d-none'); // Hide cobrar IOF for loans
              containerTipoPagamento.style.display = 'none';
              containerNotificarSacado.classList.add('d-none');
              containerResTotalIOF.style.display = 'none';
              if (cardTributacao) cardTributacao.style.display = 'none';
              if (encontroContasBtnEl) encontroContasBtnEl.style.display = 'none';

              // Make table inputs readonly
              titulosBody.querySelectorAll('.valor-pago-cell').forEach(el => el.style.display = 'none');
              titulosBody.querySelectorAll('input, select').forEach(el => el.setAttribute('readonly', 'readonly'));
              titulosBody.querySelectorAll('select').forEach(el => el.style.pointerEvents = 'none');
              titulosBody.querySelectorAll('.remove-row-btn').forEach(el => el.style.display = 'none');

              // Default to 30 days if empty
              if(!dataPrimeiroVencimentoInput.value) {
                  const today = new Date();
                  today.setDate(today.getDate() + 30);
                  dataPrimeiroVencimentoInput.value = today.toISOString().split('T')[0];
              }
              syncQuantidadeParcelasComFrequencia();
              
              updateModoFlexivel(); // Inicializa os campos da calc flexível

          } else {
              emprestimoParamsSection.style.display = 'none';
              if (resumoEmprestimoSection) resumoEmprestimoSection.style.display = 'none';
              addTituloBtn.style.display = 'inline-block';
              posicionarCamposLayoutEmprestimo();
              syncGarantiaVisibility();
              resetVisualModoFlexivel();

              labelTaxa.textContent = 'Taxa de Desconto (% a.m.)';
              legendTitulos.textContent = 'Títulos a Descontar';
              containerCedente.style.display = 'block';
              containerTomador.style.display = 'none';
              colHeaderValorPago.style.display = '';
              containerIncorreIOF.style.display = 'block';
              if (document.getElementById('incorreIOF').value === 'Sim') {
                  containerCobrarIOF.classList.remove('d-none');
              }
              containerTipoPagamento.style.display = 'block';
              containerNotificarSacado.classList.remove('d-none');
              containerResTotalIOF.style.display = 'block';
              if (cardTributacao) cardTributacao.style.display = '';

              titulosBody.querySelectorAll('.valor-pago-cell').forEach(el => el.style.display = '');

              // Make table inputs editable
              titulosBody.querySelectorAll('input, select').forEach(el => el.removeAttribute('readonly'));
              titulosBody.querySelectorAll('select').forEach(el => el.style.pointerEvents = 'auto');
              titulosBody.querySelectorAll('.remove-row-btn').forEach(el => el.style.display = 'inline-block');
          }
          updateFinanceMathDependentUI();
          clearResultsAndRegister();
      }

      tipoAntecipacaoRadio.addEventListener('change', toggleModoOperacao);
      tipoEmprestimoRadio.addEventListener('change', toggleModoOperacao);

      btnGerarParcelas.addEventListener('click', function() {
          const financeMath = getFinanceMathApi();
          if (!financeMath) {
              updateFinanceMathDependentUI();
              showFinanceMathDependencyError();
              return;
          }

          clearFinanceMathDependencyError();
          const pv = parseFloat(document.getElementById('valorEmprestimo').value);
          const freq = document.getElementById('frequenciaParcelas').value;
          const qtd = freq === 'pagamento_unico' ? 1 : parseInt(document.getElementById('quantidadeParcelas').value, 10);
          const dataInicio = dataPrimeiroVencimentoInput.value;
          const taxaMensal = parseFloat(taxaMensalInput.value) / 100;
          const tomadorId = document.getElementById('tomador').value;
          // Novo: Pega a parcela do input caso já calculada e usar como fallback
          let pmtCalculadaFlex = parseFloat(valorParcelaInput.value) || 0;

          if (!pv || isNaN(pv) || pv <= 0) { alert('Informe um valor de empréstimo válido.'); return; }
          if (!qtd || isNaN(qtd) || qtd <= 0) { alert('Informe uma quantidade de parcelas válida.'); return; }
          if (!dataInicio) { alert('Informe a data do 1º vencimento.'); return; }
          if (isNaN(taxaMensal) || taxaMensal < 0) { alert('Informe uma taxa mensal válida.'); return; }
          
          const schedule = buildEmprestimoSchedule(dataOperacaoInput.value, dataInicio, qtd, freq);
          if (schedule.days.length !== qtd || schedule.days.some(days => days < 0)) {
              alert('A data do 1º vencimento deve ser igual ou posterior à data base de cálculo.');
              return;
          }

          let pmt = 0;
          if (pmtCalculadaFlex > 0) {
              pmt = pmtCalculadaFlex;
          } else {
              pmt = financeMath.calculatePMTFromDays(taxaMensal, schedule.days, pv);
          }

          // Clear table
          titulosBody.innerHTML = '';
          
          for (let step = 0; step < qtd; step++) {
              const nR = tituloTemplateRow.cloneNode(true);
              nR.removeAttribute('id');
              nR.style.display = '';
              nR.querySelectorAll('input, button, select').forEach(e => e.disabled = false);
              
              nR.querySelector('.valor-original').value = formatValorBR(pmt);
              
              // Set date
              nR.querySelector('.data-vencimento').value = schedule.dates[step].toISOString().split('T')[0];
              
              // Set tipo_recebivel to parcela_emprestimo
              nR.querySelector('.tipo-recebivel-select').value = 'parcela_emprestimo';
              
              // Set sacado_id to tomador
              if (tomadorId) {
                  nR.querySelector('.sacado-select').value = tomadorId;
              } else {
                  nR.querySelector('.sacado-select').selectedIndex = 0; // Deixa "-- Selecione Sacado --"
              }
              
              // Apply readonly
              nR.querySelectorAll('input, select').forEach(el => el.setAttribute('readonly', 'readonly'));
              nR.querySelectorAll('select').forEach(el => el.style.pointerEvents = 'none');
              nR.querySelector('.valor-pago-cell').style.display = 'none';
              const rB = nR.querySelector('.remove-row-btn');
              if(rB) rB.style.display = 'none';

              titulosBody.appendChild(nR);
          }
          
          // Trigger calculation
          document.getElementById('calculateBtn').click();
      });

      // --- Funções Auxiliares ---
      const taxaStep=0.25; taxaDecrementBtn.addEventListener('click',()=>{let v=parseFloat(taxaMensalInput.value)||0;taxaMensalInput.value=Math.max(0,v-taxaStep).toFixed(2);clearResultsAndRegister();}); taxaIncrementBtn.addEventListener('click',()=>{let v=parseFloat(taxaMensalInput.value)||0;taxaMensalInput.value=(v+taxaStep).toFixed(2);clearResultsAndRegister();});
      addTituloBtn.addEventListener('click',()=>{const nR=tituloTemplateRow.cloneNode(true);nR.removeAttribute('id');nR.style.display='';nR.querySelectorAll('input,button,select').forEach(e=>e.disabled=false);nR.querySelectorAll('input').forEach(i=>i.value='');nR.querySelectorAll('select').forEach(s=>s.selectedIndex=0);const nDI=nR.querySelector('.data-vencimento');if(nDI)setFutureDate(nDI,30);const rB=nR.querySelector('.remove-row-btn');if(rB){rB.addEventListener('click',function(){this.closest('tr').remove();clearResultsAndRegister();})}addInputListeners(nR);titulosBody.appendChild(nR);clearResultsAndRegister();});
      function addInputListeners(rE){rE.querySelectorAll('input.valor-original,input.data-vencimento').forEach(i=>{i.addEventListener('change',clearResultsAndRegister);i.addEventListener('input',clearResultsAndRegister);});rE.querySelectorAll('input.valor-original').forEach(i=>attachMoneyMask(i));} addInputListeners(titulosBody.querySelector('tr'));
      function setFutureDate(iE,dA){if(!iE)return;try{const bDS=dataOperacaoInput.value;let bD=bDS?new Date(bDS+'T00:00:00'):new Date();if(isNaN(bD.getTime())){bD=new Date();}bD.setUTCHours(0,0,0,0);const fTS=bD.getTime()+dA*24*60*60*1000;const fD=new Date(fTS);iE.value=fD.toISOString().split('T')[0];}catch(e){console.error("Erro setFutureDate.",e);}} const fDI=titulosBody.querySelector('.data-vencimento');if(fDI&&!fDI.value){setFutureDate(fDI,30);}
      [taxaMensalInput,dataOperacaoInput,incorreIOFSelect,cobrarIOFSelect,tipoPagamentoSelect].forEach(el=>{el.addEventListener('change',clearResultsAndRegister);if(el.tagName==='INPUT'&&el.type==='number'){el.addEventListener('input',clearResultsAndRegister);}});
      cedenteSelect.addEventListener('change',()=>{registerFeedback.textContent='';registerFeedback.className='mt-2';cedenteSelect.classList.remove('is-invalid');checkEncontroContasVisibility();});
      document.getElementById('tomador').addEventListener('change', function() {
          registerFeedback.textContent = '';
          registerFeedback.className = 'mt-2';
          this.classList.remove('is-invalid');
      });
      function toggleCobrarIofVisibility(){if(incorreIOFSelect.value==='Nao'){cobrarIofWrapper.classList.remove('d-none');cobrarIOFSelect.disabled=false;}else{cobrarIofWrapper.classList.add('d-none');}} incorreIOFSelect.addEventListener('change',toggleCobrarIofVisibility);document.addEventListener('DOMContentLoaded',toggleCobrarIofVisibility);

      // --- Funções do Encontro de Contas ---
      async function checkEncontroContasVisibility() {
          if (!cedenteSelect.value) {
              encontroContasBtn.style.display = 'none';
              return;
          }
          
          try {
              const response = await fetch('buscar_recebiveis_indiretos.php', {
                  method: 'POST',
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify({cedente_id: cedenteSelect.value, check_only: true})
              });
              const result = await response.json();
              
              if (result.success && result.has_recebiveis) {
                  encontroContasBtn.style.display = 'inline-block';
              } else {
                  encontroContasBtn.style.display = 'none';
              }
          } catch (error) {
              console.error('Erro ao verificar recebíveis:', error);
              encontroContasBtn.style.display = 'none';
          }
      }

      async function carregarRecebiveisIndiretos() {
          if (!cedenteSelect.value) return;
          
          recebiveisLoading.style.display = 'block';
          recebiveisContainer.style.display = 'none';
          recebiveisError.style.display = 'none';
          
          try {
              const response = await fetch('buscar_recebiveis_indiretos.php', {
                  method: 'POST',
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify({cedente_id: cedenteSelect.value})
              });
              const result = await response.json();
              
              if (result.success) {
                  recebiveisDisponiveis = result.recebiveis;
                  renderizarRecebiveisTabela();
                  recebiveisContainer.style.display = 'block';
              } else {
                  recebiveisError.textContent = result.error || 'Erro ao carregar recebíveis';
                  recebiveisError.style.display = 'block';
              }
          } catch (error) {
              console.error('Erro ao carregar recebíveis:', error);
              recebiveisError.textContent = 'Erro de comunicação com o servidor';
              recebiveisError.style.display = 'block';
          } finally {
              recebiveisLoading.style.display = 'none';
          }
      }

      function renderizarRecebiveisTabela() {
          recebiveisTableBody.innerHTML = '';
          
          recebiveisDisponiveis.forEach(recebivel => {
              const row = document.createElement('tr');
              const saldoDisponivel = recebivel.saldo_disponivel || recebivel.valor_original;
              const valorPresente = calcularValorPresente(saldoDisponivel, recebivel.dias_para_vencimento);
              
              row.innerHTML = `
                  <td>
                      <input type="checkbox" class="form-check-input recebivel-checkbox"
                             data-id="${recebivel.id}" data-valor="${valorPresente}" data-valor-original="${saldoDisponivel}" data-dias="${recebivel.dias_para_vencimento}">
                  </td>
                  <td>${recebivel.id}</td>
                  <td>${formatCurrencyJS(parseFloat(saldoDisponivel))}</td>
                  <td>${new Date(recebivel.data_vencimento).toLocaleDateString('pt-BR')}</td>
                  <td>${recebivel.dias_para_vencimento}</td>
                  <td class="valor-presente">${formatCurrencyJS(valorPresente)}</td>
              `;
              
              recebiveisTableBody.appendChild(row);
          });
          
          // Adicionar event listeners aos checkboxes
          document.querySelectorAll('.recebivel-checkbox').forEach(checkbox => {
              checkbox.addEventListener('change', atualizarValorTotalCompensacao);
          });
      }

      function calcularValorPresente(valorOriginal, dias) {
          const taxa = parseFloat(taxaAntecipacaoInput.value) / 100;
          // Corrigido: usar a mesma fórmula do calculate.php
          // Valor presente = Valor / (1 + taxa)^(dias/30)
          const fatorDesconto = Math.pow(1 + taxa, dias / 30);
          return valorOriginal / fatorDesconto;
      }

      function atualizarValorTotalCompensacao() {
          let total = 0;
          let valorPresente = 0;
          const checkboxes = document.querySelectorAll('.recebivel-checkbox:checked');
          
          // Verificar se há valor customizado
          const valorCustomizado = parseValorMonetario(valorCustomizadoInput.value);
          const taxaAntecipacao = parseFloat(taxaAntecipacaoInput.value) / 100 || 0;
          
          if (valorCustomizado > 0 && checkboxes.length === 1) {
              // Usar valor customizado se especificado e apenas um recebível selecionado
              const checkbox = checkboxes[0];
              const valorOriginalRecebivel = parseFloat(checkbox.dataset.valorOriginal);
              
              // Validar se valor customizado não excede o valor original do recebível
              if (valorCustomizado > valorOriginalRecebivel) {
                  validationError.textContent = `O valor customizado (${formatCurrencyJS(valorCustomizado)}) não pode exceder o valor original do recebível (${formatCurrencyJS(valorOriginalRecebivel)}).`;
                  validationError.style.display = 'block';
                  aprovarCompensacaoBtn.disabled = true;
                  return;
              }
              
              total = valorCustomizado;
              // Calcular valor presente do valor customizado considerando os dias para vencimento
              const diasVencimento = parseInt(checkbox.dataset.dias);
              const fatorDesconto = Math.pow(1 + taxaAntecipacao, diasVencimento / 30);
              valorPresente = valorCustomizado / fatorDesconto;
          } else {
              // Usar valor total dos recebíveis selecionados
              checkboxes.forEach(checkbox => {
                  const valorOriginal = parseFloat(checkbox.dataset.valorOriginal);
                  const dias = parseInt(checkbox.dataset.dias);
                  const valorPresenteCalculado = calcularValorPresente(valorOriginal, dias);
                  total += valorOriginal;
                  valorPresente += valorPresenteCalculado;
              });
          }
          
          const creditoAntecipacao = total - valorPresente;
          
          valorTotalCompensacaoSpan.textContent = formatCurrencyJS(total);
          valorPresenteAntecipado.textContent = formatCurrencyJS(valorPresente);
          document.getElementById('creditoAntecipacaoModal').textContent = formatCurrencyJS(creditoAntecipacao);
          
          // Permitir compensação parcial - validar apenas se há recebíveis selecionados
          validationError.style.display = 'none';
          aprovarCompensacaoBtn.disabled = checkboxes.length === 0;
      }

      function aplicarCompensacao() {
          const checkboxes = document.querySelectorAll('.recebivel-checkbox:checked');
          const recebiveisSelecionados = [];
          let valorTotal = 0;
          
          // Verificar se há valor customizado
          const valorCustomizado = parseValorMonetario(valorCustomizadoInput.value);
          
          checkboxes.forEach(checkbox => {
              const id = checkbox.dataset.id;
              const valorOriginalRecebivel = parseFloat(checkbox.closest('tr').children[2].textContent.replace(/[^\d,]/g, '').replace(',', '.'));
              const diasVencimento = parseInt(checkbox.closest('tr').children[4].textContent);
              let valorCompensacao;
              
              if (valorCustomizado > 0 && checkboxes.length === 1) {
                  valorCompensacao = valorCustomizado;
              } else {
                  // Usar valor original do recebível para compensação, não o valor presente
                  valorCompensacao = valorOriginalRecebivel;
              }
              
              recebiveisSelecionados.push({
                  id, 
                  valor: valorCompensacao,
                  valor_original: valorOriginalRecebivel,
                  dias_vencimento: diasVencimento,
                  valor_customizado: valorCustomizado > 0 && checkboxes.length === 1
              });
              valorTotal += valorCompensacao;
          });
          
          compensacaoAtiva = {
              recebiveis: recebiveisSelecionados,
              valor_total: valorTotal,
              taxa_antecipacao: parseFloat(taxaAntecipacaoInput.value)
          };
          
          // Atualizar interface com detalhes
          compensacaoValor.textContent = formatCurrencyJS(valorTotal);
          
          // Para múltiplos recebíveis, mostrar lista de IDs
          if (recebiveisSelecionados.length === 1) {
              const primeiroRecebivel = recebiveisSelecionados[0];
              compensacaoRecebiveis.textContent = `#${primeiroRecebivel.id}`;
              compensacaoValorOriginal.textContent = formatCurrencyJS(primeiroRecebivel.valor_original);
              
              const saldoRemanescente = primeiroRecebivel.valor_original - valorTotal;
              compensacaoSaldoRemanescente.textContent = formatCurrencyJS(saldoRemanescente);
              
              // Calcular valor presente e crédito do cliente (custo da antecipação)
              const valorPresenteCompensacao = calcularValorPresente(valorTotal, primeiroRecebivel.dias_vencimento);
              const creditoCliente = valorTotal - valorPresenteCompensacao;
              
              // Preencher os novos campos
              compensacaoValorPresente.textContent = formatCurrencyJS(valorPresenteCompensacao);
              compensacaoRemuneracao.textContent = formatCurrencyJS(creditoCliente);
              
              // Determinar status
              if (saldoRemanescente > 0.01) {
                  compensacaoStatus.textContent = 'Parcialmente Quitado';
                  compensacaoStatus.className = 'badge bg-warning';
              } else {
                  compensacaoStatus.textContent = 'Totalmente Quitado';
                  compensacaoStatus.className = 'badge bg-success';
              }
          } else {
              // Múltiplos recebíveis selecionados
              const idsRecebíveis = recebiveisSelecionados.map(r => `#${r.id}`).join(', ');
              compensacaoRecebiveis.textContent = idsRecebíveis;
              
              // Calcular valor original total de todos os recebíveis
              const valorOriginalTotal = recebiveisSelecionados.reduce((sum, r) => sum + r.valor_original, 0);
              compensacaoValorOriginal.textContent = formatCurrencyJS(valorOriginalTotal);
              
              // Para múltiplos títulos, o saldo remanescente deve ser zero se todos forem totalmente compensados
              // ou a soma dos saldos individuais se houver compensação parcial
              let saldoRemanescente = 0;
              let todosQuitados = true;
              
              recebiveisSelecionados.forEach(recebivel => {
                  const saldoIndividual = recebivel.valor_original - recebivel.valor;
                  if (saldoIndividual > 0.01) {
                      saldoRemanescente += saldoIndividual;
                      todosQuitados = false;
                  }
              });
              
              compensacaoSaldoRemanescente.textContent = formatCurrencyJS(saldoRemanescente);
              
              // Calcular valor presente médio ponderado
              let valorPresenteTotal = 0;
              recebiveisSelecionados.forEach(recebivel => {
                  const valorPresenteIndividual = calcularValorPresente(recebivel.valor, recebivel.dias_vencimento);
                  valorPresenteTotal += valorPresenteIndividual;
              });
              
              const creditoCliente = valorTotal - valorPresenteTotal;
              
              // Preencher os novos campos
              compensacaoValorPresente.textContent = formatCurrencyJS(valorPresenteTotal);
              compensacaoRemuneracao.textContent = formatCurrencyJS(creditoCliente);
              
              // Determinar status para múltiplos títulos
              if (todosQuitados) {
                  compensacaoStatus.textContent = 'Totalmente Quitados';
                  compensacaoStatus.className = 'badge bg-success';
              } else {
                  compensacaoStatus.textContent = 'Parcialmente Quitados';
                  compensacaoStatus.className = 'badge bg-warning';
              }
          }
          
          compensacaoInfo.style.display = 'block';
          
          // Salvar dados para envio
          compensacaoDataInput.value = JSON.stringify(compensacaoAtiva);
          
          // Fechar modal
          encontroContasModal.hide();
          
          // Recalcular totais se necessário
          if (calculationResults) {
              updateCalculations();
          }
      }

      // Event Listeners do Encontro de Contas
      encontroContasBtn.addEventListener('click', () => {
          // Atualizar data base de cálculo no modal
          const dataBaseCalculo = document.getElementById('data_operacao').value;
          if (dataBaseCalculo) {
              const dataFormatada = new Date(dataBaseCalculo + 'T00:00:00').toLocaleDateString('pt-BR');
              document.getElementById('dataBaseCalculoModal').textContent = dataFormatada;
          }
          
          carregarRecebiveisIndiretos();
          encontroContasModal.show();
      });

      taxaAntecipacaoInput.addEventListener('input', () => {
          if (recebiveisDisponiveis.length > 0) {
              // Store currently checked checkboxes before re-rendering
              const checkedBefore = new Set();
              document.querySelectorAll('.recebivel-checkbox:checked').forEach(cb => {
                  checkedBefore.add(cb.dataset.id);
              });
              
              renderizarRecebiveisTabela();
              
              // Restore checked state after re-rendering
              document.querySelectorAll('.recebivel-checkbox').forEach(cb => {
                  if (checkedBefore.has(cb.dataset.id)) {
                      cb.checked = true;
                  }
              });
              
              atualizarValorTotalCompensacao();
          }
      });

      aprovarCompensacaoBtn.addEventListener('click', aplicarCompensacao);

      // Event listener para remoção de compensação
      removerCompensacaoBtn.addEventListener('click', function() {
          if (confirm('Tem certeza que deseja remover a compensação aplicada?')) {
              removerCompensacao();
          }
      });

      // Event listener para valor customizado
      valorCustomizadoInput.addEventListener('input', function() {
          aplicarMascaraMonetaria(this);
          atualizarValorTotalCompensacao();
      });

      // Verificar visibilidade do botão ao carregar a página
      document.addEventListener('DOMContentLoaded', checkEncontroContasVisibility);

      // Função para formatar valores em moeda
      function formatCurrencyJS(value) {
          return new Intl.NumberFormat('pt-BR', {
              style: 'currency',
              currency: 'BRL'
          }).format(value);
      }

      function aplicarMascaraMonetaria(input) {
          let valor = input.value.replace(/\D/g, '');
          valor = (valor / 100).toFixed(2) + '';
          valor = valor.replace('.', ',');
          valor = valor.replace(/(\d)(\d{3})(\d{3}),/g, '$1.$2.$3,');
          valor = valor.replace(/(\d)(\d{3}),/g, '$1.$2,');
          input.value = 'R$ ' + valor;
      }

      function parseValorMonetario(valor) {
          if (!valor) return 0;
          return parseFloat(valor.replace(/[^\d,]/g, '').replace(',', '.')) || 0;
      }

      function removerCompensacao() {
          compensacaoAtiva = null;
          compensacaoInfo.style.display = 'none';
          compensacaoDataInput.value = '';
          
          // Limpar campos do modal
          valorCustomizadoInput.value = '';
          
          // Recalcular totais se necessário
          if (calculationResults) {
              updateCalculations();
          }
      }

      // Inicializar modal do Bootstrap
      let descobrirTaxaModal;
      document.addEventListener('DOMContentLoaded', function() {
          encontroContasModal = new bootstrap.Modal(document.getElementById('encontroContasModal'));
          if (document.getElementById('descobrirTaxaModal')) {
              descobrirTaxaModal = new bootstrap.Modal(document.getElementById('descobrirTaxaModal'));
          }
      });

      // --- Descobrir Taxa por Valor Alvo ---
      const valorAlvoInput = document.getElementById('valorAlvoInput');
      const calcularTaxaAlvoBtn = document.getElementById('calcularTaxaAlvoBtn');
      const descobrirTaxaError = document.getElementById('descobrirTaxaError');

      if (valorAlvoInput) {
          valorAlvoInput.addEventListener('input', function() {
              aplicarMascaraMonetaria(this);
          });
      }

      if (calcularTaxaAlvoBtn) {
          calcularTaxaAlvoBtn.addEventListener('click', async () => {
              descobrirTaxaError.classList.add('d-none');
              const valorAlvo = parseValorMonetario(valorAlvoInput.value);
              
              if (valorAlvo <= 0) {
                  descobrirTaxaError.textContent = 'Insira um valor válido maior que zero.';
                  descobrirTaxaError.classList.remove('d-none');
                  return;
              }

              // Coletar dados da operação
              const titulos = [];
              let totalOriginal = 0;
              titulosBody.querySelectorAll('tr').forEach((row) => {
                  const vI = row.querySelector('.valor-original:not([disabled])');
                  const dI = row.querySelector('.data-vencimento:not([disabled])');
                  if (vI && vI.value && dI && dI.value) {
                      const v = parseValorBR(vI.value);
                      titulos.push({ valorOriginal: v, dataVencimento: dI.value });
                      totalOriginal += v;
                  }
              });

              if (titulos.length === 0) {
                  descobrirTaxaError.textContent = 'Adicione ao menos um título com valor e data de vencimento preenchidos antes de calcular a taxa.';
                  descobrirTaxaError.classList.remove('d-none');
                  return;
              }

              const data = {
                  valorAlvo: valorAlvo,
                  data_operacao: dataOperacaoInput.value,
                  cobrarIOF: cobrarIOFSelect.value,
                  titulos: titulos
              };

              if (compensacaoAtiva && compensacaoAtiva.recebiveis && compensacaoAtiva.recebiveis.length > 0) {
                  data.compensacao_data = {
                      recebiveis: compensacaoAtiva.recebiveis,
                      valor_total: compensacaoAtiva.valor_total,
                      taxa_antecipacao: compensacaoAtiva.taxa_antecipacao
                  };
              }

              calcularTaxaAlvoBtn.disabled = true;
              calcularTaxaAlvoBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Calculando...';

              try {
                  const response = await fetch('descobrir_taxa.php', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify(data)
                  });
                  
                  const result = await response.json();
                  
                  if (result.success) {
                      // Atualizar o input de taxa
                      taxaMensalInput.value = result.taxaMensal.toFixed(4); // Precisão extra
                      
                      // Fechar o modal
                      descobrirTaxaModal.hide();
                      
                      // Limpar o input do modal
                      valorAlvoInput.value = '';
                      
                      // Executar o cálculo principal para atualizar a tela
                      updateCalculations();
                  } else {
                      descobrirTaxaError.textContent = result.error || 'Erro ao calcular a taxa.';
                      descobrirTaxaError.classList.remove('d-none');
                  }
              } catch (error) {
                  descobrirTaxaError.textContent = 'Erro de comunicação com o servidor: ' + error.message;
                  descobrirTaxaError.classList.remove('d-none');
              } finally {
                  calcularTaxaAlvoBtn.disabled = false;
                  calcularTaxaAlvoBtn.textContent = 'Calcular Taxa';
              }
          });
      }

      // --- Calculate Button Action ---
      calculateBtn.addEventListener('click', () => {
          if (tipoEmprestimoRadio.checked) {
              const rows = titulosBody.querySelectorAll('tr');
              // Se for empréstimo e não houver parcelas geradas, gera primeiro
              if (rows.length === 0 || rows[0].querySelector('input[name="titulo_valor[]"]').value === '') {
                  document.getElementById('btnGerarParcelas').click();
                  return; // btnGerarParcelas já chama updateCalculations no final
              }
          }
          updateCalculations();
      });

      // --- Function to Update Calculations via AJAX ---
      async function updateCalculations() {
          errorMessageDiv.classList.add('d-none'); errorMessageDiv.textContent = ''; let valid = true; form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
          [taxaMensalInput, dataOperacaoInput].forEach(el=>{if(!el.value||(el.type==='number'&&parseFloat(el.value)<0)){el.classList.add('is-invalid');valid=false;}});
          const titulos = []; const rows = titulosBody.querySelectorAll('tr');
          if(rows.length===0){errorMessageDiv.textContent='Adicione título.';valid=false;} else {rows.forEach((row,index)=>{const vI=row.querySelector('.valor-original:not([disabled])');const dI=row.querySelector('.data-vencimento:not([disabled])');const sI=row.querySelector('.sacado-select:not([disabled])');const tI=row.querySelector('.tipo-recebivel-select:not([disabled])');let rV=true;if(!vI||!vI.value||parseValorBR(vI.value)<=0){if(vI)vI.classList.add('is-invalid');rV=false;valid=false;}else{if(vI)vI.classList.remove('is-invalid');}if(!dI||!dI.value){if(dI)dI.classList.add('is-invalid');rV=false;valid=false;}else{if(dI)dI.classList.remove('is-invalid');}if(dI&&dI.value&&dataOperacaoInput.value&&dI.value<dataOperacaoInput.value){if(dI)dI.classList.add('is-invalid');errorMessageDiv.textContent='Venc. anterior à Data Op.';rV=false;valid=false;}if(vI&&dI&&rV){titulos.push({valorOriginal:parseValorBR(vI.value),dataVencimento:dI.value,sacadoId:sI?sI.value||null:null,tipoRecebivel:tI?tI.value||'duplicata':'duplicata'});}});if(valid&&titulos.length===0&&rows.length>0){errorMessageDiv.textContent='Preencha títulos.';valid=false;}}
          if(!valid){if(!errorMessageDiv.textContent){errorMessageDiv.textContent='Verifique campos.';}errorMessageDiv.classList.remove('d-none');registerBtn.disabled=true;return;}
          const tipoOperacaoChecked = document.querySelector('input[name="tipoOperacao"]:checked').value;
          const data = { 
              cedente_id: tipoOperacaoChecked === 'emprestimo' ? null : cedenteSelect.value,
              tomador_id: tipoOperacaoChecked === 'emprestimo' ? document.getElementById('tomador').value : null,
              tipo_pagamento:tipoPagamentoSelect.value,
              tipoOperacao:tipoOperacaoChecked,
              valor_emprestimo: tipoOperacaoChecked === 'emprestimo' ? parseFloat(document.getElementById('valorEmprestimo').value) : null,
              tem_garantia: document.getElementById('temGarantia').value === 'Sim' ? 1 : 0,
              descricao_garantia: document.getElementById('descricaoGarantia').value,
              taxaMensal:parseFloat(taxaMensalInput.value)||0,
              data_operacao:dataOperacaoInput.value,
              incorreIOF: tipoOperacaoChecked === 'emprestimo' ? 'Nao' : incorreIOFSelect.value,
              cobrarIOF: tipoOperacaoChecked === 'emprestimo' ? 'Nao' : cobrarIOFSelect.value,
              notas:notasInput.value.trim(),
              titulos:titulos 
          };
          
          // Adicionar dados de compensação se houver
          if (compensacaoAtiva && compensacaoAtiva.recebiveis && compensacaoAtiva.recebiveis.length > 0) {
              data.compensacao_data = {
                  recebiveis: compensacaoAtiva.recebiveis,
                  valor_total: compensacaoAtiva.valor_total,
                  taxa_antecipacao: compensacaoAtiva.taxa_antecipacao
              };
          }
          calculateBtn.disabled = true; calculateBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Calc...'; registerBtn.disabled=true;
          try {
              const response = await fetch('calculate.php', {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(data)});
              if(!response.ok){let eTxt=`Erro HTTP ${response.status}`;try{eTxt=(await response.json()).error||eTxt;}catch(_){}throw new Error(eTxt);}
              const results = await response.json();

              if(results.error){throw new Error(results.error);}
              else {
                   resMediaDias.textContent=results.mediaPonderadaDias??'--';
                   resTotalOriginal.textContent=results.totalOriginal??'--';
                   resTotalPresente.textContent=results.totalPresente??'--';
                   resTotalIOF.textContent=results.totalIOF??'--';
                   
                   // Verificar se há compensação ativa e atualizar campos correspondentes
                   if (compensacaoAtiva && compensacaoAtiva.recebiveis && compensacaoAtiva.recebiveis.length > 0) {
                       const valorCompensacao = compensacaoAtiva.valor_total;
                       
                       // Calcular valor presente total considerando todos os recebíveis
                       let valorPresenteCompensacaoTotal = 0;
                       compensacaoAtiva.recebiveis.forEach(recebivel => {
                           const valorPresenteIndividual = calcularValorPresente(recebivel.valor, recebivel.dias_vencimento);
                           valorPresenteCompensacaoTotal += valorPresenteIndividual;
                       });
                       
                       const creditoCliente = valorCompensacao - valorPresenteCompensacaoTotal;
                       
                       // Mostrar linha de compensação
                       compensacaoRow.style.display = 'block';
                       resAntecipacao.textContent = formatCurrencyJS(valorCompensacao);
                       resCreditoAntecipacao.textContent = formatCurrencyJS(creditoCliente);
                       
                       // Ajustar Total Líquido Pago
                       const totalLiquidoAjustado = (results.totalLiquidoPagoNumerico || 0) - valorCompensacao + creditoCliente;
                       resTotalLiquido.textContent = formatCurrencyJS(totalLiquidoAjustado);
                   } else {
                       // Esconder linha de compensação
                       compensacaoRow.style.display = 'none';
                       resTotalLiquido.textContent = results.totalLiquidoPago ?? '--';
                   }
                   resTotalLucro.textContent=results.totalLucroLiquido??'--';
                   resMargemTotal.textContent=results.totalLucroPercentual??'--';
                   resRetornoMensal.textContent=results.retornoMensalFormatado??'--';

                   [resTotalLucro,resMargemTotal,resRetornoMensal].forEach(el=>{el.className='value';if(typeof results.isProfit!=='undefined'){el.classList.add(results.isProfit?'profit':'loss');}});

                   const currentRows = titulosBody.querySelectorAll('tr');
                   if (results.calculatedTitlesDetails && Array.isArray(results.calculatedTitlesDetails)) {
                       results.calculatedTitlesDetails.forEach((detail, index) => {
                           if (currentRows[index]) {
                               const diasCell = currentRows[index].querySelector('.dias-restantes-cell');
                               const valorPagoCell = currentRows[index].querySelector('.valor-pago-cell'); // Seleciona a nova célula

                               if(diasCell) diasCell.textContent = detail.dias ?? '-';
                               // PREENCHIMENTO CORRIGIDO DA NOVA CÉLULA:
                               if(valorPagoCell) valorPagoCell.textContent = formatCurrencyJS(detail.valor_liquido_calc_dinamico); // Usa a função para formatar e preencher
                           }
                       });
                       // Limpa células de dias/valor pago para linhas extras se o número de títulos diminuiu
                       for (let i = results.calculatedTitlesDetails.length; i < currentRows.length; i++) {
                           const diasCell = currentRows[i].querySelector('.dias-restantes-cell');
                           const valorPagoCell = currentRows[i].querySelector('.valor-pago-cell');
                           if(diasCell) diasCell.textContent = '';
                           if(valorPagoCell) valorPagoCell.textContent = '--'; // Garante que a nova célula seja limpa
                       }
                   } else {
                       currentRows.forEach(r => {
                           const diasCell = r.querySelector('.dias-restantes-cell');
                           const valorPagoCell = r.querySelector('.valor-pago-cell');
                           if(diasCell) diasCell.textContent = '';
                           if(valorPagoCell) valorPagoCell.textContent = '--';
                       });
                       console.warn("calculatedTitlesDetails ausente/inválido.");
                   }

                   // Chamada para updateChart com os novos arrays para o gráfico
                   // O updateChart agora espera 3 arrays de dados: emprestado, retornado, lucro
                   updateChart(
                       results.chartLabels || [],
                       results.chartDataCapitalEmprestado || [],
                       results.chartDataCapitalRetornado || [],
                       results.chartDataLucro || []
                   );

                   calculationResults=results;
                   lastInputDataForRegister=data;
                   registerBtn.disabled=false;
                   registerFeedback.textContent='';
                   registerFeedback.className='mt-2';
                   if(exportPdfClienteBtn) exportPdfClienteBtn.disabled = false;
              }
          } catch (error) {
              console.error('Erro cálculo:',error);
              errorMessageDiv.textContent=`Erro: ${error.message}.`;
              errorMessageDiv.classList.remove('d-none');
              clearResultsAndRegister();
          } finally {
              calculateBtn.disabled=false;
              calculateBtn.textContent='Calcular Totais';
          }
      }

      // --- Funções clearResultsAndRegister e clearResults (com limpeza de dias) ---
      function clearResultsAndRegister(clearRegisterFeedback = true) {
         clearResults();
         calculationResults=null;
         lastInputDataForRegister=null;
         registerBtn.disabled=true;
         if(exportPdfClienteBtn) exportPdfClienteBtn.disabled = true;
         if(clearRegisterFeedback){ registerFeedback.textContent=''; registerFeedback.className='mt-2';}
       }
      function clearResults() {
          resMediaDias.textContent='--';
          resTotalOriginal.textContent='--';
          resTotalPresente.textContent='--';
          resTotalIOF.textContent='--';
          resTotalLiquido.textContent='--';
          resTotalLucro.textContent='--';
          resMargemTotal.textContent='--';
          resRetornoMensal.textContent='--';
          resAntecipacao.textContent='--';
          resCreditoAntecipacao.textContent='--';
          compensacaoRow.style.display = 'none';
          [resTotalLucro,resMargemTotal,resRetornoMensal].forEach(el=>el.className='value');
          if(myFluxoChart){myFluxoChart.destroy();myFluxoChart=null;}
          // Removido: errorMessageDiv.classList.add('d-none'); - para não esconder mensagens de erro
          form.querySelectorAll('.is-invalid').forEach(el=>el.classList.remove('is-invalid'));
          titulosBody.querySelectorAll('.dias-restantes-cell').forEach(cell => { cell.textContent = ''; });
          titulosBody.querySelectorAll('.valor-pago-cell').forEach(cell => { cell.textContent = '--'; }); // Limpa a nova coluna também
      }

      // --- Lógica Registrar ---
      registerBtn.addEventListener('click', async () => {
          if (!lastInputDataForRegister) { registerFeedback.textContent = 'Erro: Calcule antes de registrar.'; registerFeedback.className = 'mt-2 alert alert-warning p-1 text-center'; return; }
          const isEmprestimo = document.querySelector('input[name="tipoOperacao"]:checked').value === 'emprestimo';
          
          if (!isEmprestimo) {
              const currentCedenteId = cedenteSelect.value;
              if (!currentCedenteId) { registerFeedback.textContent = 'Erro: Selecione Cedente.'; registerFeedback.className = 'mt-2 alert alert-danger p-1 text-center'; cedenteSelect.classList.add('is-invalid'); return; }
              else { cedenteSelect.classList.remove('is-invalid'); lastInputDataForRegister.cedente_id = currentCedenteId; }
          } else {
              const currentTomadorId = document.getElementById('tomador').value;
              if (!currentTomadorId) { 
                  registerFeedback.textContent = 'Erro: Selecione o Tomador de Empréstimo (Sacado) para registrar.'; 
                  registerFeedback.className = 'mt-2 alert alert-danger p-1 text-center'; 
                  document.getElementById('tomador').classList.add('is-invalid'); 
                  return; 
              } else {
                  document.getElementById('tomador').classList.remove('is-invalid');
                  lastInputDataForRegister.tomador_id = currentTomadorId;
                  
                  // Garantir que todos os títulos no payload de registro tenham o sacado preenchido
                  if (lastInputDataForRegister.titulos && Array.isArray(lastInputDataForRegister.titulos)) {
                      lastInputDataForRegister.titulos.forEach(titulo => {
                          if (!titulo.sacadoId) {
                              titulo.sacadoId = currentTomadorId;
                          }
                      });
                  }
              }
          }
          lastInputDataForRegister.tipo_pagamento = tipoPagamentoSelect.value;
          lastInputDataForRegister.data_operacao = dataOperacaoInput.value;
          lastInputDataForRegister.incorreIOF = isEmprestimo ? 'Nao' : incorreIOFSelect.value;
          lastInputDataForRegister.cobrarIOF = isEmprestimo ? 'Nao' : cobrarIOFSelect.value;
          lastInputDataForRegister.tem_garantia = document.getElementById('temGarantia').value === 'Sim' ? 1 : 0;
          lastInputDataForRegister.descricao_garantia = document.getElementById('descricaoGarantia').value;
          
          // Adiciona dados de compensação se houver
          if (compensacaoAtiva && compensacaoAtiva.recebiveis && compensacaoAtiva.recebiveis.length > 0) {
              lastInputDataForRegister.compensacao_data = {
                  recebiveis: compensacaoAtiva.recebiveis,
                  valor_total: compensacaoAtiva.valor_total,
                  taxa_antecipacao: compensacaoAtiva.taxa_antecipacao
              };
          } else {
              lastInputDataForRegister.compensacao_data = null;
          }
          
          registerBtn.disabled = true;
          registerFeedback.textContent = 'Registrando...';
          registerFeedback.className = 'mt-2 text-muted';
          try {
               const response = await fetch('registrar_operacao.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(lastInputDataForRegister) });
               if (!response.ok) { let eTxt = `Erro HTTP ${response.status}`; try { eTxt = (await response.json()).error || eTxt; } catch (_) {} throw new Error(eTxt); }
               const result = await response.json();
               if (result.success) {
                   registerFeedback.textContent = `Operação ${result.operacao_id ? '#' + result.operacao_id + ' ' : ''}registrada!`;
                   registerFeedback.className = 'mt-2 alert alert-success p-1 text-center';
                   // Limpa dados de compensação após registro bem-sucedido
                   compensacaoAtiva = null;
                   document.getElementById('compensacaoInfo').style.display = 'none';
                   
                   // Mostrar seção de upload de arquivos
                   if (result.operacao_id) {
                       mostrarSecaoArquivos(result.operacao_id);
                   }
                   
                   // Notificar sacados se o checkbox estiver marcado e não for empréstimo
                  const chkNotificar = document.getElementById('notificar_sacado');
                  if (chkNotificar && chkNotificar.checked && result.operacao_id && !isEmprestimo) {
                      registerFeedback.textContent += ' Enviando notificações...';
                      fetch('notificar_sacados.php', {
                          method: 'POST',
                          headers: { 'Content-Type': 'application/json' },
                          body: JSON.stringify({ operacao_id: result.operacao_id })
                      })
                      .then(res => res.json())
                      .then(data => {
                          if (data.success) {
                              registerFeedback.textContent = `Operação #${result.operacao_id} registrada! E-mails enviados: ${data.mensagem}`;
                          } else {
                              registerFeedback.textContent = `Operação #${result.operacao_id} registrada, mas erro no envio: ${data.error}`;
                              registerFeedback.className = 'mt-2 alert alert-warning p-1 text-center';
                          }
                      })
                      .catch(err => {
                          registerFeedback.textContent = `Operação #${result.operacao_id} registrada, erro no e-mail: ${err.message}`;
                          registerFeedback.className = 'mt-2 alert alert-warning p-1 text-center';
                      });
                  }
              }
              else { registerFeedback.textContent = `Erro: ${result.error || 'Desconhecido'}`; registerFeedback.className = 'mt-2 alert alert-danger p-1 text-center'; registerBtn.disabled = false; }
          } catch (error) {
              console.error("Erro registro:", error);
              registerFeedback.textContent = `Erro comunicação: ${error.message}`;
              registerFeedback.className = 'mt-2 alert alert-danger p-1 text-center';
              registerBtn.disabled = false;
          }
      });

      // --- Funcionalidade de Upload de Arquivos ---
      const arquivosInput = document.getElementById('arquivos');
      const arquivosSection = document.getElementById('arquivosSection');
      const arquivosPreview = document.getElementById('arquivos-preview');
      const arquivosList = document.getElementById('arquivos-list');
      const descricaoArquivos = document.getElementById('descricaoArquivos');

      // Mostrar seção de arquivos após registro bem-sucedido
      function mostrarSecaoArquivos(operacaoId) {
          arquivosSection.style.display = 'block';
          arquivosSection.dataset.operacaoId = operacaoId;
          
          // Adicionar botão de upload
          if (!document.getElementById('uploadArquivosBtn')) {
              const uploadBtn = document.createElement('button');
              uploadBtn.type = 'button';
              uploadBtn.id = 'uploadArquivosBtn';
              uploadBtn.className = 'btn btn-primary mt-3';
              uploadBtn.innerHTML = '<i class="bi bi-cloud-upload"></i> Enviar Arquivos';
              uploadBtn.addEventListener('click', uploadArquivos);
              arquivosSection.appendChild(uploadBtn);
          }
      }

      // Preview dos arquivos selecionados
      if (arquivosInput) {
          arquivosInput.addEventListener('change', function() {
              const files = this.files;
              if (files.length === 0) {
                  arquivosPreview.style.display = 'none';
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
              
              // Habilitar/desabilitar botão de upload
              const uploadBtn = document.getElementById('uploadArquivosBtn');
              if (uploadBtn) {
                  uploadBtn.disabled = hasErrors || files.length === 0;
              }
          });
      }

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

      // Função para upload dos arquivos
      async function uploadArquivos() {
          const operacaoId = arquivosSection.dataset.operacaoId;
          const files = arquivosInput.files;
          
          if (!operacaoId || files.length === 0) {
              alert('Selecione arquivos para enviar.');
              return;
          }

          const uploadBtn = document.getElementById('uploadArquivosBtn');
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
                  
                  // Recarregar lista de arquivos se estiver na página de detalhes
                  if (typeof carregarArquivosOperacao === 'function') {
                      carregarArquivosOperacao(operacaoId);
                  }
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
      }

      // --- Função updateChart (AGORA COM TRÊS BARRAS E LABELS DE MÊS) ---
      // Esta é a versão da função updateChart que deve estar no seu arquivo.
      // Substitua a versão existente no seu index.php por esta.
      function updateChart(labels, dataEmprestado, dataRetornado, dataLucro) {
          if (myFluxoChart) { myFluxoChart.destroy(); myFluxoChart = null; }

          // Validação robusta para garantir que todos os arrays de dados são válidos e consistentes
          if (!labels || !Array.isArray(labels) || labels.length === 0 ||
              !dataEmprestado || !Array.isArray(dataEmprestado) || dataEmprestado.length !== labels.length ||
              !dataRetornado || !Array.isArray(dataRetornado) || dataRetornado.length !== labels.length ||
              !dataLucro || !Array.isArray(dataLucro) || dataLucro.length !== labels.length
          ) {
              console.warn("Dados inválidos ou inconsistentes para o gráfico. Gráfico não será renderizado.");
              if(chartCanvas){ const ctx = chartCanvas.getContext('2d'); if(ctx) ctx.clearRect(0, 0, chartCanvas.width, chartCanvas.height); }
              return;
          }

          if (!chartCanvas) { console.error("Canvas do gráfico não encontrado!"); return; }
          const ctx = chartCanvas.getContext('2d'); if (!ctx) { console.error("Contexto 2D do gráfico não obtido."); return; }
          try {
              myFluxoChart = new Chart(ctx, {
                  type: 'bar',
                  data: {
                      labels: labels, // 'labels' agora contém os nomes dos meses
                      datasets: [
                          {
                              label: 'Capital Emprestado (Saída)',
                              data: dataEmprestado,
                              backgroundColor: 'rgba(108, 117, 125, 0.7)', // Cinza
                              borderColor: 'rgba(108, 117, 125, 1)',
                              borderWidth: 1,
                              order: 3 // Ordem para empilhamento ou exibição
                          },
                          {
                              label: 'Capital Retornado (Entrada)',
                              data: dataRetornado,
                              backgroundColor: 'rgba(0, 123, 255, 0.7)', // Azul
                              borderColor: 'rgba(0, 123, 255, 1)',
                              borderWidth: 1,
                              order: 2
                          },
                          {
                              label: 'Lucro Líquido',
                              data: dataLucro,
                              backgroundColor: 'rgba(25, 135, 84, 0.7)', // Verde
                              borderColor: 'rgba(25, 135, 84, 1)',
                              borderWidth: 1,
                              order: 1
                          }
                      ]
                  },
                  options: {
                      responsive: true,
                      maintainAspectRatio: false,
                      scales: {
                          y: {
                              beginAtZero: true,
                              ticks: {
                                  callback: function(value) {
                                      return value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 0, maximumFractionDigits: 0 });
                                  }
                              }
                          },
                          x: {
                              // O Chart.js usará os rótulos diretamente do array 'labels'.
                          }
                      },
                      plugins: {
                          legend: {
                              display: true,
                              position: 'bottom',
                              labels: {
                                  generateLabels: function(chart) {
                                      const fmt = (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                                      return chart.data.datasets.map((ds, i) => {
                                          const total = (ds.data || []).reduce((a, b) => a + (Number(b) || 0), 0);
                                          const meta = chart.getDatasetMeta(i);
                                          return {
                                              text: ds.label + ' — ' + fmt(total),
                                              fillStyle: ds.backgroundColor,
                                              strokeStyle: ds.borderColor,
                                              lineWidth: ds.borderWidth,
                                              hidden: meta.hidden === true,
                                              datasetIndex: i
                                          };
                                      });
                                  }
                              }
                          },
                          tooltip: {
                              callbacks: {
                                  title: function(context) {
                                      return context[0].label; // Título é o nome do mês
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
              console.error("Erro Chart.js ao criar o gráfico:", chartError);
              errorMessageDiv.textContent='Erro ao renderizar gráfico: '+chartError.message;
              errorMessageDiv.classList.remove('d-none');
          }
      }

      // --- Listener para Botão PDF Cliente (type="button") ---
if (exportPdfClienteBtn) { // Verifica se o botão existe no HTML
     exportPdfClienteBtn.addEventListener('click', function() {
         if (exportPdfClienteBtn.disabled) {
             console.log("[PDF Cliente Click] Botão desabilitado.");
             return;
         }
         console.log("[PDF Cliente Click] Clicado.");
         chartImageDataInput.value = ''; // Garante que o campo da imagem vá vazio

         // Define action/target e submete manualmente
         const originalAction = form.action;
         const originalTarget = form.target;
         form.action = 'gerar_recibo_cliente.php'; // Gerador unificado (modo simulação via POST)
         form.target = '_blank';               // Nova aba

         // Converte valores BR para formato simples antes do submit
         const valorInputs = form.querySelectorAll('input.valor-original');
         const valoresBR = [];
         valorInputs.forEach(i => { valoresBR.push(i.value); i.value = String(parseValorBR(i.value)); });

         console.log("[PDF Cliente Click] Submetendo para gerar_recibo_cliente.php (modo simulação)...");
         form.submit(); // Envia

         // Restaura action/target e valores BR após um delay
         setTimeout(() => {
             form.action = originalAction;
             form.target = originalTarget;
             valorInputs.forEach((i, idx) => { i.value = valoresBR[idx]; });
         }, 500);
     });
 }

   </script>

   <script>
   /* UX/UI v2 — hooks não-invasivos para o painel sticky e status badge.
      Não modificam a lógica de cálculo; apenas refletem estado já existente. */
   (function () {
       const statusBadge = document.getElementById('simStatusBadge');
       const statusText = document.getElementById('simStatusBadgeText');
       const registerBtn = document.getElementById('registerBtn');
       const summaryPanel = document.getElementById('summaryPanel');
       const summaryModeBadge = document.getElementById('summaryModeBadge');
       const tipoAntecipacao = document.getElementById('tipoAntecipacao');
       const tipoEmprestimo = document.getElementById('tipoEmprestimo');

       function setStatus(state) {
           if (!statusBadge || !statusText) return;
           statusBadge.classList.remove('is-pending', 'is-calculated', 'is-ready');
           if (state === 'ready') {
               statusBadge.classList.add('is-ready');
               statusBadge.querySelector('i').className = 'bi bi-check-circle-fill';
               statusText.textContent = 'Pronta para registrar';
           } else if (state === 'calculated') {
               statusBadge.classList.add('is-calculated');
               statusBadge.querySelector('i').className = 'bi bi-calculator-fill';
               statusText.textContent = 'Calculada';
           } else {
               statusBadge.classList.add('is-pending');
               statusBadge.querySelector('i').className = 'bi bi-hourglass-split';
               statusText.textContent = 'Aguardando cálculo';
           }
       }

       /* Observa o estado de disabled do botão Registrar para inferir status. */
       if (registerBtn) {
           const obs = new MutationObserver(() => {
               if (!registerBtn.disabled) {
                   setStatus('ready');
               } else {
                   /* Se totalLiquido tem valor != "--" há cálculo recente */
                   const tot = document.getElementById('resTotalLiquido');
                   if (tot && tot.textContent && tot.textContent.trim() !== '--' && tot.textContent.trim() !== '') {
                       setStatus('calculated');
                   } else {
                       setStatus('pending');
                   }
               }
           });
           obs.observe(registerBtn, { attributes: true, attributeFilter: ['disabled'] });
       }

       /* Abre automaticamente o <details> do Fluxo de Caixa quando há cálculo concluído. */
       const fluxoCanvas = document.getElementById('fluxoCaixaChart');
       const fluxoDetails = fluxoCanvas ? fluxoCanvas.closest('details') : null;
       const totalLiquidoEl = document.getElementById('resTotalLiquido');
       if (fluxoDetails && totalLiquidoEl) {
           const obsChart = new MutationObserver(() => {
               const v = (totalLiquidoEl.textContent || '').trim();
               if (v && v !== '--') {
                   fluxoDetails.open = true;
               } else {
                   fluxoDetails.open = false;
               }
           });
           obsChart.observe(totalLiquidoEl, { childList: true, characterData: true, subtree: true });
       }

       /* Sincroniza o badge de modo no painel sticky com o radio selecionado. */
       function syncModoBadge() {
           if (!summaryPanel || !summaryModeBadge) return;
           if (tipoEmprestimo && tipoEmprestimo.checked) {
               summaryPanel.classList.add('is-loan-mode');
               summaryModeBadge.textContent = 'Empréstimo';
           } else {
               summaryPanel.classList.remove('is-loan-mode');
               summaryModeBadge.textContent = 'Antecipação';
           }
       }
       if (tipoAntecipacao) tipoAntecipacao.addEventListener('change', syncModoBadge);
       if (tipoEmprestimo) tipoEmprestimo.addEventListener('change', syncModoBadge);
       syncModoBadge();

       /* Stepper visual: marca etapas como concluídas conforme campos preenchidos. */
       const stepper = document.getElementById('simStepper');
       function syncStepper() {
           if (!stepper) return;
           const steps = stepper.querySelectorAll('li');
           const cedente = document.getElementById('cedente');
           const tomador = document.getElementById('tomador');
           const tipoPag = document.getElementById('tipoPagamento');
           const titulosBody = document.getElementById('titulosBody');
           const isLoan = tipoEmprestimo && tipoEmprestimo.checked;
           const tipoOk = true; // sempre selecionado
           const clienteOk = isLoan ? !!(tomador && tomador.value) : !!(cedente && cedente.value);
           const tributacaoOk = isLoan ? true : !!(tipoPag && tipoPag.value);
           const titulosOk = !!(titulosBody && titulosBody.querySelector('input.valor-original') && titulosBody.querySelector('input.valor-original').value);
           const conferirOk = registerBtn && !registerBtn.disabled;
           const states = [tipoOk, clienteOk, tributacaoOk, titulosOk, conferirOk];
           let activeSet = false;
           steps.forEach((li, idx) => {
               li.classList.remove('is-active', 'is-done');
               if (states[idx]) {
                   li.classList.add('is-done');
                   const num = li.querySelector('.step-num');
                   if (num) num.innerHTML = '<i class="bi bi-check"></i>';
               } else {
                   const num = li.querySelector('.step-num');
                   if (num) num.textContent = String(idx + 1);
                   if (!activeSet) {
                       li.classList.add('is-active');
                       activeSet = true;
                   }
               }
           });
       }
       /* Hooks de mudança em campos-chave para atualizar o stepper */
       ['cedente', 'tomador', 'tipoPagamento', 'tipoAntecipacao', 'tipoEmprestimo'].forEach(id => {
           const el = document.getElementById(id);
           if (el) el.addEventListener('change', syncStepper);
       });
       const titulosBodyEl = document.getElementById('titulosBody');
       if (titulosBodyEl) titulosBodyEl.addEventListener('input', syncStepper);
       if (registerBtn) {
           const obsStep = new MutationObserver(syncStepper);
           obsStep.observe(registerBtn, { attributes: true, attributeFilter: ['disabled'] });
       }
       syncStepper();
   })();
   </script>

   <script>
   /* Stepper clicável + aviso de resumo desatualizado (não interfere no syncStepper). */
   document.addEventListener('DOMContentLoaded', function () {
       // 1) Stepper clicável: rola suavemente até a seção correspondente.
       const stepTargets = { '1': 'simStep1', '2': 'simStep2', '3': 'cardTributacao', '4': 'simStep4', '5': 'summaryPanel' };
       document.querySelectorAll('#simStepper li[data-step]').forEach(function (li) {
           li.style.cursor = 'pointer';
           li.setAttribute('role', 'button');
           li.setAttribute('title', 'Ir para esta etapa');
           li.addEventListener('click', function () {
               const target = document.getElementById(stepTargets[li.dataset.step]);
               if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
           });
       });

       // 2) Aviso de resumo desatualizado: após calcular, se algum campo mudar,
       //    avisa que é preciso recalcular antes de registrar.
       const summaryPanel = document.getElementById('summaryPanel');
       const registerBtn = document.getElementById('registerBtn');
       const calcForm = document.getElementById('calculationForm');
       if (summaryPanel && registerBtn && calcForm) {
           const staleBar = document.createElement('div');
           staleBar.id = 'simStaleWarning';
           staleBar.style.cssText = 'display:none;background:#fff3d6;color:#8a5a00;font-size:.8rem;font-weight:600;padding:8px 14px;border-top:1px solid #f1d999;text-align:center;';
           staleBar.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Os valores mudaram. Clique em <b>Calcular Totais</b> para atualizar o resumo.';
           const actions = summaryPanel.querySelector('.sim-summary-actions');
           if (actions) { actions.parentNode.insertBefore(staleBar, actions); } else { summaryPanel.appendChild(staleBar); }

           let calcFresh = false;
           // registerBtn habilitado = cálculo concluído com sucesso.
           new MutationObserver(function () {
               if (!registerBtn.disabled) { calcFresh = true; staleBar.style.display = 'none'; }
           }).observe(registerBtn, { attributes: true, attributeFilter: ['disabled'] });

           calcForm.addEventListener('input', function (e) {
               if (!calcFresh) return;
               if (e.target.closest('.sim-summary-actions')) return;
               calcFresh = false;
               staleBar.style.display = 'block';
           });
       }
   });
   </script>

</body>
</html>
