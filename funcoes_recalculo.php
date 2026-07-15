<?php
/**
 * Recálculo de operações já gravadas.
 *
 * Sempre que os valores ou vencimentos dos títulos mudam, os campos derivados
 * (valor presente, IOF, líquido, dias e os totais da operação) precisam ser
 * refeitos. Usa a mesma função central do registro (calcularLucroOperacao)
 * para garantir que editar e registrar produzam exatamente os mesmos números.
 */

require_once 'funcoes_lucro.php';

/**
 * Recalcula e persiste os valores derivados de uma operação.
 *
 * @param  PDO $pdo
 * @param  int $operacaoId
 * @return array Resumo do recálculo
 * @throws RuntimeException Se a operação não existir
 */
function recalcularOperacaoPersistida(PDO $pdo, int $operacaoId): array
{
    $stmtOp = $pdo->prepare("SELECT * FROM operacoes WHERE id = :id");
    $stmtOp->execute([':id' => $operacaoId]);
    $operacao = $stmtOp->fetch(PDO::FETCH_ASSOC);

    if (!$operacao) {
        throw new RuntimeException("Operação #{$operacaoId} não encontrada para recálculo.");
    }

    $stmtRec = $pdo->prepare(
        "SELECT * FROM recebiveis WHERE operacao_id = :id ORDER BY data_vencimento ASC"
    );
    $stmtRec->execute([':id' => $operacaoId]);
    $recebiveis = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

    if (empty($recebiveis)) {
        return ['recebiveis' => 0];
    }

    $dataOperacao = new DateTime($operacao['data_operacao']);
    $dataOperacao->setTime(0, 0, 0);

    $resultado = calcularLucroOperacao(
        $recebiveis,
        (bool) $operacao['incorre_custo_iof'],
        (bool) $operacao['cobrar_iof_cliente'],
        (float) $operacao['taxa_mensal'],
        $dataOperacao
    );

    $detalhes = $resultado['detalhesRecebiveis'];

    // Derivados de cada título
    $stmtUpd = $pdo->prepare(
        "UPDATE recebiveis
            SET valor_presente_calc = :vp,
                iof_calc            = :iof,
                valor_liquido_calc  = :liq,
                dias_prazo_calc     = :dias
          WHERE id = :id AND operacao_id = :op"
    );
    foreach ($detalhes as $detalhe) {
        $stmtUpd->execute([
            ':vp'   => $detalhe['valorPresente'],
            ':iof'  => $detalhe['iofTitulo'],
            ':liq'  => $detalhe['valorLiquidoPago'],
            ':dias' => $detalhe['dias'],
            ':id'   => $detalhe['id'],
            ':op'   => $operacaoId,
        ]);
    }

    $totalOriginal = $resultado['totalOriginal'];
    $totalPresente = array_sum(array_column($detalhes, 'valorPresente'));
    $totalLiquido  = $resultado['totalLiquidoPago'];
    $totalLucro    = $resultado['lucroAjustado'];

    if (($operacao['tipo_operacao'] ?? 'antecipacao') === 'emprestimo') {
        // No empréstimo o líquido é o principal emprestado, não o valor presente.
        $totalLiquido  = (float) $operacao['total_liquido_pago_calc'];
        $totalPresente = $totalLiquido;
        $totalLucro    = max(0, $totalOriginal - $totalLiquido);
    } else {
        // Compensação já acordada continua reduzindo o líquido pago.
        $totalLiquido -= (float) ($operacao['valor_total_compensacao'] ?? 0);
    }

    $stmtTotais = $pdo->prepare(
        "UPDATE operacoes
            SET total_original_calc      = :orig,
                total_presente_calc      = :pres,
                iof_total_calc           = :iof,
                total_liquido_pago_calc  = :liq,
                total_lucro_liquido_calc = :lucro,
                media_dias_pond_calc     = :dias
          WHERE id = :id"
    );
    $stmtTotais->execute([
        ':orig'  => $totalOriginal,
        ':pres'  => $totalPresente,
        ':iof'   => $resultado['totalIOF'],
        ':liq'   => $totalLiquido,
        ':lucro' => $totalLucro,
        ':dias'  => calcularMediaPonderadaDias($detalhes),
        ':id'    => $operacaoId,
    ]);

    return [
        'recebiveis'    => count($detalhes),
        'totalOriginal' => $totalOriginal,
        'totalLiquido'  => $totalLiquido,
    ];
}

/**
 * Média de dias ponderada pelo valor original de cada título.
 *
 * @param  array $detalhes Detalhes vindos de calcularLucroOperacao()
 * @return int
 */
function calcularMediaPonderadaDias(array $detalhes): int
{
    $numerador = 0;
    $pesoTotal = 0;

    foreach ($detalhes as $detalhe) {
        $numerador += $detalhe['dias'] * $detalhe['valorOriginal'];
        $pesoTotal += $detalhe['valorOriginal'];
    }

    return $pesoTotal > 0 ? (int) round($numerador / $pesoTotal) : 0;
}
