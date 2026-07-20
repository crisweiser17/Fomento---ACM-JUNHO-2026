<?php require_once 'auth_check.php'; ?>
<?php
// excluir_cliente.php
require_once 'db_connection.php'; // Conexão $pdo

// 1. Verifica se o ID foi passado via GET e é um número válido
if (isset($_GET['id']) && filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)) {
    $clienteIdParaExcluir = (int)$_GET['id'];

    try {
        // ---------------------------------------------------------
        // 2. VERIFICAÇÃO: vínculos que impedem a exclusão
        // ---------------------------------------------------------
        // Cadastro unificado: o mesmo cliente pode ser cedente (operacoes) e/ou
        // sacado (recebiveis). Checar só operacoes deixava o DELETE estourar na
        // foreign key fk_recebiveis_sacado com "erro no banco de dados".
        $vinculos = [
            'operações como cedente' => "SELECT COUNT(*) FROM operacoes WHERE cedente_id = :id",
            'títulos como sacado'    => "SELECT COUNT(*) FROM recebiveis WHERE sacado_id = :id",
            'contratos de cessão'    => "SELECT COUNT(*) FROM master_cession_contracts WHERE cedente_id = :id",
        ];

        $bloqueios = [];
        foreach ($vinculos as $rotulo => $sqlVinculo) {
            $stmtVinculo = $pdo->prepare($sqlVinculo);
            $stmtVinculo->execute([':id' => $clienteIdParaExcluir]);
            $qtd = (int) $stmtVinculo->fetchColumn();
            if ($qtd > 0) {
                $bloqueios[] = "$qtd $rotulo";
            }
        }

        // ---------------------------------------------------------
        // 3. DECISÃO: Excluir ou bloquear?
        // ---------------------------------------------------------
        if (!empty($bloqueios)) {
            $motivo = "Cliente não pode ser excluído: possui " . implode(' e ', $bloqueios)
                    . ". Exclua ou transfira esses registros antes.";
            header("Location: listar_clientes.php?status=error&msg=" . urlencode($motivo));
            exit;
        } else {
            // Nenhuma operação encontrada, pode prosseguir com a exclusão.

            // ---------------------------------------------------------
            // 4. EXECUÇÃO DO DELETE (somente se não houver operações)
            // ---------------------------------------------------------
            $pdo->beginTransaction(); // Opcional, mas bom para DELETE

            // Primeiro excluir os dados filhos (sócios), que também têm FK para clientes
            foreach (['clientes_socios' => 'cliente_id', 'cedentes_socios' => 'cedente_id'] as $tabelaFilha => $coluna) {
                $stmtDeleteSocios = $pdo->prepare("DELETE FROM `$tabelaFilha` WHERE `$coluna` = :id");
                $stmtDeleteSocios->bindParam(':id', $clienteIdParaExcluir, PDO::PARAM_INT);
                $stmtDeleteSocios->execute();
            }

            // Depois excluir o cliente
            $sqlDelete = "DELETE FROM clientes WHERE id = :id";
            $stmtDelete = $pdo->prepare($sqlDelete);
            $stmtDelete->bindParam(':id', $clienteIdParaExcluir, PDO::PARAM_INT);

            if ($stmtDelete->execute()) {
                // Verifica se alguma linha foi realmente deletada
                if ($stmtDelete->rowCount() > 0) {
                    $pdo->commit(); // Confirma a exclusão
                    header("Location: listar_clientes.php?status=success&msg=" . urlencode("Cliente ID " . $clienteIdParaExcluir . " excluído com sucesso."));
                    exit;
                } else {
                    // Nenhuma linha afetada, o ID provavelmente não existia mais
                    $pdo->rollBack(); // Desfaz a transação (embora nada tenha sido feito)
                    header("Location: listar_clientes.php?status=error&msg=" . urlencode("Cliente com ID " . $clienteIdParaExcluir . " não foi encontrado para exclusão."));
                    exit;
                }
            } else {
                 // Erro na execução do DELETE
                 $pdo->rollBack();
                 error_log("Erro ao tentar executar DELETE para cliente ID: " . $clienteIdParaExcluir);
                 header("Location: listar_clientes.php?status=error&msg=" . urlencode("Erro no banco ao tentar excluir o cliente."));
                 exit;
            }
        }

    } catch (PDOException $e) {
        // Erro geral de banco de dados (durante a verificação ou exclusão)
         if ($pdo->inTransaction()) { // Garante rollback se erro ocorreu durante a transação DELETE
            $pdo->rollBack();
         }
        error_log("Erro PDO ao verificar/excluir cliente ID $clienteIdParaExcluir: " . $e->getMessage());
        header("Location: listar_clientes.php?status=error&msg=" . urlencode("Erro no banco de dados: Verifique os logs. [" . $e->getCode() . "]")); // Evita expor $e->getMessage() diretamente
        exit;
    }

} else {
    // Se ID inválido ou não fornecido, redireciona para a lista
    header("Location: listar_clientes.php?status=error&msg=" . urlencode("ID inválido para exclusão."));
    exit;
}
?>