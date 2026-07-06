<?php require_once 'auth_check.php'; ?>
<?php
$pageTitle = 'Manual do Sistema';

// CSS específico do manual (painel de navegação + tipografia do conteúdo)
$headExtra = <<<'HTML'
<style>
    .manual-nav { padding: 8px; max-height: calc(100vh - 120px); overflow-y: auto; }
    .manual-nav a {
        display: block;
        padding: 8px 12px;
        border-radius: 8px;
        color: var(--app-ink);
        text-decoration: none;
        font-size: 0.88rem;
        font-weight: 500;
        line-height: 1.25;
    }
    .manual-nav a:hover { background: var(--app-surface-2); }
    .manual-nav a.active {
        background: var(--app-info-soft);
        color: var(--app-info);
        font-weight: 600;
    }
    .manual-nav a i { width: 18px; margin-right: 6px; }
    .manual-section { scroll-margin-top: 20px; }
    .manual-section .section-body p { margin-bottom: 10px; color: var(--app-ink); }
    .manual-section .section-body ul { margin-bottom: 0; padding-left: 18px; }
    .manual-section .section-body li { margin-bottom: 6px; line-height: 1.45; }
    .manual-section .section-body strong { font-weight: 600; }
    .manual-lead { color: var(--app-neutral); font-size: 0.92rem; margin-bottom: 14px; }
    .manual-flow {
        display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
        background: var(--app-info-soft); border: 1px solid var(--app-info-border);
        border-radius: var(--app-radius); padding: 12px 14px; margin-bottom: 14px;
    }
    .manual-flow .step {
        background: #fff; border: 1px solid var(--app-info-border); color: var(--app-info);
        border-radius: 20px; padding: 4px 12px; font-size: 0.8rem; font-weight: 600;
    }
    .manual-flow .arrow { color: var(--app-info); font-weight: 700; }
    .manual-glossary dt { font-weight: 600; color: var(--app-ink); margin-top: 8px; }
    .manual-glossary dd { margin: 2px 0 8px; color: var(--app-neutral); font-size: 0.9rem; }
</style>
HTML;

require_once 'head.php';
?>

<div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">

    <div class="page-toolbar">
        <div>
            <h1><i class="bi bi-book me-2"></i>Manual do Sistema</h1>
            <div class="subtitle">Guia rápido de todas as telas e do fluxo de trabalho</div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Navegação de tópicos -->
        <div class="col-lg-3">
            <div class="sticky-panel">
                <div class="panel-head">
                    <h3>Tópicos</h3>
                    <i class="bi bi-list-ul"></i>
                </div>
                <nav class="manual-nav" id="manual-nav">
                    <a href="#visao-geral"><i class="bi bi-compass"></i>Visão geral</a>
                    <a href="#acesso"><i class="bi bi-box-arrow-in-right"></i>Acesso e navegação</a>
                    <a href="#leads"><i class="bi bi-funnel-fill"></i>Leads (esteira de vendas)</a>
                    <a href="#clientes"><i class="bi bi-people-fill"></i>Clientes / Cedentes</a>
                    <a href="#simulacao"><i class="bi bi-calculator"></i>Simulação</a>
                    <a href="#operacoes"><i class="bi bi-briefcase-fill"></i>Operações</a>
                    <a href="#recebiveis"><i class="bi bi-receipt"></i>Recebíveis</a>
                    <a href="#relatorios"><i class="bi bi-graph-up"></i>Relatórios</a>
                    <a href="#usuarios"><i class="bi bi-person-badge"></i>Usuários</a>
                    <a href="#config"><i class="bi bi-gear-fill"></i>Configurações</a>
                    <a href="#glossario"><i class="bi bi-journal-text"></i>Glossário</a>
                </nav>
            </div>
        </div>

        <!-- Conteúdo -->
        <div class="col-lg-9">

            <!-- Visão geral -->
            <section class="section-card manual-section" id="visao-geral">
                <div class="section-head">
                    <i class="bi bi-compass text-primary"></i>
                    <h2>Visão geral</h2>
                </div>
                <div class="section-body">
                    <p class="manual-lead">O sistema acompanha todo o ciclo da operação de desconto/factoring: da prospecção do cliente até o fechamento financeiro do mês.</p>
                    <div class="manual-flow">
                        <span class="step">Lead</span><span class="arrow">→</span>
                        <span class="step">Cliente</span><span class="arrow">→</span>
                        <span class="step">Simulação</span><span class="arrow">→</span>
                        <span class="step">Operação</span><span class="arrow">→</span>
                        <span class="step">Recebíveis</span><span class="arrow">→</span>
                        <span class="step">Fechamento</span>
                    </div>
                    <p>Cada tela cobre uma etapa desse caminho. O menu superior agrupa as telas por área (Leads, Operações, Clientes, Relatórios e Configurações). Em caso de dúvida sobre um termo, consulte o <a href="#glossario">Glossário</a> no fim desta página.</p>
                </div>
            </section>

            <!-- Acesso e navegação -->
            <section class="section-card manual-section" id="acesso">
                <div class="section-head">
                    <i class="bi bi-box-arrow-in-right text-primary"></i>
                    <h2>Acesso e navegação</h2>
                </div>
                <div class="section-body">
                    <ul>
                        <li><strong>Login:</strong> entre com seu e-mail e senha. Em caso de erro, o sistema avisa e permite tentar de novo. Use <strong>Sair</strong> (no menu) para encerrar a sessão.</li>
                        <li><strong>Menu superior:</strong> presente em todas as telas. Os itens ficam em menus suspensos por área; a tela atual fica destacada.</li>
                        <li><strong>Início:</strong> a página inicial é a <strong>Esteira de Vendas (Kanban)</strong> dos leads.</li>
                    </ul>
                </div>
            </section>

            <!-- Leads -->
            <section class="section-card manual-section" id="leads">
                <div class="section-head">
                    <i class="bi bi-funnel-fill text-primary"></i>
                    <h2>Leads — esteira de vendas</h2>
                </div>
                <div class="section-body">
                    <p class="manual-lead">Um <strong>lead</strong> é um cliente em potencial. Ele percorre as etapas: novo → visita agendada → visita feita → aprovado → convertido (ou perdido).</p>
                    <ul>
                        <li><strong>Esteira de Vendas (Kanban):</strong> quadro visual com colunas por etapa. Arraste o cartão de uma coluna para outra para avançar o lead. Cada cartão mostra empresa, contato e telefone (com link de WhatsApp).</li>
                        <li><strong>Novo Lead / Editar:</strong> formulário com Empresa e Nome do contato (obrigatórios), telefone, origem (Ativo ou Receptivo), responsável, data da visita e observações.</li>
                        <li><strong>Listar Leads:</strong> visão em tabela, com busca, ordenação, filtros (ativos, perdidos, convertidos, todos) e paginação.</li>
                        <li><strong>Converter:</strong> um lead aprovado pode ser transformado em cliente; o sistema leva você ao cadastro para completar os dados (CNPJ, endereço, sócios).</li>
                    </ul>
                </div>
            </section>

            <!-- Clientes -->
            <section class="section-card manual-section" id="clientes">
                <div class="section-head">
                    <i class="bi bi-people-fill text-primary"></i>
                    <h2>Clientes / Cedentes</h2>
                </div>
                <div class="section-body">
                    <p class="manual-lead">O <strong>cliente</strong> (cedente) é quem entrega os recebíveis para desconto. É a empresa com quem você opera.</p>
                    <ul>
                        <li><strong>Listar Clientes:</strong> lista geral com busca, filtros (todos, ativos, inativos, novos do mês) e indicadores no topo (total, volume dos últimos 12 meses). Clique para ver ou editar.</li>
                        <li><strong>Novo Cliente / Editar:</strong> cadastro completo — dados de contato, CNPJ, endereço, dados bancários (conta e PIX) e a lista de sócios (nome e CPF). O CNPJ e os CPFs são validados quando preenchidos; se ficarem em branco ou zerados, o sistema aceita mas avisa que o valor jurídico do contrato pode ser afetado.</li>
                        <li><strong>Visualizar Cliente:</strong> ficha somente leitura com os dados e indicadores do cliente (volume, lucro, ticket médio) e as últimas operações.</li>
                    </ul>
                </div>
            </section>

            <!-- Simulação -->
            <section class="section-card manual-section" id="simulacao">
                <div class="section-head">
                    <i class="bi bi-calculator text-primary"></i>
                    <h2>Simulação</h2>
                </div>
                <div class="section-body">
                    <p class="manual-lead">Calculadora da operação de desconto: você informa os recebíveis e o sistema mostra valor presente, IOF, valor líquido a pagar e o lucro — antes de fechar negócio.</p>
                    <ul>
                        <li>Escolha o <strong>cedente</strong> e adicione os recebíveis (valor e data de vencimento).</li>
                        <li>Ajuste a <strong>taxa mensal</strong> e as opções de IOF (cobrar do cliente, incorrer no custo).</li>
                        <li>O resultado mostra o detalhamento por recebível e os totais (desconto, IOF, líquido, lucro).</li>
                        <li>Quando aprovado, a simulação pode ser <strong>registrada como operação</strong>.</li>
                    </ul>
                </div>
            </section>

            <!-- Operações -->
            <section class="section-card manual-section" id="operacoes">
                <div class="section-head">
                    <i class="bi bi-briefcase-fill text-primary"></i>
                    <h2>Operações</h2>
                </div>
                <div class="section-body">
                    <p class="manual-lead">A <strong>operação</strong> é a transação de desconto já registrada, com seus recebíveis e custos calculados.</p>
                    <ul>
                        <li><strong>Gerenciar Operações:</strong> lista com filtros (cedente, status, faixa de valor, data, tipo), busca e ordenação. Mostra taxa, valor original, líquido, lucro, número de recebíveis e saldo em aberto.</li>
                        <li><strong>Detalhes da Operação:</strong> resumo completo somente leitura — cabeçalho, tabela de recebíveis com status e anexos.</li>
                        <li><strong>Editar Operação:</strong> ajusta dados como tipo de pagamento, notas, sacado e tipo de cada recebível. Não recalcula os totais originais.</li>
                    </ul>
                </div>
            </section>

            <!-- Recebíveis -->
            <section class="section-card manual-section" id="recebiveis">
                <div class="section-head">
                    <i class="bi bi-receipt text-primary"></i>
                    <h2>Recebíveis</h2>
                </div>
                <div class="section-body">
                    <p class="manual-lead">O <strong>recebível</strong> (título) é cada cobrança dentro de uma operação — uma duplicata, cheque, nota, etc. Tem valor, vencimento, sacado e um status.</p>
                    <ul>
                        <li><strong>Gerenciar Recebíveis:</strong> tabela com filtros por status (Em Aberto, Recebido, Problema, Compensado, Parcialmente Compensado), tipo e período. Itens vencidos ficam destacados.</li>
                        <li><strong>Atualizar status:</strong> direto na lista, marque um título como Recebido, Problema, etc. Ao marcar como Recebido, a data de recebimento é registrada.</li>
                    </ul>
                </div>
            </section>

            <!-- Relatórios -->
            <section class="section-card manual-section" id="relatorios">
                <div class="section-head">
                    <i class="bi bi-graph-up text-primary"></i>
                    <h2>Relatórios</h2>
                </div>
                <div class="section-body">
                    <ul>
                        <li><strong>Dashboard Financeiro:</strong> indicadores do período escolhido — capital adiantado, amortizado, lucro realizado e projetado, inadimplência (em R$ e %), além de gráficos e ranking por cedente.</li>
                        <li><strong>Fechamento Mensal:</strong> escolha mês e ano para ver total recebido, retorno de capital, lucro bruto, despesas, lucro líquido e a distribuição do lucro (distribuído aos sócios x retido).</li>
                        <li><strong>Visitas por Usuário:</strong> resumo da atividade comercial por período (visitas agendadas/feitas, aprovados, convertidos, perdidos). Clique em um número para abrir a lista de leads correspondente.</li>
                    </ul>
                </div>
            </section>

            <!-- Usuários -->
            <section class="section-card manual-section" id="usuarios">
                <div class="section-head">
                    <i class="bi bi-person-badge text-primary"></i>
                    <h2>Usuários</h2>
                </div>
                <div class="section-body">
                    <ul>
                        <li><strong>Gerenciar Usuários:</strong> lista dos usuários do sistema, com data de criação. Clique para editar.</li>
                        <li><strong>Novo / Editar Usuário:</strong> cria um usuário (e-mail e senha) ou troca a senha de um existente. A senha precisa de no mínimo 6 caracteres e confirmação.</li>
                    </ul>
                </div>
            </section>

            <!-- Configurações -->
            <section class="section-card manual-section" id="config">
                <div class="section-head">
                    <i class="bi bi-gear-fill text-primary"></i>
                    <h2>Configurações</h2>
                </div>
                <div class="section-body">
                    <p class="manual-lead">Central de ajustes do sistema. Os valores definidos aqui são usados como padrão nas demais telas.</p>
                    <ul>
                        <li><strong>Gerais:</strong> nome e versão do sistema, fuso horário.</li>
                        <li><strong>Taxas:</strong> taxa mensal padrão, juros e multa de atraso, alíquotas de IOF.</li>
                        <li><strong>E-mail:</strong> chave de envio, remetente, cópias e modelo da mensagem.</li>
                        <li><strong>Dados bancários e da empresa:</strong> conta, PIX, razão social, documento e representante usados nos documentos.</li>
                    </ul>
                </div>
            </section>

            <!-- Glossário -->
            <section class="section-card manual-section" id="glossario">
                <div class="section-head">
                    <i class="bi bi-journal-text text-primary"></i>
                    <h2>Glossário</h2>
                </div>
                <div class="section-body">
                    <dl class="manual-glossary">
                        <dt>Lead</dt>
                        <dd>Cliente em potencial, ainda na fase de prospecção e visitas.</dd>
                        <dt>Cliente / Cedente</dt>
                        <dd>Quem cede os recebíveis para desconto — a empresa com quem você opera.</dd>
                        <dt>Sacado</dt>
                        <dd>Quem deve pagar o recebível (o devedor do título).</dd>
                        <dt>Operação</dt>
                        <dd>Transação de desconto registrada, reunindo um ou mais recebíveis de um cedente.</dd>
                        <dt>Recebível / Título</dt>
                        <dd>Cada cobrança dentro de uma operação (duplicata, cheque, nota, boleto, etc.).</dd>
                        <dt>Simulação</dt>
                        <dd>Cálculo prévio de uma operação, mostrando custos e lucro antes de registrá-la.</dd>
                        <dt>Compensação</dt>
                        <dd>Uso de valores recebidos para quitar um título anterior em aberto.</dd>
                        <dt>Fechamento</dt>
                        <dd>Apuração do mês: soma o que foi recebido, calcula o lucro e a distribuição aos sócios.</dd>
                    </dl>
                </div>
            </section>

        </div>
    </div>
</div>

<script>
    // Destaque do tópico ativo conforme a rolagem + rolagem suave nos links
    (function () {
        const links = Array.from(document.querySelectorAll('#manual-nav a'));
        const byId = new Map(links.map(a => [a.getAttribute('href').slice(1), a]));
        const sections = Array.from(document.querySelectorAll('.manual-section'));

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    links.forEach(a => a.classList.remove('active'));
                    const link = byId.get(entry.target.id);
                    if (link) link.classList.add('active');
                }
            });
        }, { rootMargin: '-15% 0px -70% 0px', threshold: 0 });

        sections.forEach(s => observer.observe(s));

        links.forEach(a => a.addEventListener('click', (e) => {
            const target = document.getElementById(a.getAttribute('href').slice(1));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }));
    })();
</script>

</body>
</html>
