<?php
/**
 * timezones.php — Fusos horários do Brasil (fonte única).
 *
 * Usado para validar (auth_check.php) e para montar o seletor (config.php).
 * Mantém o mapa IANA => rótulo amigável em um só lugar (DRY).
 */

/** Fuso padrão do sistema. */
const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

/**
 * Mapa de fusos do Brasil: identificador IANA => rótulo exibido.
 *
 * @return array<string, string>
 */
function brazilTimezones(): array
{
    return [
        'America/Noronha'      => 'Fernando de Noronha (UTC−2)',
        'America/Sao_Paulo'    => 'Brasília — Horário oficial (UTC−3)',
        'America/Bahia'        => 'Bahia (UTC−3)',
        'America/Fortaleza'    => 'Fortaleza / Nordeste (UTC−3)',
        'America/Recife'       => 'Recife (UTC−3)',
        'America/Maceio'       => 'Maceió (UTC−3)',
        'America/Belem'        => 'Belém (UTC−3)',
        'America/Araguaina'    => 'Araguaína / Tocantins (UTC−3)',
        'America/Santarem'     => 'Santarém (UTC−3)',
        'America/Campo_Grande' => 'Campo Grande / Mato Grosso do Sul (UTC−4)',
        'America/Cuiaba'       => 'Cuiabá / Mato Grosso (UTC−4)',
        'America/Manaus'       => 'Manaus / Amazonas (UTC−4)',
        'America/Porto_Velho'  => 'Porto Velho / Rondônia (UTC−4)',
        'America/Boa_Vista'    => 'Boa Vista / Roraima (UTC−4)',
        'America/Eirunepe'     => 'Eirunepé / Amazonas (oeste) (UTC−5)',
        'America/Rio_Branco'   => 'Rio Branco / Acre (UTC−5)',
    ];
}

/**
 * Normaliza um fuso recebido: retorna o próprio se for um fuso brasileiro
 * válido, caso contrário o padrão do sistema.
 */
function resolveTimezone(?string $tz): string
{
    return ($tz !== null && array_key_exists($tz, brazilTimezones()))
        ? $tz
        : DEFAULT_TIMEZONE;
}
