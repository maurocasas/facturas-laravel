<?php

namespace Vant\Facturas\Console\Support;

/**
 * The help pages (`cli/help.ts`). Artisan already prints the usage and the
 * options of every command, so a page here keeps what Artisan cannot know:
 * the summary, the examples and the notes on what the command writes,
 * caches or touches.
 */
final class Help
{
    /** What each command does, in the words the docs use. */
    public const CLI_COMMAND_SUMMARIES = [
        'init' => 'clave privada y CSR, más los pasos exactos en ARCA',
        'cert' => 'pega el certificado que te dio ARCA y lo guarda',
        'check' => 'prueba cada capa en orden y nombra la que falla',
        'issue' => 'una factura de ARS 1 en homologación, solo a pedido',
    ];

    private const INDENT = '  ';

    private const PAGES = [
        'init' => [
            'examples' => [
                'php artisan facturas:init --cuit 20123456786 --arca-env test',
                'php artisan facturas:init --cuit 20-12345678-6 --arca-env production --name mi-sistema',
            ],
            'notes' => [
                'Escribe arca-<entorno>.key con permisos 0600 y arca-<entorno>.csr en',
                '--dir (por defecto storage/app/private/arca), y nunca escribe en ARCA.',
                'En homologación copia el CSR al portapapeles, y si no puede lo imprime',
                'para copiarlo de la terminal. Al final pide el certificado y lo guarda',
                'como arca-<entorno>.crt; con Ctrl-C, --no-paste o sin terminal, lo',
                'guardás vos o lo pegás después con facturas:cert. En una terminal',
                'pregunta el CUIT y el entorno; sin terminal, --cuit y --arca-env son',
                'obligatorios.',
            ],
        ],
        'cert' => [
            'examples' => [
                'php artisan facturas:cert',
                'php artisan facturas:cert --arca-env production',
                'php artisan facturas:cert --dir credenciales --force',
            ],
            'notes' => [
                'Pegás el certificado que te dio ARCA, de -----BEGIN CERTIFICATE----- a',
                '-----END CERTIFICATE-----, y lo guarda como arca-<entorno>.crt junto a',
                'la clave que generó facturas:init. Antes verifica que sea el certificado',
                'de esa clave y de ese CUIT, y si no, no escribe nada. Es lo mismo que',
                'init pregunta al final: cert está para cuando lo dejaste para después.',
            ],
        ],
        'check' => [
            'examples' => [
                'php artisan facturas:check',
                'php artisan facturas:check --sales-point 3',
                'php artisan facturas:check --cert arca-test.crt --key arca-test.key',
            ],
            'notes' => [
                'Busca cada valor en este orden: los flags, config/facturas.php (las',
                'variables ARCA_*) y, por último, arca-<entorno>.crt y arca-<entorno>.key',
                'en --dir; de ahí sale también el CUIT y el entorno. Nunca escribe en',
                'ARCA: solo lee. Guarda el ticket WSAA en el directorio temporal del',
                'sistema para poder repetirse, porque ARCA rechaza un login nuevo hasta',
                'unos minutos después del anterior. --no-cache no lo lee ni lo escribe.',
            ],
        ],
        'issue' => [
            'examples' => [
                'php artisan facturas:issue --sales-point 3 --issuer monotributo',
                'php artisan facturas:issue --sales-point 3 --issuer responsable_inscripto --json',
            ],
            'notes' => [
                'Las condiciones de emisor son monotributo, responsable_inscripto,',
                'exento y no_alcanzado. Emite un comprobante real de homologación y se',
                'niega fuera de test: corre antes las capas de facturas:check, con la',
                'misma búsqueda de configuración, y no sigue si alguna falla. En una',
                'terminal pregunta el punto de venta y el emisor.',
            ],
        ],
    ];

    /**
     * Renders one help page. Pure: the same text with and without ANSI, so
     * `--no-color` output is the colored one minus the escapes.
     *
     * @param  'init'|'cert'|'check'|'issue'  $topic
     * @param  array{color: bool}  $options
     */
    public static function renderHelp(string $topic, array $options = ['color' => false]): string
    {
        $painter = Output::createPainter($options['color']);
        $page = self::PAGES[$topic];
        $lines = ['facturas-laravel '.Version::readCliVersion(), '', self::INDENT.self::CLI_COMMAND_SUMMARIES[$topic], ''];

        $lines[] = $painter->dim('Ejemplos:');
        $lines[] = '';
        foreach ($page['examples'] as $example) {
            $lines[] = self::INDENT.$painter->dim('$').' '.$painter->cyan($example);
        }

        $lines[] = '';
        $lines[] = $painter->dim('Notas:');
        $lines[] = '';
        foreach ($page['notes'] as $note) {
            $lines[] = self::INDENT.$note;
        }

        return implode("\n", $lines)."\n";
    }
}
