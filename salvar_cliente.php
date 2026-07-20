<?php
require_once 'auth_check.php';
require_once 'db_connection.php';
require_once 'functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function limparOutputBufferSeNecessario() {
    if (ob_get_level() > 0) {
        ob_clean();
    }
}

function buildClienteFormUrl($clienteId, $messageType, $message) {
    $params = [
        'status' => $messageType,
        'msg' => $message
    ];

    if (!empty($clienteId)) {
        $params['id'] = (int) $clienteId;
    }

    return 'form_cliente.php?' . http_build_query($params);
}

function salvarFlashFormularioCliente($clienteId, array $postData) {
    $clienteData = $postData;
    unset($clienteData['id'], $clienteData['socios']);

    $_SESSION['cliente_form_flash'] = [
        'cliente_id' => !empty($clienteId) ? (int) $clienteId : null,
        'cliente' => $clienteData,
        'socios' => array_values($postData['socios'] ?? [])
    ];
}

function limparFlashFormularioCliente() {
    unset($_SESSION['cliente_form_flash']);
}

function redirectClienteForm($clienteId, $messageType, $message) {
    limparOutputBufferSeNecessario();
    header('Location: ' . buildClienteFormUrl($clienteId, $messageType, $message));
    exit;
}

function redirectClienteFormComFlash($clienteId, $messageType, $message, array $postData) {
    salvarFlashFormularioCliente($clienteId, $postData);
    redirectClienteForm($clienteId, $messageType, $message);
}

$clienteId = isset($_POST['id']) ? (int)$_POST['id'] : null;
$isEditMode = $clienteId !== null;
$empresa = trim($_POST['empresa'] ?? '');
$email = trim($_POST['email'] ?? '');
$telefone = trim($_POST['telefone'] ?? '');
$whatsapp = trim($_POST['whatsapp'] ?? '');
$endereco = trim($_POST['endereco'] ?? '');
$tipoPessoa = 'JURIDICA';
$porte = trim($_POST['porte'] ?? '');

// Representante
$representante_nome = trim($_POST['representante_nome'] ?? '');
$representante_cpf = trim($_POST['representante_cpf'] ?? '');
$representante_rg = trim($_POST['representante_rg'] ?? '');
$representante_nacionalidade = trim($_POST['representante_nacionalidade'] ?? 'brasileiro(a)');
$representante_estado_civil = trim($_POST['representante_estado_civil'] ?? '');
$representante_profissao = trim($_POST['representante_profissao'] ?? '');
$representante_endereco = trim($_POST['representante_endereco'] ?? '');

// Novos campos de endereço
$cep = trim($_POST['cep'] ?? '');
$logradouro = trim($_POST['logradouro'] ?? '');
$numero = trim($_POST['numero'] ?? '');
$complemento = trim($_POST['complemento'] ?? '');
$bairro = trim($_POST['bairro'] ?? '');
$cidade = trim($_POST['cidade'] ?? '');
$estado = trim($_POST['estado'] ?? '');

// Dados bancários
$conta_banco = trim($_POST['conta_banco'] ?? '');
$conta_agencia = trim($_POST['conta_agencia'] ?? '');
$conta_numero = trim($_POST['conta_numero'] ?? '');
$conta_tipo = trim($_POST['conta_tipo'] ?? '');
$conta_pix = trim($_POST['conta_pix'] ?? '');
$conta_titular = trim($_POST['conta_titular'] ?? '');
$conta_documento = trim($_POST['conta_documento'] ?? '');
$conta_pix_tipo = trim($_POST['conta_pix_tipo'] ?? '');

// Outros
$anotacoes = trim($_POST['anotacoes'] ?? '');

// Documento principal (CPF ou CNPJ)
$documentoPrincipal = preg_replace('/\D/', '', trim($_POST['documento_principal'] ?? ''));

if ($tipoPessoa === 'JURIDICA') {
    $conta_titular = $empresa;
    $conta_documento = $documentoPrincipal;
}

// Se for FISICA, forçar dados do representante
if ($tipoPessoa === 'FISICA') {
    $representante_nome = $empresa;
    $representante_cpf = $documentoPrincipal;
}

// Sócios
$socios = $_POST['socios'] ?? [];

$message = '';
$messageType = 'danger'; // Padrão para erro
$temAvisoDocumento = false; // Sinaliza documento(s) em branco/zerado(s)

// Validação básica (Razão Social continua obrigatória; documento é opcional)
if (empty($empresa)) {
    $message = "Razão Social é um campo obrigatório.";
    redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
}

// Validação de email
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $message = "Por favor, insira um email válido.";
    redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
}

// Validação do documento principal (CNPJ).
// Branco ou só zeros: aceita com aviso. Caso contrário, valida normalmente.
$expectedLength = 14;
$documentoTipo = 'CNPJ';

if (documentoVazioOuZerado($documentoPrincipal)) {
    $temAvisoDocumento = true;
} else {
    if (strlen($documentoPrincipal) !== $expectedLength) {
        $message = "{$documentoTipo} deve ter {$expectedLength} dígitos.";
        redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
    }

    if ($tipoPessoa === 'JURIDICA' && !validaCNPJ($documentoPrincipal)) {
        $message = "O CNPJ informado é inválido.";
        redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
    }
}

// Validação de Representante CPF (branco/zerado aceita com aviso)
if (!empty($representante_cpf)) {
    $repCpfLimpo = preg_replace('/\D/', '', $representante_cpf);
    if (documentoVazioOuZerado($repCpfLimpo)) {
        $temAvisoDocumento = true;
    } elseif (!validaCPF($repCpfLimpo)) {
        $message = "O CPF do representante é inválido.";
        redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
    }
}

// Validação da Conta Documento (pode ser CPF ou CNPJ)
if (!empty($conta_documento)) {
    $contaDocLimpo = preg_replace('/\D/', '', $conta_documento);
    if (strlen($contaDocLimpo) === 11 && !validaCPF($contaDocLimpo)) {
        $message = "O CPF do titular da conta é inválido.";
        redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
    } elseif (strlen($contaDocLimpo) === 14 && !validaCNPJ($contaDocLimpo)) {
        $message = "O CNPJ do titular da conta é inválido.";
        redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
    } elseif (strlen($contaDocLimpo) !== 11 && strlen($contaDocLimpo) !== 14) {
        $message = "O documento do titular da conta deve ser um CPF (11) ou CNPJ (14) válido.";
        redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
    }
}

// Validação dos sócios (nome obrigatório; CPF branco/zerado aceita com aviso)
foreach ($socios as $index => $socio) {
    if (empty($socio['nome'])) {
        $message = "Nome é obrigatório para todos os sócios.";
        redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
    }

    $cpfLimpo = preg_replace('/\D/', '', $socio['cpf'] ?? '');
    if (documentoVazioOuZerado($cpfLimpo)) {
        $temAvisoDocumento = true;
    } elseif (strlen($cpfLimpo) !== 11 || !validaCPF($cpfLimpo)) {
        $message = "CPF do sócio {$socio['nome']} é inválido.";
        redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
    }

    // Atualizar array com CPF limpo
    $socios[$index]['cpf'] = $cpfLimpo;
}

try {
    $pdo->beginTransaction();
    
    if ($clienteId) { // Modo de Edição
        $stmt = $pdo->prepare("UPDATE clientes SET
                                empresa = :empresa,
                                email = :email,
                                telefone = :telefone,
                                whatsapp = :whatsapp,
                                tipo_pessoa = :tipo_pessoa,
                                porte = :porte,
                                documento_principal = :documento_principal,
                                endereco = :endereco,
                                cep = :cep,
                                logradouro = :logradouro,
                                numero = :numero,
                                complemento = :complemento,
                                bairro = :bairro,
                                cidade = :cidade,
                                estado = :estado,
                                representante_nome = :representante_nome,
                                representante_cpf = :representante_cpf,
                                representante_rg = :representante_rg,
                                representante_nacionalidade = :representante_nacionalidade,
                                representante_estado_civil = :representante_estado_civil,
                                representante_profissao = :representante_profissao,
                                representante_endereco = :representante_endereco,
                                conta_banco = :conta_banco,
                                conta_agencia = :conta_agencia,
                                conta_numero = :conta_numero,
                                conta_tipo = :conta_tipo,
                                conta_pix = :conta_pix,
                                conta_pix_tipo = :conta_pix_tipo,
                                conta_titular = :conta_titular,
                                conta_documento = :conta_documento,
                                anotacoes = :anotacoes
                                WHERE id = :id");
        $stmt->bindParam(':id', $clienteId, PDO::PARAM_INT);
    } else { // Modo de Adição
        $stmt = $pdo->prepare("INSERT INTO clientes (
            empresa, email, telefone, whatsapp, tipo_pessoa, porte, documento_principal, endereco, 
            cep, logradouro, numero, complemento, bairro, cidade, estado, nome,
            representante_nome, representante_cpf, representante_rg, representante_nacionalidade,
            representante_estado_civil, representante_profissao, representante_endereco,
            conta_banco, conta_agencia, conta_numero, conta_tipo, conta_pix, conta_pix_tipo, conta_titular, conta_documento, anotacoes
        ) VALUES (
            :empresa, :email, :telefone, :whatsapp, :tipo_pessoa, :porte, :documento_principal, :endereco, 
            :cep, :logradouro, :numero, :complemento, :bairro, :cidade, :estado, :nome,
            :representante_nome, :representante_cpf, :representante_rg, :representante_nacionalidade,
            :representante_estado_civil, :representante_profissao, :representante_endereco,
            :conta_banco, :conta_agencia, :conta_numero, :conta_tipo, :conta_pix, :conta_pix_tipo, :conta_titular, :conta_documento, :anotacoes
        )");
    }

    // Processamento de nulos para campos ENUM e opcionais
    $porte_val = !empty($porte) ? $porte : null;

    // Documento branco/zerado vai como NULL: a coluna documento_principal tem
    // índice UNIQUE, e gravar "00000000000000" literal impediria cadastrar um
    // segundo cliente sem documento (Duplicate entry). UNIQUE ignora NULLs.
    $documentoPrincipalValor = documentoVazioOuZerado($documentoPrincipal) ? null : $documentoPrincipal;

    $stmt->bindParam(':empresa', $empresa);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':telefone', $telefone);
    $stmt->bindParam(':whatsapp', $whatsapp);
    $stmt->bindParam(':tipo_pessoa', $tipoPessoa);
    $stmt->bindParam(':documento_principal', $documentoPrincipalValor);
    $stmt->bindParam(':endereco', $endereco);
    $stmt->bindParam(':cep', $cep);
    $stmt->bindParam(':logradouro', $logradouro);
    $stmt->bindParam(':numero', $numero);
    $stmt->bindParam(':complemento', $complemento);
    $stmt->bindParam(':bairro', $bairro);
    $stmt->bindParam(':cidade', $cidade);
    $stmt->bindParam(':estado', $estado);
    
    // Bind representante
    $stmt->bindParam(':representante_nome', $representante_nome);
    $stmt->bindParam(':representante_cpf', $representante_cpf);
    $stmt->bindParam(':representante_rg', $representante_rg);
    $stmt->bindParam(':representante_nacionalidade', $representante_nacionalidade);
    $stmt->bindParam(':representante_estado_civil', $representante_estado_civil);
    $stmt->bindParam(':representante_profissao', $representante_profissao);
    $stmt->bindParam(':representante_endereco', $representante_endereco);
    
    // Bind dados bancários
    $stmt->bindParam(':conta_banco', $conta_banco);
    $stmt->bindParam(':conta_agencia', $conta_agencia);
    $stmt->bindParam(':conta_numero', $conta_numero);
    $stmt->bindParam(':conta_tipo', $conta_tipo);
    $stmt->bindParam(':conta_pix', $conta_pix);
    $stmt->bindParam(':conta_pix_tipo', $conta_pix_tipo);
    $stmt->bindParam(':conta_titular', $conta_titular);
    $stmt->bindParam(':conta_documento', $conta_documento);
    
    // Bind anotações
    $stmt->bindParam(':anotacoes', $anotacoes);
    
    if (!$clienteId) {
        $stmt->bindParam(':nome', $empresa); // nome = empresa
    }
    $stmt->bindParam(':porte', $porte_val);

    if (!$stmt->execute()) {
        throw new Exception("Erro ao salvar dados do cliente: " . implode(" ", $stmt->errorInfo()));
    }

    // Obter ID do cliente (para inserção ou atualização)
    if (!$clienteId) {
        $clienteId = $pdo->lastInsertId();
    }

    // Processar sócios
    if ($clienteId) {
        // Primeiro, marcar todos os sócios existentes para exclusão
        $sociosExistentesIds = [];
        
        foreach ($socios as $socio) {
            if (!empty($socio['id'])) {
                $sociosExistentesIds[] = (int)$socio['id'];
            }
        }
        
        // Excluir sócios que não estão mais na lista
        if (!empty($sociosExistentesIds)) {
            $placeholders = str_repeat('?,', count($sociosExistentesIds) - 1) . '?';
            $stmt_delete = $pdo->prepare("DELETE FROM clientes_socios WHERE cliente_id = ? AND id NOT IN ($placeholders)");
            $params = array_merge([$clienteId], $sociosExistentesIds);
            $stmt_delete->execute($params);
        } else {
            // Se não há sócios existentes, excluir todos
            $stmt_delete = $pdo->prepare("DELETE FROM clientes_socios WHERE cliente_id = ?");
            $stmt_delete->execute([$clienteId]);
        }
        
        // Inserir ou atualizar sócios
        foreach ($socios as $socio) {
            if (!empty($socio['id'])) {
                // Atualizar sócio existente
                $stmt_socio = $pdo->prepare("UPDATE clientes_socios SET nome = ?, cpf = ? WHERE id = ? AND cliente_id = ?");
                $stmt_socio->execute([
                    trim($socio['nome']),
                    $socio['cpf'],
                    (int)$socio['id'],
                    $clienteId
                ]);
            } else {
                // Inserir novo sócio
                $stmt_socio = $pdo->prepare("INSERT INTO clientes_socios (cliente_id, nome, cpf) VALUES (?, ?, ?)");
                $stmt_socio->execute([
                    $clienteId,
                    trim($socio['nome']),
                    $socio['cpf']
                ]);
            }
        }
    }

    $pdo->commit();
    limparFlashFormularioCliente();
    
    if ($temAvisoDocumento) {
        $message = "Cliente " . ($isEditMode ? "atualizado" : "adicionado")
            . ", mas com documento(s) em branco ou zerado(s) — o valor jurídico do contrato pode ser afetado.";
        $messageType = "warning";
    } else {
        $message = "Cliente " . ($isEditMode ? "atualizado" : "adicionado") . " com sucesso!";
        $messageType = "success";
    }

} catch (PDOException $e) {
    $pdo->rollBack();
    
    if ($e->getCode() == '23000') { // Código de erro para violação de UNIQUE
        $message = "Erro: O documento informado já está cadastrado para outro cliente.";
    } else {
        $message = "Erro no banco de dados ao salvar cliente: " . $e->getMessage();
    }
    redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
} catch (Exception $e) {
    $pdo->rollBack();
    $message = "Erro ao salvar cliente: " . $e->getMessage();
    redirectClienteFormComFlash($clienteId, 'error', $message, $_POST);
}

redirectClienteForm($clienteId, $messageType, $message);
?>
