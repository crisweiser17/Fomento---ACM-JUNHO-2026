<?php
require_once 'auth_check.php';
require_once 'db_connection.php';
require_once 'ui_helpers.php';

$message = '';
$messageType = '';

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'success') {
        $message = $_GET['msg'] ?? 'Operação realizada com sucesso!';
        $messageType = 'success';
    } elseif ($_GET['status'] === 'error') {
        $message = $_GET['msg'] ?? 'Ocorreu um erro na operação.';
        $messageType = 'danger';
    }
}

// Buscar usuários
try {
    $stmt = $pdo->query("SELECT id, email, criado_em FROM usuarios ORDER BY id ASC");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Erro ao carregar usuários: " . $e->getMessage();
    $messageType = "danger";
    $usuarios = [];
}

// KPIs
$totalUsuarios = count($usuarios);
$novosMes = 0;
foreach ($usuarios as $u) {
    if (!empty($u['criado_em']) && strtotime($u['criado_em']) >= strtotime('-30 days')) {
        $novosMes++;
    }
}

function iniciaisEmail($email) {
    $local = strtolower(trim((string)$email));
    if ($local === '') return '?';
    $local = explode('@', $local)[0];
    $partes = preg_split('/[._\-]+/', $local);
    $r = '';
    foreach ($partes as $p) {
        if ($p === '') continue;
        if (strlen($r) >= 2) break;
        $r .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $r ?: mb_strtoupper(mb_substr($local, 0, 2));
}

function avatarColor($id) {
    $cls = ['b1', 'b2', 'b3', 'b4'];
    return $cls[((int)$id) % count($cls)];
}

function dataHumana($dataStr) {
    if (!$dataStr) return '<span class="text-muted">—</span>';
    try {
        $dt = new DateTime($dataStr);
        $hoje = new DateTime();
        $dias = (int) $hoje->diff($dt)->days;
        if ($dias === 0) return 'hoje, ' . $dt->format('H:i');
        if ($dias === 1) return 'ontem, ' . $dt->format('H:i');
        if ($dias < 30) return "há $dias dias";
        return $dt->format('d/m/Y');
    } catch (Exception $e) { return '—'; }
}
?>
<?php
$pageTitle = 'Usuários';
require_once 'head.php';
?>
    <style>
        /* Estilos específicos da página (tokens e componentes base vêm de theme.css) */
        body { font-size: 0.95rem; }

        /* Layout do grid de KPIs (3 colunas nesta página) */
        .kpi-strip {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 12px; margin-bottom: 18px;
        }
        @media (max-width: 768px) { .kpi-strip { grid-template-columns: 1fr; } }

        /* Célula de usuário com avatar e e-mail */
        .user-cell { display: flex; align-items: center; gap: 10px; }
        .user-cell .avatar {
            width: 36px; height: 36px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.82rem; flex-shrink: 0;
            /* Dieta de cor: avatar único (tinta da marca), sem arco-íris. */
            background: var(--app-info-soft); color: var(--app-info);
        }
        .user-cell .avatar.b1,
        .user-cell .avatar.b2,
        .user-cell .avatar.b3,
        .user-cell .avatar.b4 { background: var(--app-info-soft); color: var(--app-info); }
        .user-cell .name { font-weight: 600; font-size: 0.92rem; line-height: 1.2; }
        .user-cell .meta { font-size: 0.75rem; color: var(--neutral); }

        .self-chip {
            display: inline-block; font-size: 0.68rem; font-weight: 700;
            padding: 2px 8px; border-radius: 999px;
            background: var(--profit-soft); color: var(--profit);
            text-transform: uppercase; letter-spacing: 0.04em;
            margin-left: 6px; vertical-align: middle;
        }
    </style>

    <div class="container-fluid px-3 px-md-4 mt-4 app-shell-width">

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <div class="page-toolbar">
            <div>
                <h1>
                    <i class="bi bi-shield-lock-fill text-info"></i>
                    Usuários do Sistema
                    <span class="id-pill"><i class="bi bi-hash"></i><?php echo $totalUsuarios; ?> ativos</span>
                </h1>
                <div class="text-muted small mt-1">Quem tem acesso ao painel · gerencie senhas e permissões</div>
            </div>
            <div>
                <a href="form_usuario.php" class="btn btn-success">
                    <i class="bi bi-person-plus-fill"></i> Novo Usuário
                </a>
            </div>
        </div>

        <!-- KPIs -->
        <div class="kpi-strip">
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-people-fill"></i></div>
                <div class="k-label">Total de usuários</div>
                <div class="k-value"><?php echo $totalUsuarios; ?></div>
                <div class="k-trend">com acesso ativo</div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-green"><i class="bi bi-person-plus-fill"></i></div>
                <div class="k-label">Novos no mês</div>
                <div class="k-value"><?php echo $novosMes; ?></div>
                <div class="k-trend">criados nos últimos 30 dias</div>
            </div>
            <div class="kpi-card">
                <div class="k-icon b-blue"><i class="bi bi-person-badge-fill"></i></div>
                <div class="k-label">Sua sessão</div>
                <div class="k-value" style="font-size:0.95rem;line-height:1.4;word-break:break-word;">
                    <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'desconhecido'); ?>
                </div>
                <div class="k-trend">você está logado</div>
            </div>
        </div>

        <!-- Tabela -->
        <?php if (empty($usuarios)): ?>
            <?php echo ui_empty_state(
                'bi-shield-lock',
                'Nenhum usuário cadastrado',
                'Comece adicionando o primeiro usuário do sistema.',
                ['label' => 'Novo Usuário', 'href' => 'form_usuario.php', 'icon' => 'bi-person-plus-fill']
            ); ?>
        <?php else: ?>
            <div class="data-table-wrap">
                <div class="data-table-head">
                    <h3><?php echo $totalUsuarios; ?> usuário<?php echo $totalUsuarios === 1 ? '' : 's'; ?></h3>
                    <div class="meta">Ordenados por data de criação</div>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th>Usuário</th>
                                <th>Criado em</th>
                                <th class="text-center" style="width:140px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u):
                                $isMe = (int)$u['id'] === (int)($_SESSION['user_id'] ?? 0);
                            ?>
                                <tr>
                                    <td class="text-muted">#<?php echo (int)$u['id']; ?></td>
                                    <td>
                                        <div class="user-cell">
                                            <div class="avatar <?php echo avatarColor($u['id']); ?>"><?php echo iniciaisEmail($u['email']); ?></div>
                                            <div>
                                                <div class="name">
                                                    <?php echo htmlspecialchars($u['email']); ?>
                                                    <?php if ($isMe): ?>
                                                        <span class="self-chip"><i class="bi bi-person-check-fill"></i> Você</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="meta">login por e-mail</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-muted small"><?php echo dataHumana($u['criado_em']); ?></td>
                                    <td class="text-center">
                                        <div class="row-actions">
                                            <a href="form_usuario.php?id=<?php echo (int)$u['id']; ?>" class="btn-ico" title="Alterar senha">
                                                <i class="bi bi-key-fill"></i>
                                            </a>
                                            <?php if (!$isMe): ?>
                                                <a href="excluir_usuario.php?id=<?php echo (int)$u['id']; ?>" class="btn-ico danger" title="Excluir usuário"
                                                   data-confirm="Tem certeza que deseja excluir este usuário?">
                                                    <i class="bi bi-trash3-fill"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn-ico" disabled title="Você não pode excluir a si mesmo">
                                                    <i class="bi bi-trash3-fill"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>
