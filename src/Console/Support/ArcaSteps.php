<?php

namespace Vant\Facturas\Console\Support;

/**
 * The ARCA steps `facturas:init` prints (`cli/arca-steps.ts`). Page, menu,
 * field and button names live in one table so a rename is one edit here.
 * Verified upstream against the official references: the WSASS user manual,
 * `WSASS_como_adherirse.pdf`, `WSAA.ObtenerCertificado.pdf` and
 * `ADMINREL.DelegarWS.pdf`.
 */
final class ArcaSteps
{
    public const ARCA_PAGES = [
        'login' => 'https://auth.afip.gob.ar/contribuyente_/login.xhtml',
        'wsassService' => 'WSASS - Autogestión Certificados Homologación',
        'wsassNewCertificate' => 'Nuevo Certificado',
        'wsassNewCertificateButton' => 'Crear DN y Obtener Certificado',
        'wsassAuthorizeService' => 'Crear autorización a servicio',
        'wsassAuthorizeButton' => 'Crear Autorización de Acceso',
        'wsassDnField' => 'Nombre simbólico del DN',
        'wsassCsrField' => 'Solicitud de certificado',
        'wsassAuthorizeDnField' => 'Nombre simbólico del DN a autorizar',
        'wsassRepresentedField' => 'CUIT representado',
        'wsassServiceField' => 'Servicio al que desea acceder',
        'wsfeService' => 'wsfe - Facturación Electrónica',
        'certificates' => 'Administración de Certificados Digitales',
        'certificatesAddAlias' => 'Agregar alias',
        'relationships' => 'Administrador de Relaciones',
        'relationshipsNew' => 'Nueva Relación',
        'relationshipsAdhere' => 'Adherir Servicio',
        'relationshipsPath' => 'Nueva Relación → Webservices → Facturación Electrónica',
        'salesPoints' => 'Administración de Puntos de Venta y Domicilios',
        'salesPointsSystem' => 'RECE para aplicativo y Web Services',
        'salesPointsSystemMonotributo' => 'Factura Electrónica – Monotributo – Web Services',
    ];

    private const STEP_INDENT = '  ';

    /** Where a wrapped step line starts: under the step text, not under its number. */
    private const WRAP_INDENT = '     ';

    private const FIELD_INDENT = '       ';

    /** Spaces between the widest label of a step and its value column. */
    private const COLUMN_GAP = 3;

    /**
     * The ARCA steps for one environment, as a single linear list. `init`
     * already knows the environment, so the other one is noise.
     *
     * @param  'test'|'production'  $environment
     * @param  array{alias: string, taxId: string, csrName: string, certificateName: string, clipboard: bool, csrPem: string, paste: bool}  $context
     * @return array{heading: string, lines: list<array{text: string}>}
     */
    public static function buildArcaPlan(string $environment, array $context, Painter $painter): array
    {
        return $environment === 'test'
            ? self::buildTestPlan($context, $painter)
            : self::buildProductionPlan($context, $painter);
    }

    private static function buildTestPlan(array $context, Painter $painter): array
    {
        $pages = self::ARCA_PAGES;
        $name = fn (string $text) => $painter->bold("\"{$text}\"");

        return [
            'heading' => 'Listo. Ahora en ARCA, para homologación:',
            'lines' => [
                ...self::step(1, ['Entrá con clave fiscal en', $pages['login']]),
                ...self::step(2, [
                    "Abrí {$name($pages['wsassService'])} en Mis Servicios.",
                    "Si no está, agregalo en {$pages['relationships']} → {$pages['relationshipsAdhere']}",
                    '→ ARCA → Servicios Interactivos → WSASS, y volvé a entrar. Va con tu',
                    'clave fiscal de persona física, nivel 2 o superior: no es delegable.',
                ]),
                ...self::step(
                    3,
                    ["En el menú, {$name($pages['wsassNewCertificate'])}:"],
                    [
                        ['label' => $pages['wsassDnField'], 'value' => $context['alias']],
                        self::csrField($context),
                    ],
                    ["Apretá {$name($pages['wsassNewCertificateButton'])}."]
                ),
                ...self::step(4, [
                    'El certificado sale en el cuadro de resultado, de',
                    '-----BEGIN CERTIFICATE----- a -----END CERTIFICATE-----.',
                    $context['paste']
                        ? 'Copialo entero y pegalo acá abajo.'
                        : "Copialo entero y guardalo acá como {$context['certificateName']}.",
                ]),
                ...self::step(
                    5,
                    ["En el menú, {$name($pages['wsassAuthorizeService'])}:"],
                    [
                        ['label' => $pages['wsassAuthorizeDnField'], 'value' => $context['alias']],
                        ['label' => $pages['wsassRepresentedField'], 'value' => $context['taxId']],
                        ['label' => $pages['wsassServiceField'], 'value' => $pages['wsfeService']],
                    ],
                    ["Apretá {$name($pages['wsassAuthorizeButton'])}."]
                ),
            ],
        ];
    }

    private static function buildProductionPlan(array $context, Painter $painter): array
    {
        $pages = self::ARCA_PAGES;
        $name = fn (string $text) => $painter->bold("\"{$text}\"");

        return [
            'heading' => 'Listo. Ahora en ARCA, para producción:',
            'lines' => [
                ...self::step(1, ['Entrá con clave fiscal en', $pages['login']]),
                ...self::step(2, [
                    "Abrí {$name($pages['certificates'])} en Mis Servicios.",
                    "Si no está, agregalo en {$pages['relationships']} → {$pages['relationshipsNew']}",
                    '→ BUSCAR → Servicios Interactivos → Administración de Certificados',
                    'Digitales → Confirmar, y volvé a entrar.',
                ]),
                ...self::step(
                    3,
                    ["Apretá {$name($pages['certificatesAddAlias'])}:"],
                    [
                        ['label' => 'Alias', 'value' => $context['alias']],
                        ['label' => 'Seleccionar archivo', 'value' => $context['csrName']],
                    ],
                    ["Apretá {$name($pages['certificatesAddAlias'])} para subirlo."]
                ),
                ...self::step(4, [
                    "En la lista, entrá con {$name('Ver')} y usá el icono {$name('Descargar')}",
                    'para bajar el certificado (archivo CRT).',
                    $context['paste']
                        ? "Guardalo acá como {$context['certificateName']}, o abrilo y pegalo abajo."
                        : "Guardalo acá como {$context['certificateName']}.",
                ]),
                ...self::step(
                    5,
                    ["Volvé a {$pages['relationships']}, {$name($pages['relationshipsNew'])}:"],
                    [
                        ['label' => 'Servicio', 'value' => 'BUSCAR → Webservices → Facturación Electrónica'],
                        ['label' => 'Representante', 'value' => "BUSCAR → el computador fiscal {$context['alias']}"],
                    ],
                    ["Apretá {$name('Confirmar')}, revisá y volvé a apretar {$name('Confirmar')}."]
                ),
            ],
        ];
    }

    /**
     * One numbered step: its text, an optional aligned `label   value` block and
     * the lines that come after it.
     *
     * @param  list<string>  $text
     * @param  list<array{label: string, value: string, lines?: list<string>}>  $fields
     * @param  list<string>  $after
     * @return list<array{text: string}>
     */
    private static function step(int $number, array $text, array $fields = [], array $after = []): array
    {
        $first = array_shift($text);
        $lines = [['text' => self::STEP_INDENT."{$number}. {$first}"]];
        foreach ($text as $line) {
            $lines[] = ['text' => self::WRAP_INDENT.$line];
        }
        $width = self::columnWidth($fields);
        foreach ($fields as $field) {
            $padding = str_repeat(' ', $width - mb_strlen($field['label']));
            $lines[] = ['text' => self::FIELD_INDENT."{$field['label']}:{$padding}{$field['value']}"];
            foreach ($field['lines'] ?? [] as $line) {
                $lines[] = ['text' => self::FIELD_INDENT.$line];
            }
        }
        foreach ($after as $line) {
            $lines[] = ['text' => self::WRAP_INDENT.$line];
        }

        return $lines;
    }

    /**
     * The CSR row of step 3. With the clipboard it is one word; without it the
     * whole request goes under the field, because a terminal is the only place
     * the user can copy it from without opening an editor.
     */
    private static function csrField(array $context): array
    {
        if ($context['clipboard']) {
            return ['label' => self::ARCA_PAGES['wsassCsrField'], 'value' => 'pegá (ya está en tu portapapeles)'];
        }

        return [
            'label' => self::ARCA_PAGES['wsassCsrField'],
            'value' => 'copiá esto entero:',
            'lines' => array_values(array_filter(
                array_map('trim', explode("\n", $context['csrPem'])),
                fn (string $line) => $line !== ''
            )),
        ];
    }

    /** Where the values start, counting the colon the labels get when printed. */
    private static function columnWidth(array $fields): int
    {
        $widest = 0;
        foreach ($fields as $field) {
            $widest = max($widest, mb_strlen($field['label']));
        }

        return $widest + self::COLUMN_GAP;
    }
}
