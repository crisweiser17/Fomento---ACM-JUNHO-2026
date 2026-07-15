<?php
/**
 * unificar_sacados_em_clientes.php
 * -----------------------------------------------------------------------------
 * Migração one-off: funde a tabela `sacados` em `clientes`.
 *
 * Motivo: o sistema mantinha dois cadastros separados (clientes e sacados) sem
 * nenhum registro em comum, então um cliente não podia ser usado como sacado.
 * Pior: as telas exibiam o nome do sacado buscando em `clientes`, enquanto a
 * FK apontava para `sacados` — o que mostrava nomes trocados ou em branco.
 *
 * O que faz:
 *   1. Copia cada sacado para `clientes` (reaproveita se já existir um cliente
 *      com o mesmo CNPJ/CPF/documento/nome).
 *   2. Reaponta recebiveis.sacado_id para o id do cliente correspondente.
 *   3. Troca a FK de recebiveis.sacado_id: sacados -> clientes.
 *
 * A tabela `sacados` NÃO é apagada — fica como backup.
 *
 * Uso:
 *   CLI:  php unificar_sacados_em_clientes.php
 *   Web:  https://SEU-DOMINIO/unificar_sacados_em_clientes.php?confirmar=SIM
 *
 * Seguro rodar de novo: detecta se a FK já aponta para `clientes` e sai.
 * -----------------------------------------------------------------------------
 */

$ehCli = (php_sapi_name() === 'cli');

if (!$ehCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['confirmar'] ?? '') !== 'SIM') {
        http_response_code(400);
        exit("Confirmacao necessaria. Acesse com ?confirmar=SIM para executar.\n");
    }
}

require_once __DIR__ . '/db_connection.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const FK_SACADO = 'fk_recebiveis_sacado';

/** Para qual tabela a FK de recebiveis.sacado_id aponta hoje? */
function tabelaReferenciadaPelaFk(PDO $pdo): ?string
{
    $stmt = $pdo->prepare(
        "SELECT REFERENCED_TABLE_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'recebiveis'
            AND COLUMN_NAME = 'sacado_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
          LIMIT 1"
    );
    $stmt->execute();
    $ref = $stmt->fetchColumn();
    return $ref === false ? null : (string) $ref;
}

/** Nome da constraint FK atual (pode variar entre ambientes). */
function nomeDaFkAtual(PDO $pdo): ?string
{
    $stmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'recebiveis'
            AND COLUMN_NAME = 'sacado_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
          LIMIT 1"
    );
    $stmt->execute();
    $nome = $stmt->fetchColumn();
    return $nome === false ? null : (string) $nome;
}

/** Colunas de uma tabela. */
function colunasDe(PDO $pdo, string $tabela): array
{
    $cols = [];
    foreach ($pdo->query("DESCRIBE `$tabela`") as $c) {
        $cols[$c['Field']] = true;
    }
    return $cols;
}

/** Procura um cliente equivalente a este sacado. */
function acharClienteEquivalente(PDO $pdo, array $sacado): ?string
{
    foreach (['cnpj', 'cpf', 'documento_principal', 'nome'] as $chave) {
        $valor = $sacado[$chave] ?? null;
        if ($valor === null || $valor === '') {
            continue;
        }
        $stmt = $pdo->prepare("SELECT id FROM clientes WHERE `$chave` = ? LIMIT 1");
        $stmt->execute([$valor]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (string) $id;
        }
    }
    return null;
}

$relatorio = [];

try {
    // --- Guarda de idempotência
    if (tabelaReferenciadaPelaFk($pdo) === 'clientes') {
        exit("JA MIGRADO: a FK de recebiveis.sacado_id ja aponta para `clientes`. Nada a fazer.\n");
    }

    $tabelas = $pdo->query("SHOW TABLES LIKE 'sacados'")->fetchColumn();
    if (!$tabelas) {
        exit("Tabela `sacados` nao existe. Nada a fazer.\n");
    }

    $sacados = $pdo->query("SELECT * FROM sacados")->fetchAll(PDO::FETCH_ASSOC);
    $colsCliente = colunasDe($pdo, 'clientes');

    // --- 1) Copiar sacados para clientes, montando o mapa id_sacado => id_cliente
    $pdo->beginTransaction();

    $mapa = [];
    $inseridos = 0;
    $reaproveitados = 0;

    foreach ($sacados as $sacado) {
        $existente = acharClienteEquivalente($pdo, $sacado);
        if ($existente !== null) {
            $mapa[$sacado['id']] = $existente;
            $reaproveitados++;
            $relatorio[] = sprintf('  reaproveitado: %-42s -> cliente #%s', $sacado['nome'], $existente);
            continue;
        }

        $cols = [];
        foreach ($sacado as $col => $_) {
            if ($col !== 'id' && isset($colsCliente[$col])) {
                $cols[] = $col;
            }
        }
        $lista = '`' . implode('`, `', $cols) . '`';
        $ph    = implode(', ', array_fill(0, count($cols), '?'));
        $stmt  = $pdo->prepare("INSERT INTO clientes ($lista) VALUES ($ph)");
        $stmt->execute(array_map(fn($c) => $sacado[$c], $cols));

        $novoId = $pdo->lastInsertId();
        $mapa[$sacado['id']] = $novoId;
        $inseridos++;
        $relatorio[] = sprintf('  copiado:       %-42s -> cliente #%s', $sacado['nome'], $novoId);
    }

    $pdo->commit();

    // --- 2) Remover a FK antiga (DDL: fora de transação)
    $fkAtual = nomeDaFkAtual($pdo);
    if ($fkAtual !== null) {
        $pdo->exec("ALTER TABLE recebiveis DROP FOREIGN KEY `$fkAtual`");
        $relatorio[] = "FK antiga removida ($fkAtual -> sacados)";
    }

    // --- 3) Reapontar os recebíveis para os ids de clientes
    $pdo->beginTransaction();

    // UPDATE único com CASE: os ids novos de clientes podem colidir com os ids
    // antigos de sacados, então atualizar em loop remapearia a mesma linha
    // várias vezes (1->5 e depois 5->9). Um único statement avalia o CASE
    // contra os valores originais, sem efeito cascata.
    // Os valores vêm de ids do banco e são convertidos para int, então a
    // interpolação abaixo é segura.
    $casos = '';
    foreach ($mapa as $idSacado => $idCliente) {
        $casos .= sprintf(' WHEN %d THEN %d', (int) $idSacado, (int) $idCliente);
    }
    $idsAntigos = implode(',', array_map('intval', array_keys($mapa)));

    $reapontados = $pdo->exec(
        "UPDATE recebiveis
            SET sacado_id = CASE sacado_id{$casos} END
          WHERE sacado_id IN ({$idsAntigos})"
    );
    $relatorio[] = "recebiveis reapontados: $reapontados";

    // Sobrou algum sacado_id sem correspondencia? (nao deveria)
    $orfaos = (int) $pdo->query(
        "SELECT COUNT(*) FROM recebiveis r
          WHERE r.sacado_id IS NOT NULL
            AND r.sacado_id NOT IN (SELECT id FROM clientes)"
    )->fetchColumn();
    if ($orfaos > 0) {
        throw new RuntimeException("$orfaos recebivel(is) ficaram com sacado_id sem cliente correspondente.");
    }

    $pdo->commit();

    // --- 4) Criar a FK nova apontando para clientes
    $pdo->exec(
        "ALTER TABLE recebiveis
           ADD CONSTRAINT `" . FK_SACADO . "`
           FOREIGN KEY (sacado_id) REFERENCES clientes (id)"
    );
    $relatorio[] = 'FK nova criada (' . FK_SACADO . ' -> clientes)';

    echo "UNIFICACAO CONCLUIDA COM SUCESSO\n" . str_repeat('-', 60) . "\n";
    echo implode("\n", $relatorio) . "\n" . str_repeat('-', 60) . "\n";
    printf("copiados: %d | reaproveitados: %d\n", $inseridos, $reaproveitados);
    echo "A tabela `sacados` foi mantida como backup.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!$ehCli) {
        http_response_code(500);
    }
    echo "FALHA NA UNIFICACAO\n";
    echo 'Erro: ' . $e->getMessage() . "\n";
    if ($relatorio) {
        echo "\nProgresso antes da falha:\n" . implode("\n", $relatorio) . "\n";
    }
    echo "\nATENCAO: verifique a FK de recebiveis.sacado_id antes de rodar de novo.\n";
}
