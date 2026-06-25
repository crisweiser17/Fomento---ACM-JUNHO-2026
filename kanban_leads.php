<?php require_once 'auth_check.php'; ?>
<?php
require_once 'db_connection.php';

$colunas = [
    'novo'             => ['label' => 'Novo lead',         'icon' => 'bi-inbox',          'cor' => '#0a4ea8'],
    'visita_agendada'  => ['label' => 'Visita agendada',   'icon' => 'bi-calendar-event', 'cor' => '#b76b00'],
    'visita_feita'     => ['label' => 'Visita feita',      'icon' => 'bi-clipboard-check','cor' => '#6f42c1'],
    'aprovado'         => ['label' => 'Aprovado / op.',    'icon' => 'bi-check-circle',   'cor' => '#198754'],
];

$leads_por_estagio = array_fill_keys(array_keys($colunas), []);
try {
    $sql = "SELECT l.*, u.email AS responsavel_email
            FROM leads l
            LEFT JOIN usuarios u ON l.responsavel_id = u.id
            WHERE l.estagio IN ('novo','visita_agendada','visita_feita','aprovado')
            ORDER BY l.data_atualizacao DESC";
    foreach ($pdo->query($sql) as $row) {
        $leads_por_estagio[$row['estagio']][] = $row;
    }
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Erro: " . htmlspecialchars($e->getMessage()) . '</div>';
}

function formatTelefoneKb($t) {
    $t = preg_replace('/\D/', '', (string)$t);
    if (strlen($t) === 11) return '(' . substr($t,0,2) . ') ' . substr($t,2,5) . '-' . substr($t,7,4);
    if (strlen($t) === 10) return '(' . substr($t,0,2) . ') ' . substr($t,2,4) . '-' . substr($t,6,4);
    return $t;
}
function origemBadgeKb($o) {
    $label = $o === 'ativo' ? 'Ativo' : 'Receptivo';
    $cls = $o === 'ativo' ? 'o-ativo' : 'o-receptivo';
    return '<span class="origem-pill ' . $cls . '">' . $label . '</span>';
}
?>
<?php
$pageTitle = 'Kanban de Leads';
require_once 'head.php';
?>
    <style>
        /* Estilos específicos do Kanban (tokens e .page-toolbar vêm de theme.css) */
        body { font-size: 0.95rem; }

        .view-toggle { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
        .view-toggle a { padding: 6px 12px; font-size: 0.85rem; color: var(--neutral); text-decoration: none; background: var(--surface); border-right: 1px solid var(--border); }
        .view-toggle a:last-child { border-right: none; }
        .view-toggle a.active { background: var(--info); color: #fff; }

        .kanban-board {
            display: grid;
            grid-template-columns: repeat(4, minmax(260px, 1fr));
            gap: 14px;
            align-items: start;
        }
        @media (max-width: 1100px) { .kanban-board { grid-template-columns: repeat(2, minmax(260px, 1fr)); } }
        @media (max-width: 600px)  { .kanban-board { grid-template-columns: 1fr; } }

        .kanban-col {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            min-height: 200px;
            overflow: hidden;
        }
        .kanban-col-head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .kanban-col-head .col-icon {
            width: 28px; height: 28px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff; font-size: 0.9rem;
        }
        .kanban-col-head .col-label { font-weight: 600; font-size: 0.9rem; flex: 1; }
        .kanban-col-head .col-count { font-size: 0.75rem; color: var(--neutral); background: var(--surface-2); padding: 2px 8px; border-radius: 999px; font-weight: 600; }

        .kanban-cards {
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-height: 150px;
            flex: 1;
        }
        .kanban-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 12px;
            cursor: grab;
            transition: box-shadow 0.15s, transform 0.05s;
        }
        .kanban-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .kanban-card:active { cursor: grabbing; }
        .kanban-card.sortable-ghost { opacity: 0.4; background: var(--info-soft); }
        .kanban-card.sortable-drag { box-shadow: 0 8px 24px rgba(0,0,0,0.15); }

        .kanban-card .card-title { font-weight: 600; font-size: 0.88rem; line-height: 1.25; margin-bottom: 4px; }
        .kanban-card .card-contact { font-size: 0.78rem; color: var(--neutral); display: flex; align-items: center; gap: 4px; margin-bottom: 4px; }
        .kanban-card .card-meta { display: flex; justify-content: space-between; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
        .kanban-card .card-actions { display: inline-flex; gap: 2px; }
        .kanban-card .card-actions a, .kanban-card .card-actions button {
            width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center;
            color: var(--neutral); background: transparent; border: 0; border-radius: 5px; padding: 0;
            text-decoration: none; font-size: 0.8rem;
        }
        .kanban-card .card-actions a:hover, .kanban-card .card-actions button:hover { background: var(--surface-2); color: var(--info); }
        .kanban-card .card-actions .danger:hover { color: var(--danger); }
        .kanban-card .card-actions .success:hover { color: var(--profit); }

        .origem-pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 6px; font-size: 0.68rem; font-weight: 600; }
        .origem-pill.o-receptivo { background: var(--info-soft); color: var(--info); }
        .origem-pill.o-ativo     { background: #efe8fa; color: #6f42c1; }

        .empty-col { font-size: 0.78rem; color: var(--neutral); text-align: center; padding: 20px 8px; opacity: 0.6; border: 1px dashed var(--border); border-radius: 8px; }

        .btn-add-lead-col {
            width: 100%; padding: 8px; background: transparent; border: 1px dashed var(--border);
            border-radius: 8px; color: var(--neutral); font-size: 0.8rem; cursor: pointer;
            transition: background 0.15s;
        }
        .btn-add-lead-col:hover { background: var(--info-soft); color: var(--info); border-color: var(--info); }

        .toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 9999; }
    </style>

    <div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">

        <div class="page-toolbar">
            <div>
                <h1>
                    <i class="bi bi-kanban-fill text-info"></i>
                    Kanban de Leads
                    <?php
                    $tot = array_sum(array_map('count', $leads_por_estagio));
                    ?>
                    <span class="id-pill"><i class="bi bi-hash"></i><?php echo $tot; ?> em andamento</span>
                </h1>
                <div class="text-muted small mt-1">Arraste os cards para mudar o estágio</div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="view-toggle">
                    <a href="listar_leads.php"><i class="bi bi-list-ul"></i> Lista</a>
                    <a href="kanban_leads.php" class="active"><i class="bi bi-kanban"></i> Kanban</a>
                </div>
                <a href="form_lead.php" class="btn btn-success btn-sm">
                    <i class="bi bi-plus-lg"></i> Novo Lead
                </a>
            </div>
        </div>

        <div class="kanban-board">
            <?php foreach ($colunas as $key => $col): ?>
                <div class="kanban-col">
                    <div class="kanban-col-head">
                        <span class="col-icon" style="background: <?php echo $col['cor']; ?>;"><i class="bi <?php echo $col['icon']; ?>"></i></span>
                        <span class="col-label"><?php echo htmlspecialchars($col['label']); ?></span>
                        <span class="col-count" data-col-count="<?php echo $key; ?>"><?php echo count($leads_por_estagio[$key]); ?></span>
                    </div>
                    <div class="kanban-cards" data-estagio="<?php echo $key; ?>">
                        <?php foreach ($leads_por_estagio[$key] as $lead): ?>
                            <div class="kanban-card" data-id="<?php echo (int)$lead['id']; ?>">
                                <div class="card-title"><?php echo htmlspecialchars($lead['empresa']); ?></div>
                                <div class="card-contact"><i class="bi bi-person"></i> <?php echo htmlspecialchars($lead['nome_contato']); ?></div>
                                <?php if (!empty($lead['telefone'])): ?>
                                    <div class="card-contact">
                                        <a href="https://wa.me/55<?php echo preg_replace('/\D/', '', $lead['telefone']); ?>" target="_blank" rel="noopener" class="text-decoration-none text-success">
                                            <i class="bi bi-whatsapp"></i> <?php echo htmlspecialchars(formatTelefoneKb($lead['telefone'])); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($key === 'visita_agendada' && !empty($lead['data_visita_agendada'])): ?>
                                    <div class="card-contact text-warning">
                                        <i class="bi bi-calendar-event"></i>
                                        <?php
                                        try { echo (new DateTime($lead['data_visita_agendada']))->format('d/m/Y H:i'); }
                                        catch (Exception $e) {}
                                        ?>
                                    </div>
                                <?php endif; ?>
                                <div class="card-meta">
                                    <?php echo origemBadgeKb($lead['origem']); ?>
                                    <div class="card-actions">
                                        <a href="form_lead.php?id=<?php echo (int)$lead['id']; ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                                        <?php if ($key === 'aprovado' && empty($lead['cliente_id'])): ?>
                                            <a href="converter_lead.php?id=<?php echo (int)$lead['id']; ?>" class="success" title="Converter em cliente"><i class="bi bi-person-check-fill"></i></a>
                                        <?php endif; ?>
                                        <button type="button" class="danger btn-arquivar" data-id="<?php echo (int)$lead['id']; ?>" data-empresa="<?php echo htmlspecialchars($lead['empresa']); ?>" title="Marcar como perdido"><i class="bi bi-x-circle"></i></button>
                                    </div>
                                </div>
                                <?php if ($lead['responsavel_email']): ?>
                                    <div class="card-contact mt-1" style="font-size:0.7rem; opacity:0.7;">
                                        <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($lead['responsavel_email']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($leads_por_estagio[$key])): ?>
                            <div class="empty-col" data-empty="<?php echo $key; ?>">Arraste leads para cá</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Modal arquivar -->
    <div class="modal fade" id="modalArquivar" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" action="arquivar_lead.php" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger"></i> Marcar como perdido</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Lead: <strong id="arquivarEmpresa"></strong></p>
                    <input type="hidden" name="id" id="arquivarId">
                    <label class="form-label fw-semibold small text-uppercase text-muted">Motivo da perda</label>
                    <textarea name="motivo_perda" class="form-control" rows="3" required maxlength="255" placeholder="Ex: optou pelo concorrente, sem necessidade, etc."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Marcar como perdido</button>
                </div>
            </form>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" defer></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        function showToast(msg, type) {
            const cls = type === 'error' ? 'bg-danger' : 'bg-success';
            const el = document.createElement('div');
            el.className = `toast align-items-center text-white ${cls} border-0 show`;
            el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
            document.getElementById('toastContainer').appendChild(el);
            setTimeout(() => el.remove(), 3500);
        }

        function atualizarContagens() {
            document.querySelectorAll('.kanban-cards').forEach(col => {
                const key = col.dataset.estagio;
                const count = col.querySelectorAll('.kanban-card').length;
                const counter = document.querySelector(`[data-col-count="${key}"]`);
                if (counter) counter.textContent = count;
                const empty = col.querySelector('[data-empty]');
                if (empty) empty.style.display = count === 0 ? '' : 'none';
                if (count === 0 && !empty) {
                    const div = document.createElement('div');
                    div.className = 'empty-col';
                    div.dataset.empty = key;
                    div.textContent = 'Arraste leads para cá';
                    col.appendChild(div);
                }
            });
        }

        document.querySelectorAll('.kanban-cards').forEach(col => {
            new Sortable(col, {
                group: 'leads',
                animation: 150,
                ghostClass: 'sortable-ghost',
                dragClass: 'sortable-drag',
                onEnd: async (evt) => {
                    const card = evt.item;
                    const novoEstagio = evt.to.dataset.estagio;
                    const id = card.dataset.id;
                    if (evt.from === evt.to) return;

                    const fd = new FormData();
                    fd.append('id', id);
                    fd.append('estagio', novoEstagio);

                    try {
                        const res = await fetch('atualizar_estagio_lead.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (!data.ok) throw new Error(data.error || 'Erro');
                        showToast('Estágio atualizado', 'success');
                        atualizarContagens();
                    } catch (e) {
                        showToast('Erro: ' + e.message, 'error');
                        evt.from.insertBefore(card, evt.from.children[evt.oldIndex]);
                        atualizarContagens();
                    }
                }
            });
        });

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
