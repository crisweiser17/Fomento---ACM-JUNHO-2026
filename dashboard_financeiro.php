<?php require_once 'auth_check.php'; ?>
<?php
$pageTitle = 'Dashboard Financeiro';
$withChart = true;
require_once 'head.php';
?>
    <style>
        /* Estilos específicos do dashboard (tokens, body bg e componentes base vêm de theme.css) */
        .card-metric { transition: transform 0.2s; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 1rem; }
        .card-metric:hover { transform: translateY(-5px); }
        .metric-title { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: #6c757d; font-weight: 600; }
        .metric-value { font-size: 1.5rem; font-weight: bold; color: #343a40; }
        .metric-value-lg { font-size: 1.8rem; }
        /* Hierarquia: a métrica-herói (Lucro Realizado) recebe destaque sutil.
           Lucro = verde (regra de cor), inclusive no herói. */
        .card-metric.is-hero { background: var(--app-profit-soft, #d1f0dc); }
        .card-metric.is-hero .metric-value { font-size: 2.15rem; color: var(--app-profit, #198754); }
        .card-metric.is-hero .metric-title { color: var(--app-profit, #198754); }
        .table-container { background: #fff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); padding: 1.5rem; margin-bottom: 1.5rem; }
        .section-title { border-bottom: 2px solid #dee2e6; padding-bottom: 10px; margin-bottom: 20px; color: #495057; font-size: 1.1rem; }
        .bg-light-blue { background-color: #e9f2fb; }
        .bg-light-green { background-color: #e9fbed; }
        .scroll-list { max-height: 260px; overflow-y: auto; }
        .scroll-list thead th { position: sticky; top: 0; background-color: #f8f9fa; z-index: 1; }
        .totals-row { background-color: #f8f9fa; border-top: 2px solid #dee2e6; }
        .trend { font-size: 0.75rem; font-weight: 600; padding: 2px 6px; border-radius: 4px; display: inline-block; }
        .trend-up { color: var(--app-profit); background-color: var(--app-profit-soft); }
        .trend-down { color: var(--app-danger); background-color: var(--app-danger-soft); }
        .trend-flat { color: #6c757d; background-color: #e9ecef; }
    </style>

    <div class="container-fluid px-4 py-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h2 class="mb-0"><i class="bi bi-graph-up-arrow text-primary"></i> Dashboard Financeiro</h2>
            <div class="d-flex align-items-center gap-2">
                <label for="periodFilter" class="form-label mb-0 small text-muted">Período:</label>
                <select id="periodFilter" class="form-select form-select-sm" style="width: auto;">
                    <option value="all" selected>Tudo</option>
                    <option value="this_month">Este mês</option>
                    <option value="last_month">Mês passado</option>
                    <option value="last_30">Últimos 30 dias</option>
                    <option value="last_90">Últimos 90 dias</option>
                    <option value="this_year">Este ano</option>
                </select>
            </div>
        </div>

        <!-- Visão Geral do Fundo -->
        <div class="table-container mb-4">
            <h5 class="section-title"><i class="bi bi-globe"></i> Visão Geral do Fundo</h5>
            <div class="row">
                <div class="col-md">
                    <div class="card card-metric border-start border-4 border-primary">
                        <div class="card-body">
                            <div class="metric-title">Capital Adiantado</div>
                            <div class="metric-value metric-value-lg" id="geral-capital">R$ 0,00</div>
                            <span class="trend d-none" id="geral-capital-trend"></span>
                        </div>
                    </div>
                </div>
                <div class="col-md">
                    <div class="card card-metric border-start border-4 border-success">
                        <div class="card-body">
                            <div class="metric-title">Amortizado</div>
                            <div class="metric-value metric-value-lg" id="geral-amortizado">R$ 0,00</div>
                            <span class="trend d-none" id="geral-amortizado-trend"></span>
                        </div>
                    </div>
                </div>
                <div class="col-md">
                    <div class="card card-metric is-hero border-start border-4 border-success">
                        <div class="card-body">
                            <div class="metric-title">Lucro Realizado</div>
                            <div class="metric-value metric-value-lg" id="geral-lucro">R$ 0,00</div>
                            <span class="trend d-none" id="geral-lucro-trend"></span>
                        </div>
                    </div>
                </div>
                <div class="col-md">
                    <div class="card card-metric border-start border-4 border-warning">
                        <div class="card-body">
                            <div class="metric-title">Lucro Projetado</div>
                            <div class="metric-value metric-value-lg" id="geral-projetado">R$ 0,00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md">
                    <div class="card card-metric border-start border-4 border-danger">
                        <div class="card-body">
                            <div class="metric-title">Inadimplência Total</div>
                            <div class="metric-value metric-value-lg" id="geral-inadimplencia">R$ 0,00</div>
                            <div class="text-muted small mt-1" id="geral-taxa-inadimplencia">Taxa: 0.00%</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Caixa -->
        <div class="table-container mb-4">
            <h5 class="section-title"><i class="bi bi-piggy-bank"></i> Caixa Realizado</h5>
            <div class="row">
                <div class="col-md-3">
                    <div class="card card-metric border-start border-4 border-success">
                        <div class="card-body">
                            <div class="metric-title">Lucro Acumulado</div>
                            <div class="metric-value text-success" id="caixa-lucro">R$ 0,00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-metric border-start border-4 border-danger">
                        <div class="card-body">
                            <div class="metric-title">Despesas Pagas</div>
                            <div class="metric-value text-danger" id="caixa-despesas">R$ 0,00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-metric border-start border-4" style="border-color: var(--app-neutral) !important;">
                        <div class="card-body">
                            <div class="metric-title">Distribuído aos Sócios</div>
                            <div class="metric-value" id="caixa-distribuido">R$ 0,00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-metric border-start border-4 border-success">
                        <div class="card-body">
                            <div class="metric-title">Caixa Disponível</div>
                            <div class="metric-value text-success" id="caixa-disponivel">R$ 0,00</div>
                            <div class="text-muted small mt-1">Lucro − Despesas − Distribuições</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Lado a Lado -->
        <div class="row">
            <!-- Empréstimos -->
            <div class="col-xl-6 mb-4">
                <div class="table-container h-100 bg-light-blue">
                    <h5 class="section-title"><i class="bi bi-bank"></i> Performance de Empréstimos</h5>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="card card-metric">
                                <div class="card-body py-2">
                                    <div class="metric-title">Capital Adiantado</div>
                                    <div class="metric-value text-primary" id="emp-capital">R$ 0,00</div>
                                    <span class="trend d-none" id="emp-capital-trend"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="card card-metric">
                                <div class="card-body py-2">
                                    <div class="metric-title">Amortizado</div>
                                    <div class="metric-value text-success" id="emp-amortizado">R$ 0,00</div>
                                    <span class="trend d-none" id="emp-amortizado-trend"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="card card-metric">
                                <div class="card-body py-2">
                                    <div class="metric-title">Lucro Realizado</div>
                                    <div class="metric-value text-info" id="emp-lucro">R$ 0,00</div>
                                    <span class="trend d-none" id="emp-lucro-trend"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="card card-metric">
                                <div class="card-body py-2">
                                    <div class="metric-title">Inadimplência</div>
                                    <div class="metric-value text-danger" id="emp-inadimplencia">R$ 0,00</div>
                                    <div class="text-muted small" style="font-size: 0.75rem;" id="emp-taxa-inadimplencia">Taxa: 0.00%</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h6 class="mt-3"><i class="bi bi-list-ol"></i> Top 5 Tomadores de Empréstimo</h6>
                    <div class="table-responsive bg-white rounded p-2 border">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tomador</th>
                                    <th class="text-end">Em Aberto</th>
                                    <th class="text-end">Vencido</th>
                                </tr>
                            </thead>
                            <tbody id="emp-top5-body">
                                <tr><td colspan="3" class="text-center text-muted">Carregando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Antecipações -->
            <div class="col-xl-6 mb-4">
                <div class="table-container h-100 bg-light-green">
                    <h5 class="section-title"><i class="bi bi-cash-coin"></i> Performance de Antecipações</h5>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="card card-metric">
                                <div class="card-body py-2">
                                    <div class="metric-title">Capital Adiantado</div>
                                    <div class="metric-value text-primary" id="ant-capital">R$ 0,00</div>
                                    <span class="trend d-none" id="ant-capital-trend"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="card card-metric">
                                <div class="card-body py-2">
                                    <div class="metric-title">Amortizado</div>
                                    <div class="metric-value text-success" id="ant-amortizado">R$ 0,00</div>
                                    <span class="trend d-none" id="ant-amortizado-trend"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="card card-metric">
                                <div class="card-body py-2">
                                    <div class="metric-title">Lucro Realizado</div>
                                    <div class="metric-value text-info" id="ant-lucro">R$ 0,00</div>
                                    <span class="trend d-none" id="ant-lucro-trend"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="card card-metric">
                                <div class="card-body py-2">
                                    <div class="metric-title">Inadimplência</div>
                                    <div class="metric-value text-danger" id="ant-inadimplencia">R$ 0,00</div>
                                    <div class="text-muted small" style="font-size: 0.75rem;" id="ant-taxa-inadimplencia">Taxa: 0.00%</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-6 mb-3">
                            <h6><i class="bi bi-list-ol"></i> Top 5 Cedentes</h6>
                            <div class="table-responsive bg-white rounded p-2 border">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Cedente</th>
                                            <th class="text-end">Risco</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ant-top5-cedentes-body">
                                        <tr><td colspan="2" class="text-center text-muted">Carregando...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <h6><i class="bi bi-list-ol"></i> Top 5 Sacados</h6>
                            <div class="table-responsive bg-white rounded p-2 border">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Sacado</th>
                                            <th class="text-end">Risco</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ant-top5-sacados-body">
                                        <tr><td colspan="2" class="text-center text-muted">Carregando...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recebíveis Inadimplentes -->
        <div class="table-container mb-4">
            <h5 class="section-title text-danger"><i class="bi bi-exclamation-triangle"></i> Recebíveis Inadimplentes</h5>
            <div class="scroll-list border rounded">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Vencimento</th>
                            <th class="text-center">Atraso</th>
                            <th>Tipo</th>
                            <th>Operação</th>
                            <th>Pagador</th>
                            <th class="text-end">Valor Original</th>
                        </tr>
                    </thead>
                    <tbody id="inadimplentes-body">
                        <tr><td colspan="6" class="text-center text-muted py-3">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="totals-row d-flex justify-content-between align-items-center px-3 py-2 mt-2 rounded">
                <span class="fw-bold" id="inadimplentes-count">0 títulos</span>
                <span class="fw-bold text-danger fs-5" id="inadimplentes-total">R$ 0,00</span>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="row">
            <div class="col-lg-4 mb-4">
                <div class="table-container h-100">
                    <h5 class="section-title"><i class="bi bi-pie-chart"></i> Composição da Carteira</h5>
                    <div style="position: relative; height: 300px;">
                        <canvas id="chartComposicao"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-8 mb-4">
                <div class="table-container h-100">
                    <h5 class="section-title"><i class="bi bi-bar-chart"></i> Lucro Realizado (Últimos 12 Meses)</h5>
                    <div style="position: relative; height: 300px;">
                        <canvas id="chartLucro12m"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos 2 -->
        <div class="row">
            <div class="col-lg-4 mb-4">
                <div class="table-container h-100">
                    <h5 class="section-title"><i class="bi bi-clock-history"></i> Aging da Inadimplência</h5>
                    <div style="position: relative; height: 300px;">
                        <canvas id="chartAging"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-8 mb-4">
                <div class="table-container h-100">
                    <h5 class="section-title"><i class="bi bi-calendar-event"></i> Recebimentos Projetados (Próx 6 Meses)</h5>
                    <div style="position: relative; height: 300px;">
                        <canvas id="chartProjetado"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        const formatCurrency = (value) => {
            return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
        };

        const formatCurrencyCompact = (value) => {
            return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 }).format(value);
        };

        const formatDate = (dateStr) => {
            if (!dateStr) return '';
            const parts = dateStr.split('-');
            return `${parts[2]}/${parts[1]}/${parts[0]}`;
        };

        const renderTrend = (elId, current, previous, isAll) => {
            const el = document.getElementById(elId);
            if (!el) return;
            if (isAll) {
                el.classList.add('d-none');
                return;
            }
            el.classList.remove('d-none');
            if (previous === 0 || previous === null || previous === undefined) {
                if (current > 0) {
                    el.textContent = '↑ novo';
                    el.className = 'trend trend-up';
                } else {
                    el.textContent = '— sem ref.';
                    el.className = 'trend trend-flat';
                }
                return;
            }
            const pct = ((current - previous) / Math.abs(previous)) * 100;
            const sign = pct >= 0 ? '↑' : '↓';
            const cls = pct > 0 ? 'trend-up' : (pct < 0 ? 'trend-down' : 'trend-flat');
            el.className = 'trend ' + cls;
            el.textContent = `${sign} ${Math.abs(pct).toFixed(1)}% vs anterior`;
        };

        const renderInadimplentes = (lista) => {
            const tbody = document.getElementById('inadimplentes-body');
            const countEl = document.getElementById('inadimplentes-count');
            const totalEl = document.getElementById('inadimplentes-total');
            tbody.innerHTML = '';

            if (!lista || lista.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Nenhum recebível inadimplente.</td></tr>';
                countEl.textContent = '0 títulos';
                totalEl.textContent = formatCurrency(0);
                return;
            }

            let total = 0;
            lista.forEach(item => {
                total += parseFloat(item.valor_original);
                const tipoBadge = item.tipo_operacao === 'emprestimo'
                    ? '<span class="badge bg-primary">Empréstimo</span>'
                    : '<span class="badge bg-success">Antecipação</span>';
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${formatDate(item.data_vencimento)}</td>
                    <td class="text-center"><span class="badge bg-danger">${item.dias_atraso}d</span></td>
                    <td>${tipoBadge}</td>
                    <td><a href="detalhes_operacao.php?id=${item.operacao_id}" class="text-decoration-none">#${item.operacao_id}</a></td>
                    <td class="text-truncate" style="max-width: 240px;" title="${item.pagador_nome}">${item.pagador_nome}</td>
                    <td class="text-end text-danger fw-semibold">${formatCurrency(item.valor_original)}</td>
                `;
                tbody.appendChild(tr);
            });

            countEl.textContent = `${lista.length} título${lista.length > 1 ? 's' : ''}`;
            totalEl.textContent = formatCurrency(total);
        };

        const renderTop5 = (data, tbodyId, showVencido = true) => {
            const tbody = document.getElementById(tbodyId);
            tbody.innerHTML = '';
            
            if (!data || data.length === 0) {
                const cols = showVencido ? 3 : 2;
                tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted">Nenhum dado</td></tr>`;
                return;
            }

            data.forEach(item => {
                const tr = document.createElement('tr');
                let html = `<td class="text-truncate" style="max-width: 150px;" title="${item.nome}">${item.nome}</td>
                            <td class="text-end">${formatCurrency(item.valor_em_aberto)}</td>`;
                if (showVencido) {
                    html += `<td class="text-end text-danger">${formatCurrency(item.valor_vencido)}</td>`;
                }
                tr.innerHTML = html;
                tbody.appendChild(tr);
            });
        };

        let chartInstances = {};

        const carregarDashboard = async () => {
            try {
                const period = document.getElementById('periodFilter').value || 'all';
                const isAll = period === 'all';
                const response = await fetch('api_dashboard_financeiro.php?period=' + encodeURIComponent(period));
                const result = await response.json();

                if (result.success && result.data) {
                    const d = result.data;
                    const prev = d.previous || { geral: {}, emprestimo: {}, antecipacao: {} };

                    // Geral
                    if (d.geral) {
                        document.getElementById('geral-capital').textContent = formatCurrency(d.geral.capital_adiantado);
                        document.getElementById('geral-amortizado').textContent = formatCurrency(d.geral.amortizado);
                        document.getElementById('geral-lucro').textContent = formatCurrency(d.geral.lucro_realizado);
                        document.getElementById('geral-projetado').textContent = formatCurrency(d.geral.lucro_projetado);
                        document.getElementById('geral-inadimplencia').textContent = formatCurrency(d.geral.inadimplencia);
                        renderTrend('geral-capital-trend', d.geral.capital_adiantado, prev.geral.capital_adiantado, isAll);
                        renderTrend('geral-amortizado-trend', d.geral.amortizado, prev.geral.amortizado, isAll);
                        renderTrend('geral-lucro-trend', d.geral.lucro_realizado, prev.geral.lucro_realizado, isAll);
                    }

                    // Empréstimo
                    if (d.emprestimo) {
                        document.getElementById('emp-capital').textContent = formatCurrency(d.emprestimo.capital_adiantado);
                        document.getElementById('emp-amortizado').textContent = formatCurrency(d.emprestimo.amortizado);
                        document.getElementById('emp-lucro').textContent = formatCurrency(d.emprestimo.lucro_realizado);
                        document.getElementById('emp-inadimplencia').textContent = formatCurrency(d.emprestimo.inadimplencia);
                        renderTop5(d.emprestimo.top_5_tomadores, 'emp-top5-body', true);
                        renderTrend('emp-capital-trend', d.emprestimo.capital_adiantado, prev.emprestimo.capital_adiantado, isAll);
                        renderTrend('emp-amortizado-trend', d.emprestimo.amortizado, prev.emprestimo.amortizado, isAll);
                        renderTrend('emp-lucro-trend', d.emprestimo.lucro_realizado, prev.emprestimo.lucro_realizado, isAll);
                    }

                    // Caixa
                    if (d.caixa) {
                        document.getElementById('caixa-lucro').textContent = formatCurrency(d.caixa.lucro_acumulado);
                        document.getElementById('caixa-despesas').textContent = formatCurrency(d.caixa.despesas_pagas);
                        document.getElementById('caixa-distribuido').textContent = formatCurrency(d.caixa.distribuido);
                        const elCaixa = document.getElementById('caixa-disponivel');
                        elCaixa.textContent = formatCurrency(d.caixa.caixa_realizado);
                        elCaixa.classList.toggle('text-success', d.caixa.caixa_realizado >= 0);
                        elCaixa.classList.toggle('text-danger', d.caixa.caixa_realizado < 0);
                    }

                    // Lista de Inadimplentes
                    renderInadimplentes(d.inadimplentes || []);

                    // Antecipação
                    if (d.antecipacao) {
                        document.getElementById('ant-capital').textContent = formatCurrency(d.antecipacao.capital_adiantado);
                        document.getElementById('ant-amortizado').textContent = formatCurrency(d.antecipacao.amortizado);
                        document.getElementById('ant-lucro').textContent = formatCurrency(d.antecipacao.lucro_realizado);
                        document.getElementById('ant-inadimplencia').textContent = formatCurrency(d.antecipacao.inadimplencia);
                        renderTop5(d.antecipacao.top_5_cedentes, 'ant-top5-cedentes-body', false);
                        renderTop5(d.antecipacao.top_5_sacados, 'ant-top5-sacados-body', false);
                        renderTrend('ant-capital-trend', d.antecipacao.capital_adiantado, prev.antecipacao.capital_adiantado, isAll);
                        renderTrend('ant-amortizado-trend', d.antecipacao.amortizado, prev.antecipacao.amortizado, isAll);
                        renderTrend('ant-lucro-trend', d.antecipacao.lucro_realizado, prev.antecipacao.lucro_realizado, isAll);
                    }

                    // Gráfico de Composição (Pizza) - Empréstimo vs Antecipação em Aberto
                    const ctxComposicao = document.getElementById('chartComposicao').getContext('2d');
                    const empAberto = Math.max(0, d.emprestimo.capital_adiantado - d.emprestimo.amortizado);
                    const antAberto = Math.max(0, d.antecipacao.capital_adiantado - d.antecipacao.amortizado);

                    if (chartInstances.composicao) chartInstances.composicao.destroy();
                    chartInstances.composicao = new Chart(ctxComposicao, {
                        type: 'pie',
                        data: {
                            labels: ['Empréstimos (Risco)', 'Antecipações (Risco)'],
                            datasets: [{
                                data: [empAberto, antAberto],
                                backgroundColor: ['#0d6efd', '#20c997'],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                datalabels: {
                                    color: '#fff',
                                    font: { weight: 'bold' },
                                    formatter: (value, ctx) => {
                                        return value > 0 ? formatCurrencyCompact(value) : '';
                                    }
                                },
                                legend: { position: 'bottom' },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.label || '';
                                            if (label) label += ': ';
                                            label += formatCurrency(context.raw);
                                            return label;
                                        }
                                    }
                                }
                            }
                        }
                    });

                    // Gráfico de Lucro 12m (Barras)
                    if (d.grafico_lucro_12m) {
                        const ctxLucro = document.getElementById('chartLucro12m').getContext('2d');
                        if (chartInstances.lucro12m) chartInstances.lucro12m.destroy();
                        chartInstances.lucro12m = new Chart(ctxLucro, {
                            type: 'bar',
                            data: {
                                labels: d.grafico_lucro_12m.meses,
                                datasets: [
                                    {
                                        label: 'Empréstimo',
                                        data: d.grafico_lucro_12m.emprestimo,
                                        backgroundColor: '#0d6efd'
                                    },
                                    {
                                        label: 'Antecipação',
                                        data: d.grafico_lucro_12m.antecipacao,
                                        backgroundColor: '#20c997'
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                layout: { padding: { top: 20 } },
                                scales: {
                                    x: { stacked: true },
                                    y: { 
                                        stacked: true,
                                        ticks: {
                                            callback: function(value) {
                                                return 'R$ ' + value;
                                            }
                                        }
                                    }
                                },
                                plugins: {
                                    datalabels: {
                                        anchor: 'end',
                                        align: 'top',
                                        color: '#495057',
                                        font: { weight: 'bold', size: 10 },
                                        formatter: (value, ctx) => {
                                            let sum = 0;
                                            let dataArr = ctx.chart.data.datasets;
                                            if (ctx.datasetIndex === dataArr.length - 1) {
                                                dataArr.forEach(dataset => {
                                                    sum += dataset.data[ctx.dataIndex];
                                                });
                                                if (sum > 0) return formatCurrencyCompact(sum);
                                            }
                                            return '';
                                        }
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                let label = context.dataset.label || '';
                                                if (label) label += ': ';
                                                label += formatCurrency(context.raw);
                                                return label;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }

                    // Cálculo de Taxas de Inadimplência
                    // Usa capital em risco (valor_liquido) para numerador e denominador na mesma base
                    const calcTaxa = (inadimplenciaCapital, capital, amortizado) => {
                        const emAberto = Math.max(0, capital - amortizado);
                        if (emAberto > 0) {
                            return ((inadimplenciaCapital / emAberto) * 100).toFixed(2) + '%';
                        }
                        return '0.00%';
                    };

                    if (d.geral) {
                        document.getElementById('geral-taxa-inadimplencia').textContent = 'Taxa: ' + calcTaxa(d.geral.inadimplencia_capital, d.geral.capital_adiantado, d.geral.amortizado);
                    }
                    if (d.emprestimo) {
                        document.getElementById('emp-taxa-inadimplencia').textContent = 'Taxa: ' + calcTaxa(d.emprestimo.inadimplencia_capital, d.emprestimo.capital_adiantado, d.emprestimo.amortizado);
                    }
                    if (d.antecipacao) {
                        document.getElementById('ant-taxa-inadimplencia').textContent = 'Taxa: ' + calcTaxa(d.antecipacao.inadimplencia_capital, d.antecipacao.capital_adiantado, d.antecipacao.amortizado);
                    }

                    // Gráfico de Aging - Escada padrão (1-30 / 31-60 / 61-90 / 90+)
                    if (d.aging) {
                        const ctxAging = document.getElementById('chartAging').getContext('2d');
                        if (chartInstances.aging) chartInstances.aging.destroy();
                        chartInstances.aging = new Chart(ctxAging, {
                            type: 'doughnut',
                            data: {
                                labels: ['1 a 30 dias', '31 a 60 dias', '61 a 90 dias', 'Mais de 90 dias'],
                                datasets: [{
                                    data: [d.aging.ate_30_dias, d.aging.de_31_a_60_dias, d.aging.de_61_a_90_dias, d.aging.mais_de_90_dias],
                                    backgroundColor: ['#ffc107', '#fd7e14', '#dc3545', '#7c2929'],
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    datalabels: {
                                        color: '#fff',
                                        font: { weight: 'bold' },
                                        formatter: (value, ctx) => {
                                            return value > 0 ? formatCurrencyCompact(value) : '';
                                        }
                                    },
                                    legend: { position: 'bottom' },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                let label = context.label || '';
                                                if (label) label += ': ';
                                                label += formatCurrency(context.raw);
                                                return label;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }

                    // Gráfico de Recebimentos Projetados
                    if (d.grafico_recebimentos) {
                        const ctxProj = document.getElementById('chartProjetado').getContext('2d');
                        if (chartInstances.projetado) chartInstances.projetado.destroy();
                        chartInstances.projetado = new Chart(ctxProj, {
                            type: 'bar',
                            data: {
                                labels: d.grafico_recebimentos.meses,
                                datasets: [
                                    {
                                        label: 'Empréstimo',
                                        data: d.grafico_recebimentos.emprestimo,
                                        backgroundColor: '#0d6efd'
                                    },
                                    {
                                        label: 'Antecipação',
                                        data: d.grafico_recebimentos.antecipacao,
                                        backgroundColor: '#20c997'
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                layout: { padding: { top: 20 } },
                                scales: {
                                    x: { stacked: true },
                                    y: { 
                                        stacked: true,
                                        ticks: {
                                            callback: function(value) {
                                                return 'R$ ' + value;
                                            }
                                        }
                                    }
                                },
                                plugins: {
                                    datalabels: {
                                        anchor: 'end',
                                        align: 'top',
                                        color: '#495057',
                                        font: { weight: 'bold', size: 10 },
                                        formatter: (value, ctx) => {
                                            let sum = 0;
                                            let dataArr = ctx.chart.data.datasets;
                                            if (ctx.datasetIndex === dataArr.length - 1) {
                                                dataArr.forEach(dataset => {
                                                    sum += dataset.data[ctx.dataIndex];
                                                });
                                                if (sum > 0) return formatCurrencyCompact(sum);
                                            }
                                            return '';
                                        }
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                let label = context.dataset.label || '';
                                                if (label) label += ': ';
                                                label += formatCurrency(context.raw);
                                                return label;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }

                } else {
                    console.error('Falha ao carregar os dados:', result.message);
                }
            } catch (error) {
                console.error('Erro na requisição da API:', error);
            }
        };

        document.addEventListener('DOMContentLoaded', () => {
            // chart.js é carregado com defer (head.php), então registramos o plugin
            // só após o DOM/scripts deferidos estarem prontos.
            Chart.register(ChartDataLabels);
            const periodSelect = document.getElementById('periodFilter');
            if (periodSelect) {
                periodSelect.addEventListener('change', carregarDashboard);
            }
            carregarDashboard();
        });
    </script>
</body>
</html>
