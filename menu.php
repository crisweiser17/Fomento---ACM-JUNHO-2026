<?php
// menu.php

// Read config to get app_name and app_version
$menuConfigFilePath = __DIR__ . '/config.json';
$menuAppConfig = [];
if (file_exists($menuConfigFilePath)) {
    $menuAppConfig = json_decode(file_get_contents($menuConfigFilePath), true) ?: [];
}
$menuAppName = $menuAppConfig['app_name'] ?? 'FACTOR';
$menuAppVersion = $menuAppConfig['app_version'] ?? '5.3 — 25 de junho de 2026';

// Detecta qual página está sendo exibida
$currentPage = basename($_SERVER['PHP_SELF']);

// Define os links do menu, usando uma estrutura para dropdown
// Adicionados ícones (Bootstrap Icons) como parte do label para simplicidade
$menuItems = [
    // Link simples para a Home (hub do sistema)
    'home.php' => '<i class="bi bi-house-door me-1"></i> Início',
    // Estrutura para o dropdown de Leads (Esteira de Venda e Novo Lead)
    'leads_dropdown' => [
        'label' => 'Leads',
        'icon' => 'bi-funnel-fill',
        'pages' => [
            'listar_leads.php',
            'kanban_leads.php',
            'form_lead.php',
            'salvar_lead.php',
            'excluir_lead.php',
            'arquivar_lead.php',
            'converter_lead.php',
            'atualizar_estagio_lead.php'
        ],
        'items' => [
            'kanban_leads.php' => '<i class="bi bi-kanban"></i> Esteira de Venda',
            'form_lead.php'    => '<i class="bi bi-plus-circle"></i> Novo Lead'
        ]
    ],
    // Estrutura para o dropdown de Operações (inclui Nova Simulação, Operações e Recebíveis)
    'operacoes_dropdown' => [
        'label' => 'Operações',
        'icon' => 'bi-journal-text',
        'pages' => [
            'simulacao.php',
            'calcular_desconto.php',
            'form_operacao.php',
            'listar_operacoes.php',
            'detalhes_operacao.php',
            'listar_recebiveis.php'
        ],
        'items' => [
            'simulacao.php' => '<i class="bi bi-calculator"></i> Nova Simulação',
            'listar_operacoes.php' => '<i class="bi bi-list-ul"></i> Gerenciar Operações',
            'listar_recebiveis.php' => '<i class="bi bi-list-check"></i> Gerenciar Recebíveis'
        ]
    ],
    // Estrutura para o dropdown de Clientes (Novo Cliente e Listar Clientes)
    'clientes_dropdown' => [
        'label' => 'Clientes',
        'icon' => 'bi-people-fill',
        'pages' => [
            'listar_clientes.php',
            'form_cliente.php',
            'visualizar_cliente.php',
            'salvar_cliente.php',
            'excluir_cliente.php'
        ],
        'items' => [
            'form_cliente.php'    => '<i class="bi bi-person-plus"></i> Novo Cliente',
            'listar_clientes.php' => '<i class="bi bi-people"></i> Listar Clientes'
        ]
    ],
    // Estrutura para o dropdown de Relatórios
    'relatorio_dropdown' => [
        'label' => 'Relatórios',
        'icon' => 'bi-graph-up',
        'pages' => [
            'dashboard_financeiro.php',
            'fechamento.php',
            'relatorio_visitas.php'
        ],
        'items' => [
            'dashboard_financeiro.php' => '<i class="bi bi-graph-up"></i> Relatório Geral Financeiro',
            'fechamento.php' => '<i class="bi bi-wallet2"></i> Fechamento Mensal',
            'relatorio_visitas.php' => '<i class="bi bi-bar-chart-line-fill"></i> Visitas por Usuário'
        ]
    ],
    // Estrutura para o dropdown de Configurações
    'config_dropdown' => [
        'label' => 'Configurações',
        'icon' => 'bi-gear-fill',
        'pages' => [
            'config.php',
            'listar_usuarios.php',
            'form_usuario.php'
        ],
        'items' => [
            'config.php' => '<i class="bi bi-gear-fill"></i> Configurações Gerais',
            'listar_usuarios.php' => '<i class="bi bi-people-fill"></i> Gerenciar Usuários'
        ]
    ]
];
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container-fluid px-3 px-md-4 app-shell-width">
    <a class="navbar-brand" href="home.php">
        <i class="bi bi-cash-coin me-2"></i><?php echo htmlspecialchars($menuAppName); ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
        <?php
        foreach ($menuItems as $keyOrUrl => $item):
            // Verifica se é um item de dropdown
            if (is_array($item) && isset($item['items'])) {
                $dropdownLabel = $item['label'];
                $dropdownIcon = $item['icon'] ?? '';
                $dropdownPages = $item['pages'] ?? [];
                $dropdownSubItems = $item['items'];

                // Verifica se alguma das páginas filhas ou a página principal do dropdown está ativa
                $isDropdownActive = false;
                foreach ($dropdownPages as $page) {
                    if ($currentPage == $page) {
                        $isDropdownActive = true;
                        break;
                    }
                }
                // Adicional: Se o $keyOrUrl for uma página e estiver ativa, ativa o dropdown (caso exista um link principal)
                if (!$isDropdownActive && !is_numeric($keyOrUrl) && $currentPage == $keyOrUrl) {
                    $isDropdownActive = true;
                }

                $activeClass = $isDropdownActive ? 'active fw-bold' : '';
        ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo $activeClass; ?>" href="#" id="navbarDropdown_<?php echo $keyOrUrl; ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if ($dropdownIcon): ?><i class="bi <?php echo $dropdownIcon; ?> me-1"></i><?php endif; ?>
                        <?php echo $dropdownLabel; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="navbarDropdown_<?php echo $keyOrUrl; ?>">
                        <?php foreach ($dropdownSubItems as $url => $label):
                                if ($label === '---'): ?>
                                    <li><hr class="dropdown-divider"></li>
                        <?php   else:
                                    $childActiveClass = ($currentPage == $url) ? 'active' : '';
                        ?>
                                <li><a class="dropdown-item <?php echo $childActiveClass; ?>" href="<?php echo $url; ?>"><?php echo $label; ?></a></li>
                        <?php   endif;
                                endforeach; ?>

                    </ul>
                </li>
        <?php
            } else { // É um link simples
                $url = $keyOrUrl;
                $label = $item;
                $isActive = ($currentPage == $url);

                // Ativa o item "Configurações" quando em config.php
                if ($url == 'config.php' && $currentPage == 'config.php') { //
                    $isActive = true; //
                }

                $activeClass = $isActive ? 'active fw-bold' : '';
                $ariaCurrent = $isActive ? 'aria-current="page"' : '';
        ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeClass; ?>" <?php echo $ariaCurrent; ?> href="<?php echo $url; ?>">
                        <?php echo $label; ?>
                    </a>
                </li>

        <?php
            } // Fim do if/else
        endforeach; // Fim do loop principal
        ?>
        <li class="nav-item">
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sair</a>
        </li>
      </ul>
    </div>
  </div>
</nav>

<footer class="app-footer">
  <div class="container-fluid px-3 px-md-4 app-shell-width d-flex justify-content-between align-items-center">
    <span><i class="bi bi-cash-coin me-1"></i><?php echo htmlspecialchars($menuAppName); ?></span>
    <span class="d-flex align-items-center gap-3">
      <a href="manual.php" class="app-footer-link"><i class="bi bi-book me-1"></i>Manual do Sistema</a>
      <span>Atualizado em <?php echo htmlspecialchars($menuAppVersion); ?></span>
    </span>
  </div>
</footer>
