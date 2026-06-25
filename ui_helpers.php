<?php
/**
 * ui_helpers.php — Helpers de UI que emitem HTML padronizado.
 *
 * Sem framework de componentes: são funções puras que RETORNAM string HTML
 * (use com `echo`). Centralizam a linguagem visual do design system
 * (theme.css) para garantir consistência entre as 22 telas.
 *
 * Nenhuma destas funções acessa banco, sessão ou regras de negócio —
 * apenas formatam apresentação. Todo conteúdo dinâmico é escapado.
 */

if (!function_exists('ui_e')) {
    /** Escapa texto para HTML (atalho). */
    function ui_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ui_attr')) {
    /** Monta atributos HTML a partir de um array associativo (valores escapados). */
    function ui_attr(array $attrs): string
    {
        $out = [];
        foreach ($attrs as $key => $val) {
            if ($val === null || $val === false) {
                continue;
            }
            if ($val === true) {
                $out[] = ui_e($key);
                continue;
            }
            $out[] = ui_e($key) . '="' . ui_e($val) . '"';
        }
        return $out ? ' ' . implode(' ', $out) : '';
    }
}

if (!function_exists('ui_status_pill')) {
    /**
     * Pill de status — fonte única de verdade para cores de status.
     * Mapeia rótulos/códigos comuns do sistema para as classes do theme.css.
     */
    function ui_status_pill(?string $status, ?string $labelOverride = null): string
    {
        $key = strtolower(trim((string) $status));

        // normaliza acentos/variações comuns
        $map = [
            'aberto'        => ['s-aberto', 'Em aberto'],
            'em aberto'     => ['s-aberto', 'Em aberto'],
            'em_aberto'     => ['s-aberto', 'Em aberto'],
            'pendente'      => ['s-aberto', 'Pendente'],
            'ativo'         => ['s-ativo', 'Ativo'],
            'ativa'         => ['s-ativo', 'Ativa'],
            'concluida'     => ['s-concluida', 'Concluída'],
            'concluída'     => ['s-concluida', 'Concluída'],
            'concluido'     => ['s-concluida', 'Concluído'],
            'recebido'      => ['s-concluida', 'Recebido'],
            'compensado'    => ['s-concluida', 'Compensado'],
            'pago'          => ['s-concluida', 'Pago'],
            'parcial'       => ['s-parcial', 'Parcial'],
            'problema'      => ['s-problema', 'Com problema'],
            'com problema'  => ['s-problema', 'Com problema'],
            'inadimplente'  => ['s-problema', 'Inadimplente'],
            'cancelado'     => ['s-problema', 'Cancelado'],
            'inativo'       => ['s-neutro', 'Inativo'],
            'arquivado'     => ['s-neutro', 'Arquivado'],
        ];

        [$cls, $defaultLabel] = $map[$key] ?? ['s-neutro', ucfirst($key ?: '—')];
        $label = $labelOverride ?? $defaultLabel;

        return '<span class="status-pill ' . $cls . '">' . ui_e($label) . '</span>';
    }
}

if (!function_exists('ui_btn')) {
    /**
     * Botão/link padronizado.
     * $variant: primary | secondary | danger (mapeado para classes Bootstrap
     * coerentes com a hierarquia do design system).
     * $opts: ['href','icon','size'=>'sm'|'lg','confirm'=>texto,'attrs'=>[],'block'=>bool]
     */
    function ui_btn(string $label, string $variant = 'primary', array $opts = []): string
    {
        $variantClass = [
            'primary'   => 'btn-primary',
            'secondary' => 'btn-outline-secondary',
            'danger'    => 'btn-outline-danger',
            'success'   => 'btn-success',
        ][$variant] ?? 'btn-primary';

        $classes = ['btn', $variantClass];
        if (!empty($opts['size']))  { $classes[] = 'btn-' . $opts['size']; }
        if (!empty($opts['block'])) { $classes[] = 'w-100'; }

        $attrs = $opts['attrs'] ?? [];
        $attrs['class'] = implode(' ', $classes);
        if (!empty($opts['confirm'])) {
            $attrs['data-confirm'] = $opts['confirm'];
        }

        $iconHtml = !empty($opts['icon']) ? '<i class="bi ' . ui_e($opts['icon']) . '"></i> ' : '';
        $inner = $iconHtml . ui_e($label);

        if (!empty($opts['href'])) {
            $attrs['href'] = $opts['href'];
            return '<a' . ui_attr($attrs) . '>' . $inner . '</a>';
        }
        $attrs['type'] = $attrs['type'] ?? 'button';
        return '<button' . ui_attr($attrs) . '>' . $inner . '</button>';
    }
}

if (!function_exists('ui_page_header')) {
    /**
     * Toolbar de página consistente.
     * $actions: array de strings de HTML já prontas (ex.: ui_btn(...)).
     *           Convenção: ordene primária por último à direita; ações
     *           perigosas devem usar ui_btn(...,'danger',['confirm'=>...]).
     */
    function ui_page_header(string $titulo, ?string $subtitulo = null, array $actions = [], array $opts = []): string
    {
        $icon = !empty($opts['icon']) ? '<i class="bi ' . ui_e($opts['icon']) . '"></i> ' : '';
        $pill = !empty($opts['pill'])
            ? '<span class="id-pill">' . ($opts['pill_icon'] ?? '<i class="bi bi-hash"></i>') . ui_e($opts['pill']) . '</span>'
            : '';

        $html  = '<div class="page-toolbar">';
        $html .= '<div class="toolbar-main">';
        $html .= '<h1>' . $icon . ui_e($titulo) . ' ' . $pill . '</h1>';
        if ($subtitulo !== null && $subtitulo !== '') {
            $html .= '<div class="subtitle">' . ui_e($subtitulo) . '</div>';
        }
        $html .= '</div>';
        if ($actions) {
            $html .= '<div class="toolbar-actions">' . implode('', $actions) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('ui_kpi_card')) {
    /**
     * Card de KPI.
     * $variant: blue | green | warn | danger | purple | pink (cor do ícone).
     * $trend: ['dir'=>'up'|'down'|'flat', 'text'=>'...'] opcional.
     */
    function ui_kpi_card(string $label, string $value, string $icon, string $variant = 'blue', ?array $trend = null): string
    {
        $html  = '<div class="kpi-card">';
        $html .= '<span class="k-icon b-' . ui_e($variant) . '"><i class="bi ' . ui_e($icon) . '"></i></span>';
        $html .= '<div class="k-label">' . ui_e($label) . '</div>';
        $html .= '<div class="k-value">' . ui_e($value) . '</div>';
        if ($trend) {
            $dir = $trend['dir'] ?? 'flat';
            $html .= '<div class="k-trend ' . ui_e($dir) . '">' . ui_e($trend['text'] ?? '') . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('ui_empty_state')) {
    /**
     * Estado vazio padronizado para listagens/seções sem dados.
     * $cta: ['label'=>..., 'href'=>..., 'icon'=>...] opcional.
     */
    function ui_empty_state(string $icon, string $titulo, string $mensagem = '', ?array $cta = null): string
    {
        $html  = '<div class="empty-state">';
        $html .= '<i class="bi ' . ui_e($icon) . ' empty-ico"></i>';
        $html .= '<div class="empty-title">' . ui_e($titulo) . '</div>';
        if ($mensagem !== '') {
            $html .= '<div class="empty-msg">' . ui_e($mensagem) . '</div>';
        }
        if ($cta) {
            $html .= ui_btn($cta['label'], 'primary', [
                'href' => $cta['href'] ?? '#',
                'icon' => $cta['icon'] ?? null,
            ]);
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('ui_row_actions')) {
    /**
     * Ações de linha de tabela como ícones.
     * $actions: lista de ['href','icon','title','danger'=>bool,'confirm'=>txt].
     * Regra de UX: ações perigosas (danger) são automaticamente movidas para
     * o FIM e separadas das ações de leitura por um divisor visual.
     */
    function ui_row_actions(array $actions): string
    {
        $safe = [];
        $danger = [];
        foreach ($actions as $a) {
            if (!empty($a['danger'])) {
                $danger[] = $a;
            } else {
                $safe[] = $a;
            }
        }

        $render = function (array $a): string {
            $attrs = $a['attrs'] ?? [];
            $attrs['class'] = trim('btn-ico ' . (!empty($a['danger']) ? 'danger' : ''));
            $attrs['title'] = $a['title'] ?? null;
            if (!empty($a['confirm'])) {
                $attrs['data-confirm'] = $a['confirm'];
            }
            $icon = '<i class="bi ' . ui_e($a['icon'] ?? 'bi-dot') . '"></i>';
            if (!empty($a['href'])) {
                $attrs['href'] = $a['href'];
                return '<a' . ui_attr($attrs) . '>' . $icon . '</a>';
            }
            $attrs['type'] = 'button';
            return '<button' . ui_attr($attrs) . '>' . $icon . '</button>';
        };

        $html = '<div class="row-actions">';
        foreach ($safe as $a) {
            $html .= $render($a);
        }
        if ($danger) {
            if ($safe) {
                $html .= '<span class="ico-sep"></span>';
            }
            foreach ($danger as $a) {
                $html .= $render($a);
            }
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('ui_pagination')) {
    /**
     * Barra de paginação. $baseUrl deve conter os params já montados, faltando
     * apenas o número da página (helper acrescenta page=N).
     */
    function ui_pagination(int $currentPage, int $totalPages, string $baseUrl, ?string $info = null): string
    {
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        $sep = (strpos($baseUrl, '?') !== false) ? '&' : '?';
        $link = function (int $p) use ($baseUrl, $sep): string {
            return ui_e($baseUrl . $sep . 'page=' . $p);
        };

        $html  = '<div class="pagination-bar">';
        $html .= '<div class="info">' . ($info !== null ? ui_e($info) : ('Página ' . $currentPage . ' de ' . $totalPages)) . '</div>';
        $html .= '<ul class="pagination">';

        $prevDisabled = $currentPage <= 1 ? ' disabled' : '';
        $html .= '<li class="page-item' . $prevDisabled . '"><a class="page-link" href="' . $link(max(1, $currentPage - 1)) . '" aria-label="Anterior">&laquo;</a></li>';

        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);
        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $link(1) . '">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
        }
        for ($p = $start; $p <= $end; $p++) {
            $active = $p === $currentPage ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $link($p) . '">' . $p . '</a></li>';
        }
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            $html .= '<li class="page-item"><a class="page-link" href="' . $link($totalPages) . '">' . $totalPages . '</a></li>';
        }

        $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';
        $html .= '<li class="page-item' . $nextDisabled . '"><a class="page-link" href="' . $link(min($totalPages, $currentPage + 1)) . '" aria-label="Próxima">&raquo;</a></li>';

        $html .= '</ul></div>';
        return $html;
    }
}
