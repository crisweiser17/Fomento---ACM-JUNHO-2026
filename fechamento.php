<?php
require_once 'auth_check.php';
?>
<?php
$pageTitle = 'Fechamento Mensal';
$withChart = true;
require_once 'head.php';
?>
    <style>
        /* Estilos específicos da página (tokens e componentes base vêm de theme.css) */
        .summary-card { border-left: 4px solid; border-radius: 8px; }
        .border-recebido { border-color: #0d6efd; }
        .border-capital { border-color: #6c757d; }
        /* Dieta de cor: bordas dos cards no padrão (verde=lucro, vermelho=despesa,
           neutro=repartições). */
        .border-bruto { border-color: var(--app-profit); }
        .border-despesas { border-color: var(--app-danger); }
        .border-liquido { border-color: var(--app-profit); }
        .border-distribuido { border-color: var(--app-neutral); }
        .border-retido { border-color: var(--app-neutral); }
        .scroll-list { max-height: 260px; overflow-y: auto; }
        .scroll-list thead th { position: sticky; top: 0; background-color: #f8f9fa; z-index: 1; }
        .totals-row { background-color: #f8f9fa; border-top: 2px solid #dee2e6; }
    </style>

<div class="container mb-5 app-shell-width">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-wallet2"></i> Fechamento Mensal</h2>
        <div class="d-flex gap-2">
            <select id="mesFiltro" class="form-select w-auto">
                <?php
                $meses = ['01'=>'Janeiro','02'=>'Fevereiro','03'=>'Março','04'=>'Abril','05'=>'Maio','06'=>'Junho',
                          '07'=>'Julho','08'=>'Agosto','09'=>'Setembro','10'=>'Outubro','11'=>'Novembro','12'=>'Dezembro'];
                $mesAtual = date('m');
                foreach($meses as $num => $nome) {
                    $sel = ($num == $mesAtual) ? 'selected' : '';
                    echo "<option value='$num' $sel>$nome</option>";
                }
                ?>
            </select>
            <select id="anoFiltro" class="form-select w-auto">
                <?php
                $anoAtual = date('Y');
                for($i = $anoAtual - 2; $i <= $anoAtual + 2; $i++) {
                    $sel = ($i == $anoAtual) ? 'selected' : '';
                    echo "<option value='$i' $sel>$i</option>";
                }
                ?>
            </select>
            <button class="btn btn-primary" onclick="carregarDados()">Filtrar</button>
        </div>
    </div>

    <!-- Cards Resumo -->
    <div class="row g-3 mb-4">
        <div class="col-md-4 col-lg">
            <div class="card shadow-sm summary-card border-recebido">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Total Recebido</h6>
                    <h3 class="mb-0" id="cardTotalRecebido">R$ 0,00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg">
            <div class="card shadow-sm summary-card border-capital">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Retorno de Capital</h6>
                    <h3 class="mb-0" id="cardRetornoCapital">R$ 0,00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg">
            <div class="card shadow-sm summary-card border-bruto">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Lucro Bruto</h6>
                    <h3 class="mb-0 text-success" id="cardLucroBruto">R$ 0,00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg">
            <div class="card shadow-sm summary-card border-despesas">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Despesas</h6>
                    <h3 class="mb-0 text-danger" id="cardDespesas">R$ 0,00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg">
            <div class="card shadow-sm summary-card border-liquido">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Lucro Líquido</h6>
                    <h3 class="mb-0 text-success" id="cardLucroLiquido">R$ 0,00</h3>
                    <small class="text-muted">Bruto − Despesas</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Linha 2: Distribuição + Retido -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm summary-card border-distribuido">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Distribuído aos Sócios</h6>
                    <h3 class="mb-0" id="cardDistribuido">R$ 0,00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm summary-card border-retido">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Lucro Retido</h6>
                    <h3 class="mb-0" id="cardLucroRetido">R$ 0,00</h3>
                    <small class="text-muted">Líquido − Distribuído</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráficos -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Composição do Recebimento</h5>
                </div>
                <div class="card-body d-flex justify-content-center align-items-center">
                    <div style="width: 300px; height: 300px;">
                        <canvas id="chartComposicao"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Lucro vs Despesas</h5>
                </div>
                <div class="card-body d-flex justify-content-center align-items-center">
                    <div style="width: 300px; height: 300px;">
                        <canvas id="chartLucroDespesas"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Módulo de Despesas -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Despesas do Mês</h5>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalDespesa">
                <i class="bi bi-plus-lg"></i> Adicionar Despesa
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Data</th>
                            <th>Título</th>
                            <th>Descrição</th>
                            <th class="text-end">Valor</th>
                            <th class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tabelaDespesas">
                        <!-- Preenchido via JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Módulo de Títulos Atrasados -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0 text-danger"><i class="bi bi-exclamation-triangle"></i> Títulos Atrasados/Inadimplentes</h5>
        </div>
        <div class="card-body p-0">
            <div class="scroll-list">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Vencimento</th>
                            <th>Dias de Atraso</th>
                            <th>Título</th>
                            <th>Pagador (Sacado)</th>
                            <th class="text-end">Valor Original</th>
                        </tr>
                    </thead>
                    <tbody id="tabelaAtrasados">
                        <!-- Preenchido via JS -->
                    </tbody>
                </table>
            </div>
            <div class="totals-row d-flex justify-content-between align-items-center px-3 py-2">
                <span class="fw-bold" id="atrasadosCount">0 títulos</span>
                <span class="fw-bold text-danger fs-5" id="atrasadosTotal">R$ 0,00</span>
            </div>
        </div>
    </div>

    <!-- Módulo de Distribuição de Lucros -->
    <div class="card shadow-sm mb-5">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-success"><i class="bi bi-cash-coin"></i> Distribuição de Lucros para Sócios</h5>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalDistribuicao">
                <i class="bi bi-plus-lg"></i> Registrar Distribuição
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Data</th>
                            <th>Sócio</th>
                            <th class="text-end">Valor</th>
                            <th class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tabelaDistribuicao">
                        <!-- Preenchido via JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Modal Adicionar Despesa -->
<div class="modal fade" id="modalDespesa" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="formDespesa" onsubmit="salvarDespesa(event)">
      <div class="modal-header">
        <h5 class="modal-title">Nova Despesa</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
          <input type="hidden" name="action" value="add">
          <div class="mb-3">
              <label class="form-label">Data da Despesa <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="data_despesa" id="dataDespesa" required>
          </div>
          <div class="mb-3">
              <label class="form-label">Título <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="titulo" required>
          </div>
          <div class="mb-3">
              <label class="form-label">Descrição</label>
              <textarea class="form-control" name="descricao" rows="2"></textarea>
          </div>
          <div class="mb-3">
              <label class="form-label">Valor (R$) <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="valor" id="valorDespesa" placeholder="0,00" required>
          </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar Despesa</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Registrar Distribuição de Lucros -->
<div class="modal fade" id="modalDistribuicao" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content border-success">
      <form id="formDistribuicao" onsubmit="salvarDistribuicao(event)">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title">Nova Distribuição de Lucros</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
          <input type="hidden" name="action" value="add">
          <div class="mb-3">
              <label class="form-label">Data <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="data" id="dataDistribuicao" required>
          </div>
          <div class="mb-3">
              <label class="form-label">Sócio <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="socio_nome" placeholder="Nome do Sócio" required>
          </div>
          <div class="mb-3">
              <label class="form-label">Valor (R$) <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="valor" id="valorDistribuicao" placeholder="0,00" required>
          </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-success">Salvar Distribuição</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Scripts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
let composicaoChart = null;
let lucroDespesasChart = null;

function formatCurrency(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
}

function formatDate(dateStr) {
    if(!dateStr) return '';
    const parts = dateStr.split('-');
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

// Máscara simples para valor
document.getElementById('valorDespesa').addEventListener('input', applyCurrencyMask);
document.getElementById('valorDistribuicao').addEventListener('input', applyCurrencyMask);

function applyCurrencyMask(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length > 0) {
        value = (parseInt(value) / 100).toFixed(2) + '';
        value = value.replace('.', ',');
        value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
    }
    e.target.value = value;
}

// Set default date in modal to current filter month
function setDefaultDate(modalId, inputId) {
    document.getElementById(modalId).addEventListener('show.bs.modal', function () {
        const m = document.getElementById('mesFiltro').value;
        const a = document.getElementById('anoFiltro').value;
        const d = new Date();
        let day = d.getDate();
        if(d.getMonth() + 1 != parseInt(m) || d.getFullYear() != parseInt(a)) {
            day = 1;
        }
        const dayStr = day.toString().padStart(2, '0');
        document.getElementById(inputId).value = `${a}-${m}-${dayStr}`;
    });
}
setDefaultDate('modalDespesa', 'dataDespesa');
setDefaultDate('modalDistribuicao', 'dataDistribuicao');

async function carregarDados() {
    const mes = document.getElementById('mesFiltro').value;
    const ano = document.getElementById('anoFiltro').value;

    try {
        // Carregar Cards e Gráficos
        const resFechamento = await fetch(`api_fechamento.php?mes=${mes}&ano=${ano}`);
        const jsonFechamento = await resFechamento.json();

        if (jsonFechamento.success) {
            const d = jsonFechamento.data;
            document.getElementById('cardTotalRecebido').textContent = formatCurrency(d.total_recebido);
            document.getElementById('cardRetornoCapital').textContent = formatCurrency(d.retorno_capital);
            document.getElementById('cardLucroBruto').textContent = formatCurrency(d.lucro_bruto);
            document.getElementById('cardDespesas').textContent = formatCurrency(d.total_despesas);
            document.getElementById('cardLucroLiquido').textContent = formatCurrency(d.lucro_liquido);
            document.getElementById('cardDistribuido').textContent = formatCurrency(d.total_distribuido || 0);
            document.getElementById('cardLucroRetido').textContent = formatCurrency(d.lucro_retido || 0);
            
            atualizarGraficos(d);

            // Carregar Títulos Atrasados
            const tbodyAtrasados = document.getElementById('tabelaAtrasados');
            const countEl = document.getElementById('atrasadosCount');
            const totalEl = document.getElementById('atrasadosTotal');
            tbodyAtrasados.innerHTML = '';
            if (d.titulos_atrasados && d.titulos_atrasados.length > 0) {
                let total = 0;
                d.titulos_atrasados.forEach(titulo => {
                    total += parseFloat(titulo.valor_original);
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${formatDate(titulo.data_vencimento)}</td>
                        <td><span class="badge bg-danger">${titulo.dias_atraso} dias</span></td>
                        <td><strong>${titulo.id}</strong></td>
                        <td>${titulo.pagador_nome || '-'}</td>
                        <td class="text-end text-danger">${formatCurrency(titulo.valor_original)}</td>
                    `;
                    tbodyAtrasados.appendChild(tr);
                });
                const n = d.titulos_atrasados.length;
                countEl.textContent = `${n} título${n > 1 ? 's' : ''}`;
                totalEl.textContent = formatCurrency(total);
            } else {
                tbodyAtrasados.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Nenhum título em atraso até o fim do mês.</td></tr>';
                countEl.textContent = '0 títulos';
                totalEl.textContent = formatCurrency(0);
            }

        } else {
            alert('Erro ao carregar fechamento: ' + jsonFechamento.message);
        }

        // Carregar Despesas
        const resDespesas = await fetch(`api_despesas.php?action=list&mes=${mes}&ano=${ano}`);
        const jsonDespesas = await resDespesas.json();
        
        const tbody = document.getElementById('tabelaDespesas');
        tbody.innerHTML = '';
        
        if (jsonDespesas.success && jsonDespesas.data.length > 0) {
            jsonDespesas.data.forEach(desp => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${formatDate(desp.data_despesa)}</td>
                    <td><strong>${desp.titulo}</strong></td>
                    <td class="text-muted small">${desp.descricao || '-'}</td>
                    <td class="text-end text-danger">${formatCurrency(desp.valor)}</td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-danger" onclick="excluirDespesa(${desp.id})" title="Excluir">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        } else {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Nenhuma despesa registrada neste mês.</td></tr>';
        }

        // Carregar Distribuição de Lucros
        const resDistribuicao = await fetch(`api_distribuicao_lucros.php?action=list&mes=${mes}&ano=${ano}`);
        const jsonDistribuicao = await resDistribuicao.json();
        
        const tbodyDistribuicao = document.getElementById('tabelaDistribuicao');
        tbodyDistribuicao.innerHTML = '';
        
        if (jsonDistribuicao.success && jsonDistribuicao.data.length > 0) {
            jsonDistribuicao.data.forEach(dist => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${formatDate(dist.data)}</td>
                    <td><strong>${dist.socio_nome}</strong></td>
                    <td class="text-end text-success">${formatCurrency(dist.valor)}</td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-danger" onclick="excluirDistribuicao(${dist.id})" title="Excluir">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                `;
                tbodyDistribuicao.appendChild(tr);
            });
        } else {
            tbodyDistribuicao.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted">Nenhuma distribuição registrada neste mês.</td></tr>';
        }

    } catch (err) {
        console.error(err);
        alert('Erro na comunicação com o servidor.');
    }
}

function atualizarGraficos(data) {
    // Gráfico de Composição (Retorno vs Lucro Bruto)
    const ctx1 = document.getElementById('chartComposicao').getContext('2d');
    if (composicaoChart) composicaoChart.destroy();
    
    composicaoChart = new Chart(ctx1, {
        type: 'doughnut',
        data: {
            labels: ['Retorno de Capital', 'Lucro Bruto'],
            datasets: [{
                data: [data.retorno_capital, data.lucro_bruto],
                backgroundColor: ['#6c757d', '#ffc107'],
                borderWidth: 1
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });

    // Gráfico de Lucro vs Despesas
    const ctx2 = document.getElementById('chartLucroDespesas').getContext('2d');
    if (lucroDespesasChart) lucroDespesasChart.destroy();
    
    // Evitar valores negativos no gráfico se houver prejuízo, mas mostrar a proporção
    const lucroFinal = Math.max(0, data.lucro_liquido);
    
    lucroDespesasChart = new Chart(ctx2, {
        type: 'pie',
        data: {
            labels: ['Lucro Líquido', 'Despesas'],
            datasets: [{
                data: [lucroFinal, data.total_despesas],
                backgroundColor: ['#198754', '#dc3545'],
                borderWidth: 1
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
}

async function salvarDespesa(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    try {
        const res = await fetch('api_despesas.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        
        if(json.success) {
            const modalEl = document.getElementById('modalDespesa');
            const modal = bootstrap.Modal.getInstance(modalEl);
            modal.hide();
            e.target.reset();
            carregarDados(); // Recarrega cards, gráficos e tabela
        } else {
            alert('Erro: ' + json.message);
        }
    } catch(err) {
        console.error(err);
        alert('Erro ao salvar despesa.');
    }
}

async function excluirDespesa(id) {
    if(!confirm('Tem certeza que deseja excluir esta despesa?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    
    try {
        const res = await fetch('api_despesas.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        if(json.success) {
            carregarDados();
        } else {
            alert('Erro ao excluir: ' + json.message);
        }
    } catch(err) {
        console.error(err);
        alert('Erro ao excluir despesa.');
    }
}

async function salvarDistribuicao(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    try {
        const res = await fetch('api_distribuicao_lucros.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        
        if(json.success) {
            const modalEl = document.getElementById('modalDistribuicao');
            const modal = bootstrap.Modal.getInstance(modalEl);
            modal.hide();
            e.target.reset();
            carregarDados(); // Recarrega cards, gráficos e tabela
        } else {
            alert('Erro: ' + json.message);
        }
    } catch(err) {
        console.error(err);
        alert('Erro ao salvar distribuição.');
    }
}

async function excluirDistribuicao(id) {
    if(!confirm('Tem certeza que deseja excluir esta distribuição?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    
    try {
        const res = await fetch('api_distribuicao_lucros.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        if(json.success) {
            carregarDados();
        } else {
            alert('Erro ao excluir: ' + json.message);
        }
    } catch(err) {
        console.error(err);
        alert('Erro ao excluir distribuição.');
    }
}

// Carregar dados iniciais
carregarDados();

});
</script>
</body>
</html>
