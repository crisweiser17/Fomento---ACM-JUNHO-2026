<?php
/**
 * installer2.php - Replicador de Estrutura para Servidor Online
 * 
 * Este script instala a estrutura EXATA do banco de dados local em um servidor online,
 * com dados minimos de exemplo (1 cliente, 1 operacao, usuario admin).
 * 
 * Fluxo de uso:
 * 1. Envie todos os arquivos PHP da versao local para o servidor
 * 2. Acesse installer2.php no navegador
 * 3. Preencha as credenciais do MySQL do servidor
 * 4. Clique "Instalar Sistema"
 * 5. Delete o installer2.php apos a instalacao
 */

set_time_limit(300);
ini_set('memory_limit', '512M');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$step = 1;
$message = '';
$messageType = '';
$log = [];

function addLog(&$log, $msg) {
    $log[] = date('H:i:s') . ' - ' . $msg;
}

// =============================================================================
// SCHEMA DO BANCO DE DADOS (DDL COMPLETO DAS 18 TABELAS)
// =============================================================================
$schemaSQL = <<<SQL
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `compensacoes`;
DROP TABLE IF EXISTS `operacao_arquivos_log`;
DROP TABLE IF EXISTS `operacao_arquivos`;
DROP TABLE IF EXISTS `operacao_documentos`;
DROP TABLE IF EXISTS `operacao_anotacoes`;
DROP TABLE IF EXISTS `generated_contracts`;
DROP TABLE IF EXISTS `master_cession_contracts`;
DROP TABLE IF EXISTS `operation_witnesses`;
DROP TABLE IF EXISTS `operation_guarantors`;
DROP TABLE IF EXISTS `operation_vehicles`;
DROP TABLE IF EXISTS `recebiveis`;
DROP TABLE IF EXISTS `clientes_socios`;
DROP TABLE IF EXISTS `operacoes`;
DROP TABLE IF EXISTS `contract_templates`;
DROP TABLE IF EXISTS `despesas`;
DROP TABLE IF EXISTS `distribuicao_lucros`;
DROP TABLE IF EXISTS `usuarios`;
DROP TABLE IF EXISTS `clientes`;

CREATE TABLE `clientes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_pessoa` enum('FISICA','JURIDICA') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FISICA',
  `documento_principal` varchar(18) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `documento_secundario` varchar(14) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cpf` varchar(14) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cnpj` varchar(18) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `documento_socio` varchar(14) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `empresa` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `endereco` text COLLATE utf8mb4_unicode_ci,
  `cep` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logradouro` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complemento` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bairro` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cidade` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `porte` enum('MEI','ME','EPP','MEDIO','GRANDE','PF') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `representante_nome` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `representante_cpf` varchar(14) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `representante_rg` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `representante_nacionalidade` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'brasileiro(a)',
  `representante_estado_civil` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `representante_profissao` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `representante_endereco` text COLLATE utf8mb4_unicode_ci,
  `conta_banco` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conta_agencia` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conta_numero` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conta_pix` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conta_tipo` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conta_titular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conta_documento` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whatsapp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anotacoes` text COLLATE utf8mb4_unicode_ci,
  `conta_pix_tipo` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_nome` (`nome`),
  KEY `idx_empresa` (`empresa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `clientes_socios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cliente_id` int DEFAULT NULL,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cpf` varchar(14) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_cadastro` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sacado_id` (`cliente_id`),
  KEY `idx_cpf` (`cpf`),
  CONSTRAINT `fk_clientes_socios_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `compensacoes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operacao_principal_id` int NOT NULL COMMENT 'ID da operacao principal',
  `recebivel_compensado_id` int NOT NULL COMMENT 'ID do recebivel compensado',
  `valor_presente_compensacao` decimal(15,2) NOT NULL,
  `valor_original_recebivel` decimal(15,2) NOT NULL,
  `valor_compensado` decimal(15,2) NOT NULL,
  `saldo_restante` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tipo_compensacao` enum('total','parcial') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'total',
  `recebivel_saldo_id` int DEFAULT NULL,
  `taxa_antecipacao_aplicada` decimal(10,4) NOT NULL,
  `data_compensacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `observacoes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_operacao_principal` (`operacao_principal_id`),
  KEY `idx_recebivel_compensado` (`recebivel_compensado_id`),
  KEY `idx_data_compensacao` (`data_compensacao`),
  KEY `idx_recebivel_saldo` (`recebivel_saldo_id`),
  KEY `idx_tipo_compensacao` (`tipo_compensacao`),
  CONSTRAINT `fk_compensacao_operacao` FOREIGN KEY (`operacao_principal_id`) REFERENCES `operacoes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_compensacao_recebivel` FOREIGN KEY (`recebivel_compensado_id`) REFERENCES `recebiveis` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabela para rastrear compensacoes';

CREATE TABLE `contract_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `template_content` longtext NOT NULL,
  `version` varchar(20) DEFAULT '1.0',
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `despesas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `titulo` varchar(255) NOT NULL,
  `descricao` text,
  `valor` decimal(15,2) NOT NULL DEFAULT '0.00',
  `data_despesa` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `distribuicao_lucros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `socio_nome` varchar(255) NOT NULL,
  `valor` decimal(15,2) NOT NULL DEFAULT '0.00',
  `data` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `generated_contracts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operation_id` int NOT NULL,
  `template_code` varchar(50) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_hash` varchar(64) DEFAULT NULL,
  `status` enum('generated','sent_to_signature','signed','cancelled') DEFAULT 'generated',
  `signature_platform` varchar(50) DEFAULT NULL,
  `signature_document_id` varchar(255) DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_operation` (`operation_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `generated_contracts_ibfk_1` FOREIGN KEY (`operation_id`) REFERENCES `operacoes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `master_cession_contracts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cedente_id` int NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `status` enum('rascunho','ativo','encerrado') DEFAULT 'rascunho',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `cedente_id` (`cedente_id`),
  CONSTRAINT `master_cession_contracts_ibfk_1` FOREIGN KEY (`cedente_id`) REFERENCES `clientes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `operacao_anotacoes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operacao_id` int DEFAULT NULL,
  `recebivel_id` int DEFAULT NULL,
  `usuario_id` int DEFAULT NULL,
  `anotacao` text COLLATE utf8mb4_unicode_ci,
  `data_criacao` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `operacao_id` (`operacao_id`),
  KEY `recebivel_id` (`recebivel_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `operacao_anotacoes_ibfk_1` FOREIGN KEY (`operacao_id`) REFERENCES `operacoes` (`id`),
  CONSTRAINT `operacao_anotacoes_ibfk_2` FOREIGN KEY (`recebivel_id`) REFERENCES `recebiveis` (`id`),
  CONSTRAINT `operacao_anotacoes_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `operacao_arquivos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operacao_id` int NOT NULL,
  `nome_original` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome_arquivo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_arquivo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `extensao` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tamanho_bytes` bigint NOT NULL,
  `caminho_arquivo` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_upload` datetime DEFAULT CURRENT_TIMESTAMP,
  `usuario_upload` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descricao` text COLLATE utf8mb4_unicode_ci,
  `ativo` tinyint(1) DEFAULT '1',
  `data_exclusao` datetime DEFAULT NULL,
  `usuario_exclusao` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_operacao_id` (`operacao_id`),
  KEY `idx_data_upload` (`data_upload`),
  KEY `idx_ativo` (`ativo`),
  KEY `idx_tipo_arquivo` (`tipo_arquivo`),
  CONSTRAINT `fk_arquivo_operacao` FOREIGN KEY (`operacao_id`) REFERENCES `operacoes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabela para armazenar arquivos anexados';

CREATE TABLE `operacao_arquivos_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `arquivo_id` int NOT NULL,
  `operacao_id` int NOT NULL,
  `acao` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usuario` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_acao` datetime NOT NULL,
  `observacoes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `arquivo_id` (`arquivo_id`),
  KEY `operacao_id` (`operacao_id`),
  CONSTRAINT `operacao_arquivos_log_ibfk_1` FOREIGN KEY (`arquivo_id`) REFERENCES `operacao_arquivos` (`id`),
  CONSTRAINT `operacao_arquivos_log_ibfk_2` FOREIGN KEY (`operacao_id`) REFERENCES `operacoes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `operacao_documentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operacao_id` int NOT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `caminho_arquivo` varchar(500) NOT NULL,
  `is_assinado` tinyint(1) DEFAULT '0',
  `data_geracao` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `operacao_id` (`operacao_id`),
  CONSTRAINT `operacao_documentos_ibfk_1` FOREIGN KEY (`operacao_id`) REFERENCES `operacoes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `operacoes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cedente_id` int DEFAULT NULL,
  `taxa_mensal` decimal(10,4) NOT NULL,
  `data_operacao` datetime DEFAULT NULL,
  `data_base_calculo` date DEFAULT NULL,
  `tipo_pagamento` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'direto',
  `tipo_operacao` enum('antecipacao','emprestimo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'antecipacao',
  `tem_garantia` tinyint(1) NOT NULL DEFAULT '0',
  `descricao_garantia` text COLLATE utf8mb4_unicode_ci,
  `total_original_calc` decimal(15,2) DEFAULT NULL,
  `total_presente_calc` decimal(15,2) DEFAULT NULL,
  `iof_total_calc` decimal(15,2) DEFAULT NULL,
  `total_liquido_pago_calc` decimal(15,2) DEFAULT NULL,
  `incorre_custo_iof` tinyint(1) NOT NULL,
  `cobrar_iof_cliente` tinyint(1) NOT NULL,
  `total_lucro_liquido_calc` decimal(15,2) DEFAULT NULL,
  `media_dias_pond_calc` int DEFAULT NULL,
  `notas` text COLLATE utf8mb4_unicode_ci,
  `valor_total_compensacao` decimal(15,2) DEFAULT '0.00' COMMENT 'Valor total das compensacoes',
  `status_contrato` enum('pendente','aguardando_assinatura','assinado') COLLATE utf8mb4_unicode_ci DEFAULT 'pendente',
  `natureza` enum('EMPRESTIMO','DESCONTO') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DESCONTO',
  `valor_principal` decimal(15,2) DEFAULT NULL,
  `valor_total_devido` decimal(15,2) DEFAULT NULL,
  `taxa_juros_mensal` decimal(6,4) DEFAULT NULL,
  `taxa_juros_anual` decimal(6,4) DEFAULT NULL,
  `cet_mensal` decimal(6,4) DEFAULT NULL,
  `num_parcelas` int DEFAULT NULL,
  `valor_parcela` decimal(15,2) DEFAULT NULL,
  `data_primeiro_vencimento` date DEFAULT NULL,
  `periodicidade` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'mensais',
  `taxa_desagio_mensal` decimal(6,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_operacao_sacado` (`cedente_id`),
  CONSTRAINT `fk_operacoes_cliente` FOREIGN KEY (`cedente_id`) REFERENCES `clientes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `operation_guarantors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operation_id` int NOT NULL,
  `nome` varchar(255) NOT NULL,
  `cpf` varchar(14) NOT NULL,
  `rg` varchar(30) DEFAULT NULL,
  `nacionalidade` varchar(50) DEFAULT 'brasileiro(a)',
  `estado_civil` varchar(30) DEFAULT NULL,
  `profissao` varchar(100) DEFAULT NULL,
  `endereco` text,
  `email` varchar(100) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `casado` tinyint(1) DEFAULT '0',
  `regime_casamento` varchar(50) DEFAULT NULL,
  `conjuge_nome` varchar(255) DEFAULT NULL,
  `conjuge_cpf` varchar(14) DEFAULT NULL,
  `tipo` enum('AVALISTA','FIADOR','CONJUGE_ANUENTE') DEFAULT 'AVALISTA',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `operation_id` (`operation_id`),
  CONSTRAINT `operation_guarantors_ibfk_1` FOREIGN KEY (`operation_id`) REFERENCES `operacoes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `operation_vehicles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operation_id` int NOT NULL,
  `marca` varchar(50) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `ano_fab` int DEFAULT NULL,
  `ano_mod` int DEFAULT NULL,
  `cor` varchar(30) DEFAULT NULL,
  `combustivel` varchar(30) DEFAULT NULL,
  `chassi` varchar(17) DEFAULT NULL,
  `placa` varchar(10) DEFAULT NULL,
  `renavam` varchar(15) DEFAULT NULL,
  `municipio_emplacamento` varchar(100) DEFAULT NULL,
  `uf` char(2) DEFAULT NULL,
  `valor_avaliacao` decimal(15,2) DEFAULT NULL,
  `gravame_status` enum('pendente','solicitado','registrado','cancelado') DEFAULT 'pendente',
  `gravame_numero` varchar(50) DEFAULT NULL,
  `gravame_data` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `operation_id` (`operation_id`),
  CONSTRAINT `operation_vehicles_ibfk_1` FOREIGN KEY (`operation_id`) REFERENCES `operacoes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `operation_witnesses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operation_id` int NOT NULL,
  `ordem` tinyint NOT NULL,
  `nome` varchar(255) NOT NULL,
  `cpf` varchar(14) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `operation_id` (`operation_id`),
  CONSTRAINT `operation_witnesses_ibfk_1` FOREIGN KEY (`operation_id`) REFERENCES `operacoes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `recebiveis` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operacao_id` int NOT NULL,
  `sacado_id` int DEFAULT NULL,
  `tipo_recebivel` enum('cheque','duplicata','nota_promissoria','boleto','fatura','nota_fiscal','parcela_emprestimo','outros') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'duplicata' COMMENT 'Tipo do recebivel',
  `valor_original` decimal(15,2) NOT NULL,
  `valor_recebido` decimal(15,2) DEFAULT NULL,
  `data_vencimento` date NOT NULL,
  `valor_presente_calc` decimal(15,2) DEFAULT NULL,
  `iof_calc` decimal(15,2) DEFAULT NULL,
  `valor_liquido_calc` decimal(15,2) DEFAULT NULL,
  `dias_prazo_calc` int DEFAULT NULL COMMENT 'Dias de prazo calculado',
  `status` enum('Em Aberto','Recebido','Problema','Compensado','Parcialmente Compensado') COLLATE utf8mb4_unicode_ci DEFAULT 'Em Aberto',
  `data_recebimento` datetime DEFAULT NULL,
  `obs_problema` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_operacao_id` (`operacao_id`),
  KEY `idx_data_vencimento` (`data_vencimento`),
  KEY `idx_status` (`status`),
  KEY `idx_sacado_id` (`sacado_id`),
  CONSTRAINT `fk_recebiveis_cliente` FOREIGN KEY (`sacado_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `recebiveis_ibfk_1` FOREIGN KEY (`operacao_id`) REFERENCES `operacoes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `senha_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
SQL;

// =============================================================================
// TEMPLATES DE CONTRATO (CONTEUDO EMBUTIDO)
// =============================================================================
$contractTemplates = [
    [
        'code' => '01',
        'name' => 'Contrato de Cessao Onerosa de Creditos',
        'description' => 'Contrato de antecipacao de recebiveis (desconto de titulos)',
        'content' => file_exists(__DIR__ . '/_contratos/01_template_antecipacao_recebiveis.md') 
            ? file_get_contents(__DIR__ . '/_contratos/01_template_antecipacao_recebiveis.md')
            : '# Template de Cessao de Creditos - atualizar manualmente'
    ],
    [
        'code' => '02a',
        'name' => 'Mútuo Simples (Sem Garantia, Sem Aval)',
        'description' => 'Contrato de mútuo feneratício simples',
        'content' => file_exists(__DIR__ . '/_contratos/02a_template_mutuo_simples.md')
            ? file_get_contents(__DIR__ . '/_contratos/02a_template_mutuo_simples.md')
            : '# Template 02a - atualizar manualmente'
    ],
    [
        'code' => '02b',
        'name' => 'Mútuo com Avalista',
        'description' => 'Contrato de mútuo com avalista/fiador',
        'content' => file_exists(__DIR__ . '/_contratos/02b_template_mutuo_com_aval.md')
            ? file_get_contents(__DIR__ . '/_contratos/02b_template_mutuo_com_aval.md')
            : '# Template 02b - atualizar manualmente'
    ],
    [
        'code' => '02c',
        'name' => 'Mútuo com Garantia (Alienacao Fiduciaria)',
        'description' => 'Contrato de mútuo com garantia real de veiculo',
        'content' => file_exists(__DIR__ . '/_contratos/02c_template_mutuo_com_garantia.md')
            ? file_get_contents(__DIR__ . '/_contratos/02c_template_mutuo_com_garantia.md')
            : '# Template 02c - atualizar manualmente'
    ],
    [
        'code' => '02d',
        'name' => 'Mútuo com Garantia e Aval',
        'description' => 'Contrato de mútuo com garantia e avalista',
        'content' => file_exists(__DIR__ . '/_contratos/02d_template_mutuo_com_garantia_e_aval.md')
            ? file_get_contents(__DIR__ . '/_contratos/02d_template_mutuo_com_garantia_e_aval.md')
            : '# Template 02d - atualizar manualmente'
    ],
    [
        'code' => '02e',
        'name' => 'Mútuo com Garantia de Bem',
        'description' => 'Contrato de mútuo com garantia de bem',
        'content' => file_exists(__DIR__ . '/_contratos/02e_template_mutuo_com_garantia_bem.md')
            ? file_get_contents(__DIR__ . '/_contratos/02e_template_mutuo_com_garantia_bem.md')
            : '# Template 02e - atualizar manualmente'
    ],
    [
        'code' => '02f',
        'name' => 'Mútuo com Garantia de Bem e Aval',
        'description' => 'Contrato de mútuo com garantia de bem e avalista',
        'content' => file_exists(__DIR__ . '/_contratos/02f_template_mutuo_com_garantia_bem_e_aval.md')
            ? file_get_contents(__DIR__ . '/_contratos/02f_template_mutuo_com_garantia_bem_e_aval.md')
            : '# Template 02f - atualizar manualmente'
    ],
    [
        'code' => '03',
        'name' => 'Nota Promissória',
        'description' => 'Nota promissória vinculada ao contrato de mútuo',
        'content' => file_exists(__DIR__ . '/_contratos/03_template_nota_promissoria.md')
            ? file_get_contents(__DIR__ . '/_contratos/03_template_nota_promissoria.md')
            : '# Template Nota Promissoria - atualizar manualmente'
    ],
];

// =============================================================================
// PROCESSAMENTO DO FORMULARIO
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = $_POST['db_host'] ?? 'localhost';
    $db_user = $_POST['db_user'] ?? 'root';
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = $_POST['db_name'] ?? 'factor_db';
    $charset = 'utf8mb4';

    try {
        // Fase A: Conectar ao MySQL
        addLog($log, "Conectando ao MySQL em {$db_host}...");
        $pdo = new PDO("mysql:host={$db_host};port=3306;charset={$charset}", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        addLog($log, "Conexao estabelecida com sucesso.");

        // Criar banco se nao existir
        addLog($log, "Criando banco '{$db_name}' se nao existir...");
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$db_name}`");
        addLog($log, "Banco selecionado.");

        // Fase B: Executar schema
        addLog($log, "Executando schema DDL (18 tabelas)...");
        $pdo->exec($schemaSQL);
        addLog($log, "Schema criado com sucesso.");

        // Fase C: Inserir dados minimos
        addLog($log, "Inserindo dados de exemplo...");

        // 1. Usuario admin
        $senhaHash = password_hash('Qazwsx123@', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha_hash) VALUES (?, ?)");
        $stmt->execute(['admin', $senhaHash]);
        addLog($log, "Usuario 'admin' criado.");

        // 2. Cliente de exemplo (PJ)
        $stmt = $pdo->prepare("INSERT INTO clientes 
            (nome, email, telefone, tipo_pessoa, documento_principal, cnpj, empresa, 
             endereco, cep, logradouro, numero, complemento, bairro, cidade, estado,
             conta_banco, conta_agencia, conta_numero, conta_tipo, conta_titular, conta_documento)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'Empresa Exemplo LTDA',
            'contato@exemplo.com.br',
            '(11) 99999-9999',
            'JURIDICA',
            '11222333000144',
            '11.222.333/0001-44',
            'Empresa Exemplo LTDA',
            'Av. Paulista, 1000, Sala 1001',
            '01310-100',
            'Av. Paulista',
            '1000',
            'Sala 1001',
            'Bela Vista',
            'Sao Paulo',
            'SP',
            'Banco do Brasil',
            '1234-5',
            '12345-6',
            'corrente',
            'Empresa Exemplo LTDA',
            '11.222.333/0001-44'
        ]);
        $clienteId = $pdo->lastInsertId();
        addLog($log, "Cliente de exemplo criado (ID: {$clienteId}).");

        // 3. Socio do cliente
        $stmt = $pdo->prepare("INSERT INTO clientes_socios (cliente_id, nome, cpf) VALUES (?, ?, ?)");
        $stmt->execute([$clienteId, 'Joao da Silva', '123.456.789-00']);
        addLog($log, "Socio do cliente criado.");

        // 4. Operacao de exemplo (antecipacao)
        $dataOperacao = date('Y-m-d H:i:s');
        $dataBase = date('Y-m-d');
        $stmt = $pdo->prepare("INSERT INTO operacoes 
            (cedente_id, taxa_mensal, data_operacao, data_base_calculo, tipo_pagamento, 
             tipo_operacao, total_original_calc, total_presente_calc, iof_total_calc, 
             total_liquido_pago_calc, incorre_custo_iof, cobrar_iof_cliente, 
             total_lucro_liquido_calc, media_dias_pond_calc, notas, natureza, status_contrato)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $clienteId,
            5.00,
            $dataOperacao,
            $dataBase,
            'direto',
            'antecipacao',
            10000.00,
            9500.00,
            50.00,
            9450.00,
            0,
            1,
            550.00,
            30,
            'Operacao de exemplo criada pelo instalador',
            'DESCONTO',
            'pendente'
        ]);
        $operacaoId = $pdo->lastInsertId();
        addLog($log, "Operacao de exemplo criada (ID: {$operacaoId}).");

        // 5. Recebiveis da operacao
        $dataVencimento = date('Y-m-d', strtotime('+30 days'));
        $stmt = $pdo->prepare("INSERT INTO recebiveis 
            (operacao_id, tipo_recebivel, valor_original, data_vencimento, 
             valor_presente_calc, iof_calc, valor_liquido_calc, dias_prazo_calc, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $operacaoId,
            'duplicata',
            5000.00,
            $dataVencimento,
            4750.00,
            25.00,
            4725.00,
            30,
            'Em Aberto'
        ]);
        $stmt->execute([
            $operacaoId,
            'duplicata',
            5000.00,
            date('Y-m-d', strtotime('+60 days')),
            4750.00,
            25.00,
            4725.00,
            60,
            'Em Aberto'
        ]);
        addLog($log, "2 recebiveis de exemplo criados.");

        // 6. Templates de contrato
        addLog($log, "Inserindo templates de contrato...");
        $stmt = $pdo->prepare("INSERT INTO contract_templates (code, name, description, template_content, version, active) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($contractTemplates as $tpl) {
            $stmt->execute([
                $tpl['code'],
                $tpl['name'],
                $tpl['description'],
                $tpl['content'],
                '1.0',
                1
            ]);
        }
        addLog($log, count($contractTemplates) . " templates de contrato inseridos.");

        // Fase D: Criar db_connection.php
        addLog($log, "Gerando db_connection.php...");
        $dbConnContent = "<?php\n";
        $dbConnContent .= "// db_connection.php - Gerado automaticamente pelo installer2.php\n\n";
        $dbConnContent .= "\$db_host = '" . addslashes($db_host) . "';\n";
        $dbConnContent .= "\$db_name = '" . addslashes($db_name) . "';\n";
        $dbConnContent .= "\$db_user = '" . addslashes($db_user) . "';\n";
        $dbConnContent .= "\$db_pass = '" . addslashes($db_pass) . "';\n";
        $dbConnContent .= "\$charset = 'utf8mb4';\n\n";
        $dbConnContent .= "\$options = [\n";
        $dbConnContent .= "    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n";
        $dbConnContent .= "    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n";
        $dbConnContent .= "    PDO::ATTR_EMULATE_PREPARES   => false,\n";
        $dbConnContent .= "];\n\n";
        $dbConnContent .= "\$dsn = \"mysql:host=\$db_host;port=3306;dbname=\$db_name;charset=\$charset\";\n\n";
        $dbConnContent .= "try {\n";
        $dbConnContent .= "    \$pdo = new PDO(\$dsn, \$db_user, \$db_pass, \$options);\n";
        $dbConnContent .= "} catch (\\PDOException \$e) {\n";
        $dbConnContent .= "    error_log(\"Erro de Conexao BD: \" . \$e->getMessage());\n";
        $dbConnContent .= "    die(\"Erro ao conectar com o banco de dados. Tente novamente mais tarde.\");\n";
        $dbConnContent .= "}\n";
        $dbConnContent .= "?>\n";

        if (file_put_contents(__DIR__ . '/db_connection.php', $dbConnContent) === false) {
            throw new Exception("Nao foi possivel criar o arquivo db_connection.php. Verifique permissoes de escrita.");
        }
        addLog($log, "db_connection.php criado com sucesso.");

        // Criar config.json padrao
        $configDefault = [
            "default_taxa_mensal" => 5.00,
            "iof_adicional_rate" => 0.0038,
            "iof_diaria_rate" => 0.000082
        ];
        file_put_contents(__DIR__ . '/config.json', json_encode($configDefault, JSON_PRETTY_PRINT));
        addLog($log, "config.json padrao criado.");

        $step = 2;
        $message = "Instalacao concluida com sucesso! O banco de dados foi replicado com a estrutura atual.";
        $messageType = "success";

    } catch (Exception $e) {
        $message = "Erro durante a instalacao: " . htmlspecialchars($e->getMessage());
        $messageType = "danger";
        addLog($log, "ERRO: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador v2 - Replicador de Estrutura</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="theme.css">
    <style>
        body { background-color: #f8f9fa; }
        .install-container { max-width: 700px; margin: 30px auto; }
        .card-header { background-color: #007cba; color: white; }
        .log-box { background: #1e1e1e; color: #d4d4d4; font-family: 'Courier New', monospace; font-size: 0.85rem; max-height: 300px; overflow-y: auto; padding: 15px; border-radius: 5px; }
        .log-box .success { color: #4ec9b0; }
        .log-box .error { color: #f44747; }
    </style>
</head>
<body>
    <div class="container install-container">
        <div class="card shadow-sm">
            <div class="card-header text-center py-3">
                <h2 class="h4 mb-0"><i class="bi bi-hdd-network"></i> Instalador v2 - Replicador</h2>
                <small>Replicar estrutura local no servidor online</small>
            </div>
            <div class="card-body p-4">
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($log)): ?>
                    <div class="mb-3">
                        <h6><i class="bi bi-terminal"></i> Log de Instalacao:</h6>
                        <div class="log-box">
                            <?php foreach ($log as $line): ?>
                                <div class="<?php echo strpos($line, 'ERRO') !== false ? 'error' : 'success'; ?>">
                                    <?php echo htmlspecialchars($line); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill"></i> <strong>Atenção:</strong>
                        Este instalador vai <strong>RECREAR</strong> todas as tabelas do banco de dados. 
                        Certifique-se de que o banco esta vazio ou faca backup antes de continuar.
                    </div>

                    <form method="POST" action="installer2.php">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="db_host" class="form-label">Servidor MySQL (Host)</label>
                                <input type="text" class="form-control" id="db_host" name="db_host" value="localhost" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="db_name" class="form-label">Nome do Banco de Dados</label>
                                <input type="text" class="form-control" id="db_name" name="db_name" value="factor_db" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="db_user" class="form-label">Usuario do Banco</label>
                                <input type="text" class="form-control" id="db_user" name="db_user" value="root" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="db_pass" class="form-label">Senha do Banco</label>
                                <input type="password" class="form-control" id="db_pass" name="db_pass">
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <h6><i class="bi bi-info-circle"></i> O que sera instalado:</h6>
                            <ul class="mb-0">
                                <li>18 tabelas com estrutura exata da versao local</li>
                                <li>1 usuario admin (login: <code>admin</code> / senha: <code>Qazwsx123@</code>)</li>
                                <li>1 cliente de exemplo (PJ)</li>
                                <li>1 operacao de exemplo com 2 recebiveis</li>
                                <li>Templates de contrato atualizados</li>
                            </ul>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirm" required>
                            <label class="form-check-label" for="confirm">
                                Entendo que isso vai recriar todas as tabelas do banco de dados.
                            </label>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-play-circle"></i> Iniciar Instalacao
                            </button>
                        </div>
                    </form>

                <?php elseif ($step === 2): ?>
                    <div class="text-center mb-4">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                        <h3 class="mt-3">Instalacao Concluida!</h3>
                        <p class="text-muted">O sistema esta pronto para uso no servidor online.</p>
                    </div>
                    
                    <div class="alert alert-danger">
                        <h5><i class="bi bi-shield-lock-fill"></i> Seguranca: Acoes Necessarias</h5>
                        <ol class="mb-0">
                            <li><strong>Exclua este arquivo (installer2.php)</strong> imediatamente</li>
                            <li>Altere a senha do usuario admin no primeiro login</li>
                            <li>Apague o cliente e a operacao de exemplo e cadastre os dados reais</li>
                        </ol>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <a href="index.php" class="btn btn-success btn-lg">
                            <i class="bi bi-box-arrow-in-right"></i> Acessar o Sistema
                        </a>
                    </div>
                <?php endif; ?>
                
            </div>
            <div class="card-footer text-center text-muted py-3">
                <small>Factor System - Instalador v2 &copy; <?php echo date('Y'); ?></small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
