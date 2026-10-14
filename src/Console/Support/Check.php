<?php

namespace Facturas\Console\Support;

use DateTimeImmutable;
use Throwable;
use Facturas\ArcaClient;
use Facturas\Config\ArcaConfig;
use Facturas\Config\ServiceConfig;
use Facturas\Errors\ArcaAuthenticationError;
use Facturas\Errors\ArcaConfigurationError;
use Facturas\Errors\ArcaServiceError;
use Facturas\Errors\ArcaSoapFaultError;

/**
 * The layered diagnosis behind `facturas:check` and `facturas:issue`
 * (`cli/check.ts`): configuration → certificate and key → WSAA login →
 * WSFE authorization → sales points, stopping at the first layer that fails.
 * Never writes to ARCA.
 *
 * Flags: cert, key, taxId, env, dir, salesPoint, noCache.
 * A report: {ok, environment?, taxId?, layers: list<layer>, salesPoints?}, and
 * a layer: {name, ok, detail?, expiresAt?, diagnosis?, fix?, code?, reason?, warning?}.
 */
final class Check
{
    /** The five layers, in the order ARCA breaks them. */
    public const LAYER_LABELS = [
        'config' => 'configuración',
        'certificate' => 'certificado y clave',
        'wsaa' => 'WSAA',
        'wsfe' => 'WSFE',
        'salesPoints' => 'puntos de venta',
    ];

    private const EXPIRY_WARNING_DAYS = 30;

    private const EMPTY_IN_TEST_WARNING = 'en homologación ARCA suele no informarlos; probá igual con issue';

    private const SECONDS_PER_DAY = 86_400;

    /**
     * WSFE's `PtoVta` is an integer from 1 to 99999. Anything a number only
     * starts with —`3foo`, `3.5`, `1e2`— is a typo, not a sales point: parsing
     * it would silently work on a different one than the person typed.
     */
    private const SALES_POINT_MIN = 1;

    private const SALES_POINT_MAX = 99_999;

    /** The sales point a string names, or null when it names none. */
    public static function parseSalesPoint(string $value): ?int
    {
        if (! preg_match('/^\d+$/', $value)) {
            return null;
        }
        $digits = ltrim($value, '0');
        if (strlen($digits) > 5) {
            return null;
        }
        $parsed = (int) $value;

        return $parsed >= self::SALES_POINT_MIN && $parsed <= self::SALES_POINT_MAX ? $parsed : null;
    }

    /** Why that string is not a sales point, in the words the CLI uses. */
    public static function describeSalesPointProblem(string $value): string
    {
        $reason = preg_match('/^\d+$/', $value)
            ? 'Tiene que estar entre '.self::SALES_POINT_MIN.' y '.self::SALES_POINT_MAX
            : 'Tiene que ser un número entero';

        return "Punto de venta inválido: {$value}. {$reason}, por ejemplo --sales-point 3.";
    }

    /**
     * Runs the layers in order and stops at the first failure.
     *
     * @return array{report: array, client?: ArcaClient}
     */
    public static function runCheckLayers(CliIo $io, array $flags): array
    {
        $layers = [];
        $resolved = self::resolveConfigLayer($io, $flags);
        $layers[] = $resolved['layer'];
        if (! ($resolved['layer']['ok'] && isset($resolved['config']))) {
            return ['report' => ['ok' => false, 'layers' => $layers]];
        }

        /** @var ArcaConfig $config */
        $config = $resolved['config'];
        $report = ['ok' => false, 'environment' => $config->environment, 'taxId' => $config->taxId, 'layers' => $layers];

        $certificate = self::checkCertificate($config, $io->environment->now());
        $report['layers'][] = $certificate;
        if (! $certificate['ok']) {
            return ['report' => $report];
        }

        $cache = ($flags['noCache'] ?? false) === true ? null : TicketCache::create($io->environment->cacheDir(), $config->privateKeyPem);
        $wsaa = self::checkWsaa($io, $config, $cache);
        $report['layers'][] = $wsaa['layer'];
        if (! ($wsaa['layer']['ok'] && isset($wsaa['credentials']))) {
            return ['report' => $report];
        }

        // WSFE reuses the ticket layer 3 obtained: a second login would
        // collide with it as coe.alreadyAuthenticated.
        $client = $io->environment->createClient(
            $config->withWsaaSessionStore($cache ?? new EphemeralSessionStore($wsaa['credentials']))
        );
        $wsfe = self::checkWsfe($client);
        $report['layers'][] = $wsfe['layer'];
        if (! $wsfe['layer']['ok']) {
            return ['report' => $report, 'client' => $client];
        }

        $report['salesPoints'] = array_map(self::toSalesPointReport(...), $wsfe['salesPoints'] ?? []);
        $layer = self::checkSalesPoints($report['salesPoints'], $config->environment, $flags['salesPoint'] ?? null);
        $report['layers'][] = $layer;
        $report['ok'] = $layer['ok'];
        // Keep the documented key order: ok, environment, taxId, layers, salesPoints.
        $salesPoints = $report['salesPoints'];
        unset($report['salesPoints']);
        $report['salesPoints'] = $salesPoints;

        return ['report' => $report, 'client' => $client];
    }

    /** Renders the report the way a shell would: one line per fact. */
    public static function writeCheckReport(Writer $writer, array $report): void
    {
        foreach ($report['layers'] as $layer) {
            $label = self::LAYER_LABELS[$layer['name']];
            if ($layer['ok']) {
                $writer->ok($label, $layer['detail'] ?? null);
            } else {
                $writer->fail($label);
                if (isset($layer['diagnosis'])) {
                    $writer->note($layer['diagnosis']);
                }
                if (isset($layer['fix'])) {
                    $writer->note($layer['fix']);
                }
            }
            if ($layer['name'] === 'salesPoints') {
                foreach ($report['salesPoints'] ?? [] as $point) {
                    $writer->note(self::describeSalesPoint($point));
                }
            }
            if (isset($layer['warning'])) {
                $writer->warn($label, $layer['warning']);
            }
        }
    }

    /**
     * The environment `check` would use, found without touching ARCA: the
     * flag, the config, or the name of the pair sitting in the directory.
     * `issue` asks first, because it refuses to write anywhere but
     * homologación. Null means nothing said so yet; the layers will name
     * whatever is wrong.
     */
    public static function resolveCheckEnvironment(CliIo $io, array $flags): ?string
    {
        $chosen = self::chosenEnvironment($io, $flags);
        if ($chosen !== null) {
            return $chosen;
        }
        try {
            $discovery = self::resolveCredentials($io, $flags)['discovery'];

            return $discovery['kind'] === 'found' ? $discovery['credentials']['environment'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function chosenEnvironment(CliIo $io, array $flags): ?string
    {
        $flag = strtolower(trim((string) ($flags['env'] ?? '')));
        if ($flag !== '') {
            return $flag;
        }
        $configured = trim((string) ($io->config['environment'] ?? ''));

        return $configured !== '' ? $configured : null;
    }

    /** @return array{certificatePem: ?string, privateKeyPem: ?string, discovery: array} */
    private static function resolveCredentials(CliIo $io, array $flags, ?string $environment = null): array
    {
        $certificatePem = self::readPem($io, $flags['cert'] ?? null, $io->config['certificatePem'] ?? null, $io->config['certificatePath'] ?? null);
        $privateKeyPem = self::readPem($io, $flags['key'] ?? null, $io->config['privateKeyPem'] ?? null, $io->config['privateKeyPath'] ?? null);
        $discovery = $certificatePem !== null && $privateKeyPem !== null
            ? ['kind' => 'none']
            : Discover::discoverCredentials($io->directory($flags['dir'] ?? null), $environment, [
                'certificatePem' => $certificatePem,
                'privateKeyPem' => $privateKeyPem,
            ]);

        return ['certificatePem' => $certificatePem, 'privateKeyPem' => $privateKeyPem, 'discovery' => $discovery];
    }

    /** @return array{layer: array, config?: ArcaConfig} */
    private static function resolveConfigLayer(CliIo $io, array $flags): array
    {
        $chosenEnvironment = self::chosenEnvironment($io, $flags);

        try {
            ['certificatePem' => $certificatePem, 'privateKeyPem' => $privateKeyPem, 'discovery' => $discovery]
                = self::resolveCredentials($io, $flags, Discover::toEnvironment($chosenEnvironment));
        } catch (Throwable $error) {
            $unknown = Diagnose::describeUnknownError($error);

            return ['layer' => self::failedLayer('config', ['diagnosis' => $unknown['diagnosis']], $unknown['code'] ?? null)];
        }

        $failure = self::describeDiscoveryFailure($discovery);
        if ($failure !== null) {
            return ['layer' => self::failedLayer('config', $failure)];
        }

        $found = $discovery['kind'] === 'found' ? $discovery['credentials'] : null;
        $certificate = $certificatePem ?? $found['certificatePem'] ?? null;
        $privateKey = $privateKeyPem ?? $found['privateKeyPem'] ?? null;

        $taxId = self::resolveTaxId($io, $flags, $certificate);
        if (isset($taxId['layer'])) {
            return ['layer' => $taxId['layer']];
        }

        $environment = $chosenEnvironment ?? $found['environment'] ?? null;
        if ($environment === null || $environment === '') {
            return ['layer' => self::failedLayer('config', Diagnose::diagnose('config.environment'))];
        }
        if ($certificate === null || $certificate === '' || $privateKey === null || $privateKey === '') {
            return ['layer' => self::failedLayer('config', Diagnose::diagnose('config.pem'))];
        }

        try {
            $config = ArcaConfig::fromOptions([
                ...($io->config['options'] ?? []),
                'taxId' => $taxId['taxId'],
                'certificatePem' => $certificate,
                'privateKeyPem' => $privateKey,
                'environment' => $environment,
            ]);

            return [
                'layer' => [
                    'name' => 'config',
                    'ok' => true,
                    'detail' => self::describeSources([
                        ...self::describeCredentialSource($flags, $found, $certificatePem),
                        'taxId' => $taxId['source'],
                        ...self::describeEnvironmentSource($io, $flags, $config->environment),
                    ]),
                ],
                'config' => $config,
            ];
        } catch (Throwable $error) {
            return ['layer' => self::failedLayer('config', self::toConfigDiagnosis($error))];
        }
    }

    /**
     * The CUIT, in order: the flag, the config, and last the `serialNumber`
     * ARCA wrote in the certificate. Without any of the three there is
     * nothing to ask ARCA about.
     *
     * @return array{taxId: string, source: string}|array{layer: array}
     */
    private static function resolveTaxId(CliIo $io, array $flags, ?string $certificatePem): array
    {
        $flag = trim((string) ($flags['taxId'] ?? ''));
        $given = $flag !== '' ? $flag : trim((string) ($io->config['taxId'] ?? ''));
        if ($given !== '') {
            $problem = Cuit::describeTaxIdProblem($given);
            if ($problem !== null) {
                return ['layer' => self::failedLayer('config', ['diagnosis' => $problem, 'fix' => Cuit::TAX_ID_FIX])];
            }

            return ['taxId' => Cuit::normalizeTaxId($given), 'source' => $flag !== '' ? '--cuit' : 'ARCA_TAX_ID'];
        }
        if ($certificatePem === null) {
            return ['layer' => self::failedLayer('config', Diagnose::diagnose('config.taxId'))];
        }

        $fromCertificate = self::readCertificateTaxId($certificatePem);
        if ($fromCertificate === null) {
            return ['layer' => self::failedLayer('config', Diagnose::diagnose('config.taxIdUnknown'))];
        }

        return ['taxId' => $fromCertificate, 'source' => "CUIT {$fromCertificate} del certificado"];
    }

    /** The CUIT in the certificate, or nothing when the PEM does not even parse. */
    private static function readCertificateTaxId(string $certificatePem): ?string
    {
        try {
            return Csr::readCertificateFacts($certificatePem)['taxId'] ?? null;
        } catch (Throwable) {
            // Layer 2 is where a PEM that does not parse gets its own row.
            return null;
        }
    }

    private static function describeDiscoveryFailure(array $discovery): ?array
    {
        if ($discovery['kind'] === 'ambiguous') {
            return Diagnose::diagnose('config.files.ambiguous', ['files' => implode(' y ', $discovery['files'])]);
        }
        if ($discovery['kind'] === 'incomplete') {
            return Diagnose::diagnose(
                $discovery['missingKind'] === 'certificate' ? 'config.files.missingCertificate' : 'config.files.missingKey',
                ['file' => $discovery['present'], 'missingFile' => $discovery['missing']]
            );
        }

        return null;
    }

    /**
     * A flag names a file and wins; otherwise the PEM in the config, and
     * last the file the config points at.
     */
    private static function readPem(CliIo $io, ?string $file, ?string $pem, ?string $path): ?string
    {
        if ($file !== null && $file !== '') {
            return trim(Discover::readFile(CliIo::resolve($io->cwd, $file)));
        }
        if ($pem !== null && trim($pem) !== '') {
            // .env files often carry PEMs with literal "\n".
            $pem = str_contains($pem, '\n') && ! str_contains($pem, "\n") ? str_replace('\n', "\n", $pem) : $pem;

            return trim($pem);
        }
        if ($path !== null && trim($path) !== '') {
            $contents = trim(Discover::readFile(CliIo::resolve($io->cwd, trim($path))));

            return $contents === '' ? null : $contents;
        }

        return null;
    }

    /**
     * Only a file is worth naming. PEMs that came from the config are the
     * default and stay unsaid.
     */
    private static function describeCredentialSource(array $flags, ?array $found, ?string $suppliedCertificate): array
    {
        $named = [];
        if (trim((string) ($flags['cert'] ?? '')) !== '') {
            $named[] = '--cert';
        }
        if (trim((string) ($flags['key'] ?? '')) !== '') {
            $named[] = '--key';
        }
        if ($found !== null) {
            $file = $suppliedCertificate !== null ? $found['keyFile'] : $found['certificateFile'];
            $named[] = "{$file} en este directorio";
        }

        return $named === [] ? [] : ['credentials' => implode(' y ', $named)];
    }

    /** The file name already says the environment, so nothing is added for it. */
    private static function describeEnvironmentSource(CliIo $io, array $flags, string $environment): array
    {
        if (trim((string) ($flags['env'] ?? '')) !== '') {
            return ['environment' => "--arca-env={$environment}"];
        }
        if (trim((string) ($io->config['environment'] ?? '')) !== '') {
            return ['environment' => "ARCA_ENVIRONMENT={$environment}"];
        }

        return [];
    }

    private static function describeSources(array $origin): string
    {
        return implode(', ', array_values(array_filter(
            [$origin['credentials'] ?? null, $origin['taxId'] ?? null, $origin['environment'] ?? null],
            fn ($part) => $part !== null
        )));
    }

    private static function toConfigDiagnosis(Throwable $error): array
    {
        if (! $error instanceof ArcaConfigurationError) {
            return ['diagnosis' => Diagnose::describeUnknownError($error)['diagnosis']];
        }
        $message = $error->getMessage();
        if (str_contains($message, 'taxId')) {
            return Diagnose::diagnose('config.taxId');
        }
        if (str_contains($message, 'environment')) {
            return Diagnose::diagnose('config.environment');
        }
        if (str_contains($message, 'certificatePem') || str_contains($message, 'privateKeyPem') || str_contains($message, 'Encrypted private keys')) {
            return Diagnose::diagnose('cert.invalid');
        }

        return ['diagnosis' => $message];
    }

    private static function checkCertificate(ArcaConfig $config, DateTimeImmutable $now): array
    {
        try {
            $facts = Csr::readCertificateFacts($config->certificatePem);
            $matches = Csr::privateKeyMatchesCertificate($config->certificatePem, $config->privateKeyPem);
        } catch (Throwable) {
            return self::failedLayer('certificate', Diagnose::diagnose('cert.invalid'));
        }

        // A certificate for another CUIT is a misconfiguration, not something
        // ARCA forgives: WSAA answers for whoever the certificate belongs to.
        if (isset($facts['taxId']) && $facts['taxId'] !== $config->taxId) {
            return self::failedLayer('certificate', Diagnose::diagnose('cert.taxIdMismatch', [
                'certificateTaxId' => $facts['taxId'],
                'taxId' => $config->taxId,
            ]));
        }

        $expiresAt = self::toIsoDate($facts['notAfter']);
        $remaining = $facts['notAfter']->getTimestamp() - (float) $now->format('U.u');
        if ($remaining <= 0) {
            return self::failedLayer('certificate', Diagnose::diagnose('cert.expired', ['date' => $expiresAt]));
        }
        if (! $matches) {
            return self::failedLayer('certificate', Diagnose::diagnose('cert.mismatch'));
        }

        $daysLeft = (int) floor($remaining / self::SECONDS_PER_DAY);
        $layer = ['name' => 'certificate', 'ok' => true, 'detail' => "coinciden, vence {$expiresAt}", 'expiresAt' => $expiresAt];
        if ($daysLeft < self::EXPIRY_WARNING_DAYS) {
            $layer['warning'] = "vence en {$daysLeft} ".($daysLeft === 1 ? 'día' : 'días');
        }

        return $layer;
    }

    /** @return array{layer: array, credentials?: array} */
    private static function checkWsaa(CliIo $io, ArcaConfig $config, ?TicketCache $cache): array
    {
        try {
            $auth = $io->environment->createAuth($cache === null ? $config : $config->withWsaaSessionStore($cache));
            $credentials = $auth->login('wsfe', ['forceRefresh' => $cache === null]);

            return [
                'layer' => ['name' => 'wsaa', 'ok' => true, 'detail' => $cache?->wasReused() === true ? 'ticket vigente' : 'ticket obtenido'],
                'credentials' => $credentials,
            ];
        } catch (Throwable $error) {
            return ['layer' => self::toWsaaFailure($error, $config->environment)];
        }
    }

    private static function toWsaaFailure(Throwable $error, string $environment): array
    {
        $fault = Diagnose::findSoapFault($error);
        $key = Diagnose::diagnoseWsaaFaultCode($fault?->faultCode);
        if ($key !== null) {
            return self::failedLayer('wsaa', Diagnose::diagnose($key), $fault?->errorCode());
        }

        $transport = Diagnose::findTransportError($error);
        if ($transport !== null) {
            $host = (string) parse_url(ServiceConfig::WSAA['endpoint'][$environment], PHP_URL_HOST);

            return self::failedLayer('wsaa', Diagnose::diagnose('wsaa.transport', ['host' => $host]), $transport->errorCode());
        }

        $unknown = Diagnose::describeUnknownError($error);

        return self::failedLayer('wsaa', ['diagnosis' => $unknown['diagnosis']], $unknown['code'] ?? null);
    }

    /** @return array{layer: array, salesPoints?: list<array>} */
    private static function checkWsfe(ArcaClient $client): array
    {
        try {
            $status = $client->wsfe->getServerStatus();
            $down = [];
            foreach (['appServer', 'dbServer', 'authServer'] as $name) {
                $value = (string) ($status[$name] ?? '');
                if (strtoupper($value) !== 'OK') {
                    $down[] = "{$name}={$value}";
                }
            }
            if ($down !== []) {
                return ['layer' => self::failedLayer('wsfe', Diagnose::diagnose('wsfe.serviceError', ['message' => implode(', ', $down)]))];
            }

            $salesPoints = $client->wsfe->getSalesPoints();

            return ['layer' => ['name' => 'wsfe', 'ok' => true, 'detail' => 'servidor ok'], 'salesPoints' => $salesPoints];
        } catch (Throwable $error) {
            return ['layer' => self::toWsfeFailure($error)];
        }
    }

    private static function toWsfeFailure(Throwable $error): array
    {
        if ($error instanceof ArcaAuthenticationError) {
            return [
                ...self::failedLayer('wsfe', Diagnose::diagnoseAuthenticationReason($error->reason), $error->errorCode()),
                'reason' => $error->reason,
            ];
        }
        if ($error instanceof ArcaServiceError || $error instanceof ArcaSoapFaultError) {
            return self::failedLayer('wsfe', Diagnose::diagnose('wsfe.serviceError', ['message' => $error->getMessage()]), $error->errorCode());
        }
        $unknown = Diagnose::describeUnknownError($error);

        return self::failedLayer('wsfe', ['diagnosis' => $unknown['diagnosis']], $unknown['code'] ?? null);
    }

    private static function checkSalesPoints(array $points, string $environment, ?int $requested): array
    {
        if ($requested !== null) {
            $found = null;
            foreach ($points as $point) {
                if ($point['number'] === $requested) {
                    $found = $point;
                    break;
                }
            }
            if ($found === null) {
                // Homologación often reports no points at all for ones that work.
                if ($points === [] && $environment === 'test') {
                    return ['name' => 'salesPoints', 'ok' => true, 'detail' => "{$requested} (no informado)", 'warning' => self::EMPTY_IN_TEST_WARNING];
                }

                return self::failedLayer('salesPoints', Diagnose::diagnose('salesPoint.missing', ['salesPoint' => $requested]));
            }
            if ($found['blocked']) {
                return self::failedLayer('salesPoints', Diagnose::diagnose('salesPoint.blocked', ['salesPoint' => $requested]));
            }

            return ['name' => 'salesPoints', 'ok' => true, 'detail' => self::describeSalesPoint($found)];
        }

        if ($points === []) {
            // In homologación ARCA often reports nothing for points that work.
            // In production an empty list is the whole answer: no invoice can
            // be issued.
            if ($environment === 'test') {
                return ['name' => 'salesPoints', 'ok' => true, 'detail' => 'ninguno informado', 'warning' => self::EMPTY_IN_TEST_WARNING];
            }

            return self::failedLayer('salesPoints', Diagnose::diagnose('salesPoint.none'));
        }

        $count = count($points);

        return ['name' => 'salesPoints', 'ok' => true, 'detail' => "{$count} ".($count === 1 ? 'informado' : 'informados')];
    }

    private static function toSalesPointReport(array $point): array
    {
        $report = ['number' => (int) $point['number'], 'blocked' => (bool) $point['blocked']];
        if (isset($point['emissionType'])) {
            $report['system'] = (string) $point['emissionType'];
        }

        return $report;
    }

    private static function describeSalesPoint(array $point): string
    {
        $state = $point['blocked'] ? 'bloqueado' : 'habilitado';

        return isset($point['system'])
            ? "{$point['number']} ({$state}, {$point['system']})"
            : "{$point['number']} ({$state})";
    }

    /** @param  array{diagnosis: string, fix?: string}  $row */
    private static function failedLayer(string $name, array $row, ?string $code = null): array
    {
        $layer = ['name' => $name, 'ok' => false, 'diagnosis' => $row['diagnosis']];
        if (isset($row['fix'])) {
            $layer['fix'] = $row['fix'];
        }
        if ($code !== null) {
            $layer['code'] = $code;
        }

        return $layer;
    }

    private static function toIsoDate(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }
}
