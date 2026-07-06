<?php
// functions.php - Funções auxiliares para o sistema de factoring

// Funções de formatação
if (!function_exists('formatCurrency')) {
    function formatCurrency($value) {
        return 'R$ ' . number_format($value ?? 0, 2, ',', '.');
    }
}

if (!function_exists('formatHtmlCurrency')) {
    function formatHtmlCurrency($value) {
        return 'R$ ' . number_format($value ?? 0, 2, ',', '.');
    }
}

if (!function_exists('formatPercent')) {
    function formatPercent($value) {
        return number_format(($value ?? 0) * 100, 2, ',', '.') . ' %';
    }
}

if (!function_exists('formatHtmlPercentage')) {
    function formatHtmlPercentage($value, $decimals = 2) {
        return number_format($value ?? 0, $decimals, ',', '.') . '%';
    }
}

// Função para formatar data
if (!function_exists('formatDate')) {
    function formatDate($dateStr) {
        if (empty($dateStr)) return '--';
        try {
            return (new DateTime($dateStr))->format('d/m/Y');
        } catch (Exception $e) {
            return 'Data Inválida';
        }
    }
}

// Função para calcular dias entre datas
if (!function_exists('calcularDias')) {
    function calcularDias($dataInicio, $dataFim) {
        try {
            $inicio = new DateTime($dataInicio);
            $fim = new DateTime($dataFim);
            $diff = $inicio->diff($fim);
            return $diff->days;
        } catch (Exception $e) {
            return 0;
        }
    }
}

// Função para calcular dias para vencimento (considera apenas data, sem horário)
if (!function_exists('calcularDiasParaVencimento')) {
    function calcularDiasParaVencimento($dataVencimento) {
        if (empty($dataVencimento)) {
            return 'N/A';
        }
        
        try {
            // Criar objetos DateTime apenas com data (sem horário) para evitar problemas de timezone
            $hoje = new DateTime('today'); // Meia-noite de hoje
            $vencimento = new DateTime($dataVencimento);
            $vencimento->setTime(0, 0, 0); // Garantir que seja meia-noite
            
            $interval = $hoje->diff($vencimento);
            $dias = $interval->days;
            
            if ($vencimento < $hoje) {
                // Já venceu - mostrar negativo
                return '-' . $dias;
            } elseif ($vencimento->format('Y-m-d') === $hoje->format('Y-m-d')) {
                // Vence hoje - mostrar "hoje"
                return 'hoje';
            } else {
                // Vence no futuro - mostrar número de dias
                return $dias;
            }
        } catch (Exception $e) {
            return 'Erro Data';
        }
    }
}

// Função para validar data
if (!function_exists('validarData')) {
    function validarData($data) {
        if (empty($data)) return false;
        try {
            $d = DateTime::createFromFormat('Y-m-d', $data);
            return $d && $d->format('Y-m-d') === $data;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('validaCPF')) {
    function validaCPF($cpf) {
        $cpf = preg_replace('/[^0-9]/is', '', $cpf);
        if (strlen($cpf) != 11) return false;
        if ($cpf === '00000000000') return true;
        if (preg_match('/(\d)\1{10}/', $cpf)) return false;
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) return false;
        }
        return true;
    }
}

if (!function_exists('validaCNPJ')) {
    function validaCNPJ($cnpj) {
        $cnpj = preg_replace('/[^0-9]/', '', (string) $cnpj);
        if (strlen($cnpj) != 14) return false;
        if ($cnpj === '00000000000000') return true;
        if (preg_match('/(\d)\1{13}/', $cnpj)) return false;
        for ($i = 0, $j = 5, $soma = 0; $i < 12; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }
        $resto = $soma % 11;
        if ($cnpj[12] != ($resto < 2 ? 0 : 11 - $resto)) return false;
        for ($i = 0, $j = 6, $soma = 0; $i < 13; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }
        $resto = $soma % 11;
        return $cnpj[13] == ($resto < 2 ? 0 : 11 - $resto);
    }
}

// Considera um documento "vazio ou zerado" (branco ou só zeros).
// Nesses casos o cadastro é aceito com aviso, em vez de bloqueado.
if (!function_exists('documentoVazioOuZerado')) {
    function documentoVazioOuZerado($doc) {
        $limpo = preg_replace('/\D/', '', (string) $doc);
        return $limpo === '' || preg_match('/^0+$/', $limpo) === 1;
    }
}

// Função para validar valor numérico
if (!function_exists('validarValor')) {
    function validarValor($valor) {
        return is_numeric($valor) && $valor > 0;
    }
}

// Função para sanitizar entrada
if (!function_exists('sanitizar')) {
    function sanitizar($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// Função para log de erros
if (!function_exists('logError')) {
    function logError($message, $context = []) {
        $logMessage = date('Y-m-d H:i:s') . ' - ' . $message;
        if (!empty($context)) {
            $logMessage .= ' - Context: ' . json_encode($context);
        }
        error_log($logMessage);
    }
}