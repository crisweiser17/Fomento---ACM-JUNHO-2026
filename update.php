<?php
require_once 'db_connection.php';

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar'])) {
    $queries = [
        // setup_contratos_full.php
        "CREATE TABLE IF NOT EXISTS contract_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            template_content LONGTEXT NOT NULL,
            version VARCHAR(20) DEFAULT '1.0',
            active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS generated_contracts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operation_id INT NOT NULL,
            template_code VARCHAR(50) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_hash VARCHAR(64),
            status ENUM('generated', 'sent_to_signature', 'signed', 'cancelled') DEFAULT 'generated',
            signature_platform VARCHAR(50),
            signature_document_id VARCHAR(255),
            signed_at TIMESTAMP NULL,
            metadata JSON,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (operation_id) REFERENCES operacoes(id),
            INDEX idx_operation (operation_id),
            INDEX idx_status (status)
        )",
        "CREATE TABLE IF NOT EXISTS master_cession_contracts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cedente_id INT NOT NULL,
            file_path VARCHAR(500),
            signed_at TIMESTAMP NULL,
            status ENUM('rascunho','ativo','encerrado') DEFAULT 'rascunho',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (cedente_id) REFERENCES cedentes(id)
        )",
        "CREATE TABLE IF NOT EXISTS operation_vehicles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operation_id INT NOT NULL,
            marca VARCHAR(50),
            modelo VARCHAR(100),
            ano_fab INT,
            ano_mod INT,
            cor VARCHAR(30),
            combustivel VARCHAR(30),
            chassi VARCHAR(17),
            placa VARCHAR(10),
            renavam VARCHAR(15),
            municipio_emplacamento VARCHAR(100),
            uf CHAR(2),
            valor_avaliacao DECIMAL(15,2),
            gravame_status ENUM('pendente','solicitado','registrado','cancelado') DEFAULT 'pendente',
            gravame_numero VARCHAR(50),
            gravame_data DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (operation_id) REFERENCES operacoes(id)
        )",
        "CREATE TABLE IF NOT EXISTS operation_guarantors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operation_id INT NOT NULL,
            nome VARCHAR(255) NOT NULL,
            cpf VARCHAR(14) NOT NULL,
            rg VARCHAR(30),
            nacionalidade VARCHAR(50) DEFAULT 'brasileiro(a)',
            estado_civil VARCHAR(30),
            profissao VARCHAR(100),
            endereco TEXT,
            email VARCHAR(100),
            whatsapp VARCHAR(20),
            casado TINYINT(1) DEFAULT 0,
            regime_casamento VARCHAR(50),
            conjuge_nome VARCHAR(255),
            conjuge_cpf VARCHAR(14),
            tipo ENUM('AVALISTA','FIADOR','CONJUGE_ANUENTE') DEFAULT 'AVALISTA',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (operation_id) REFERENCES operacoes(id)
        )",
        "CREATE TABLE IF NOT EXISTS operation_witnesses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operation_id INT NOT NULL,
            ordem TINYINT NOT NULL,
            nome VARCHAR(255) NOT NULL,
            cpf VARCHAR(14) NOT NULL,
            email VARCHAR(100),
            FOREIGN KEY (operation_id) REFERENCES operacoes(id)
        )",
        // Nota: cedentes/sacados foram consolidadas em `clientes`. Os ALTERs dinâmicos
        // mais abaixo são aplicados em `clientes` (idempotente — colunas duplicadas são ignoradas).

        "ALTER TABLE operacoes ADD COLUMN natureza ENUM('EMPRESTIMO','DESCONTO') NOT NULL DEFAULT 'DESCONTO'",
        "ALTER TABLE operacoes ADD COLUMN valor_principal DECIMAL(15,2)",
        "ALTER TABLE operacoes ADD COLUMN valor_total_devido DECIMAL(15,2)",
        "ALTER TABLE operacoes ADD COLUMN taxa_juros_mensal DECIMAL(6,4)",
        "ALTER TABLE operacoes ADD COLUMN taxa_juros_anual DECIMAL(6,4)",
        "ALTER TABLE operacoes ADD COLUMN cet_mensal DECIMAL(6,4)",
        "ALTER TABLE operacoes ADD COLUMN num_parcelas INT",
        "ALTER TABLE operacoes ADD COLUMN valor_parcela DECIMAL(15,2)",
        "ALTER TABLE operacoes ADD COLUMN data_primeiro_vencimento DATE",
        "ALTER TABLE operacoes ADD COLUMN periodicidade VARCHAR(20) DEFAULT 'mensais'",
        "ALTER TABLE operacoes ADD COLUMN taxa_desagio_mensal DECIMAL(6,4)",
        
        // setup_contratos.php
        "ALTER TABLE operacoes ADD COLUMN status_contrato ENUM('pendente', 'aguardando_assinatura', 'assinado') DEFAULT 'pendente'",
        "CREATE TABLE IF NOT EXISTS operacao_documentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operacao_id INT NOT NULL,
            nome_arquivo VARCHAR(255) NOT NULL,
            caminho_arquivo VARCHAR(500) NOT NULL,
            is_assinado TINYINT(1) DEFAULT 0,
            data_geracao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (operacao_id) REFERENCES operacoes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // db_distribuicao_lucros_migration.php
        "CREATE TABLE IF NOT EXISTS distribuicao_lucros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            socio_nome VARCHAR(255) NOT NULL,
            valor DECIMAL(15,2) NOT NULL,
            data DATE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        
        // db_despesas_migration.php
        "CREATE TABLE IF NOT EXISTS despesas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(255) NOT NULL,
            descricao TEXT,
            valor DECIMAL(15, 2) NOT NULL,
            data_despesa DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
        
        // update_db_usuarios.php
        "CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            senha_hash VARCHAR(255) NOT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // Módulo de leads (esteira comercial)
        "CREATE TABLE IF NOT EXISTS leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa VARCHAR(255) NOT NULL,
            nome_contato VARCHAR(255) NOT NULL,
            telefone VARCHAR(50) DEFAULT NULL,
            origem ENUM('receptivo','ativo') NOT NULL DEFAULT 'receptivo',
            estagio ENUM('novo','visita_agendada','visita_feita','aprovado','perdido','convertido') NOT NULL DEFAULT 'novo',
            responsavel_id INT DEFAULT NULL,
            cliente_id INT DEFAULT NULL,
            data_visita_agendada DATETIME DEFAULT NULL,
            motivo_perda VARCHAR(255) DEFAULT NULL,
            observacoes TEXT DEFAULT NULL,
            data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_estagio (estagio),
            INDEX idx_responsavel (responsavel_id),
            INDEX idx_cliente (cliente_id),
            INDEX idx_empresa (empresa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "CREATE TABLE IF NOT EXISTS leads_historico (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            estagio_de VARCHAR(40) DEFAULT NULL,
            estagio_para VARCHAR(40) NOT NULL,
            usuario_id INT DEFAULT NULL,
            observacao VARCHAR(255) DEFAULT NULL,
            data_evento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lead (lead_id),
            INDEX idx_data (data_evento),
            FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    ];

    // Schema unificado: cedentes/sacados foram fundidas em `clientes`.
    $tables_to_update = ['clientes'];
    $columns_to_add = [
        'casado' => 'TINYINT(1) DEFAULT 0',
        'regime_casamento' => 'VARCHAR(100) DEFAULT NULL',
        'conjuge_nome' => 'VARCHAR(255) DEFAULT NULL',
        'conjuge_cpf' => 'VARCHAR(20) DEFAULT NULL',
        'conjuge_rg' => 'VARCHAR(50) DEFAULT NULL',
        'conjuge_nacionalidade' => 'VARCHAR(100) DEFAULT NULL',
        'conjuge_profissao' => 'VARCHAR(150) DEFAULT NULL',
        
        'conta_banco' => 'VARCHAR(100) DEFAULT NULL',
        'conta_agencia' => 'VARCHAR(50) DEFAULT NULL',
        'conta_numero' => 'VARCHAR(50) DEFAULT NULL',
        'conta_pix' => 'VARCHAR(255) DEFAULT NULL',
        'conta_tipo' => 'VARCHAR(50) DEFAULT NULL',
        'conta_titular' => 'VARCHAR(255) DEFAULT NULL',
        'conta_documento' => 'VARCHAR(50) DEFAULT NULL',
        
        'whatsapp' => 'VARCHAR(50) DEFAULT NULL',

        // Colunas do representante e porte (adicionadas posteriormente)
        'porte' => "ENUM('MEI','ME','EPP','MEDIO','GRANDE','PF') NULL",
        'representante_nome' => 'VARCHAR(255) DEFAULT NULL',
        'representante_cpf' => 'VARCHAR(14) DEFAULT NULL',
        'representante_rg' => 'VARCHAR(30) DEFAULT NULL',
        'representante_estado_civil' => 'VARCHAR(50) DEFAULT NULL',
        'representante_profissao' => 'VARCHAR(100) DEFAULT NULL',
        'representante_nacionalidade' => "VARCHAR(50) DEFAULT 'brasileiro(a)'",
        'representante_endereco' => 'TEXT DEFAULT NULL'
    ];

    foreach ($tables_to_update as $table) {
        foreach ($columns_to_add as $column => $definition) {
            $queries[] = "ALTER TABLE $table ADD COLUMN $column $definition";
        }
    }

    $successCount = 0;
    
    foreach ($queries as $index => $sql) {
        try {
            $pdo->exec($sql);
            $successCount++;
        } catch (PDOException $e) {
            $code = $e->getCode();
            $msg = $e->getMessage();
            // 1060: Duplicate column name
            // 1050: Table already exists
            // 1146: Base table or view not found (tabela legada que nao existe mais)
            // 42S21: SQLSTATE for duplicate column
            // 42S02: SQLSTATE for table not found
            if (strpos($msg, '1060') !== false || strpos($msg, 'Duplicate column name') !== false || $code == '42S21') {
                // Ignorar erro de coluna duplicada
            } elseif (strpos($msg, '1050') !== false || (strpos($msg, 'Table') !== false && strpos($msg, 'already exists') !== false)) {
                // Ignorar erro de tabela existente
            } elseif (strpos($msg, '1146') !== false || $code == '42S02') {
                // Ignorar erro de tabela inexistente (referencias legadas a cedentes/sacados removidas do schema)
            } else {
                $messages[] = ['type' => 'error', 'text' => "Erro na query " . ($index + 1) . ": " . $msg];
            }
        }
    }

    // Insert admin user se não existir
    try {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = 'admin'");
        $stmt->execute();
        
        if (!$stmt->fetch()) {
            $senha = 'Qazwsx123@';
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmtInsert = $pdo->prepare("INSERT INTO usuarios (email, senha_hash) VALUES ('admin', :hash)");
            $stmtInsert->bindParam(':hash', $hash);
            $stmtInsert->execute();
            $successCount++;
        }
    } catch (PDOException $e) {
        $messages[] = ['type' => 'error', 'text' => "Erro ao criar usuário admin: " . $e->getMessage()];
    }

    if (empty($messages)) {
        $messages[] = ['type' => 'success', 'text' => "Atualização concluída com sucesso! Nenhuma falha encontrada."];
    } else {
        array_unshift($messages, ['type' => 'success', 'text' => "Processo concluído com alguns avisos/erros (veja abaixo)."]);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualização de Banco de Dados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card shadow-sm mx-auto" style="max-width: 600px;">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Atualização do Banco de Dados</h4>
            </div>
            <div class="card-body text-center">
                <p class="mb-4">Clique no botão abaixo para rodar todas as migrações e atualizações de schema do banco de dados (ignorando colunas e tabelas já existentes).</p>
                <form method="POST">
                    <button type="submit" name="atualizar" class="btn btn-primary btn-lg w-100">Atualizar Banco de Dados</button>
                </form>
                
                <?php if (!empty($messages)): ?>
                    <div class="mt-4 text-start">
                        <?php foreach ($messages as $message): ?>
                            <div class="alert alert-<?= $message['type'] === 'error' ? 'danger' : 'success' ?> mb-2">
                                <?= htmlspecialchars($message['text']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>