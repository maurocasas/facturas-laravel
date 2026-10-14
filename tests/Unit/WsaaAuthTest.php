<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Vant\Facturas\Errors\ArcaConfigurationError;
use Vant\Facturas\Errors\ArcaLockTimeoutError;
use Vant\Facturas\Errors\ArcaSoapFaultError;
use Vant\Facturas\Errors\ArcaTransportError;
use Vant\Facturas\Internal\Logger;
use Vant\Facturas\Support\AbortController;
use Vant\Facturas\Support\AbortSignal;
use Vant\Facturas\Tests\Support\TestConfig;
use Vant\Facturas\Wsaa\LockingWsaaSessionStore;
use Vant\Facturas\Wsaa\MemoryWsaaSessionStore;
use Vant\Facturas\Wsaa\WsaaAuth;
use Vant\Facturas\Wsaa\WsaaSessionStore;

/*
 * Port of wsaa/index.test.ts. The TS tests mock `postXmlWithMetadata`; here a
 * Guzzle MockHandler answers the real HTTP layer, so `$this->http` holds every
 * loginCms request sent (the TS `mockPostXml.mock.calls`).
 */

function wsaaTestTicketXml(array $overrides = []): string
{
    $expiresAt = $overrides['expiresAt'] ?? '2099-01-01T00:00:00Z';
    $token = $overrides['token'] ?? 'token';
    $sign = $overrides['sign'] ?? 'sign';

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<loginTicketResponse>
  <header>
    <expirationTime>{$expiresAt}</expirationTime>
  </header>
  <credentials>
    <token>{$token}</token>
    <sign>{$sign}</sign>
  </credentials>
</loginTicketResponse>
XML;
}

function wsaaTestSoapResponse(?string $loginCmsReturnXml = null): string
{
    $loginCmsReturn = $loginCmsReturnXml !== null ? "<loginCmsReturn><![CDATA[{$loginCmsReturnXml}]]></loginCmsReturn>" : '';

    return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <loginCmsResponse xmlns="http://wsaa.view.sua.dvadac.desein.afip.gov">
      {$loginCmsReturn}
    </loginCmsResponse>
  </soap:Body>
</soap:Envelope>
XML;
}

function wsaaTestFaultResponse(string $faultCode, string $faultMessage, string $faultDetail = ''): string
{
    return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <soap:Fault>
      <faultcode>{$faultCode}</faultcode>
      <faultstring>{$faultMessage}</faultstring>
      {$faultDetail}
    </soap:Fault>
  </soap:Body>
</soap:Envelope>
XML;
}

/** `createHttpResponse(body)`. */
function wsaaTestHttpResponse(string $body): Response
{
    return new Response(200, ['Content-Type' => 'text/xml; charset=utf-8'], $body);
}

function wsaaTestTicketResponse(array $overrides = []): Response
{
    return wsaaTestHttpResponse(wsaaTestSoapResponse(wsaaTestTicketXml($overrides)));
}

/** A spy WSAA session store: `get` answers from `$gets` (last one repeats) or throws. */
function wsaaTestSpyStore(array $gets, bool $locking = false): WsaaSessionStore
{
    $store = new class($gets) implements LockingWsaaSessionStore
    {
        public int $getCalls = 0;

        public int $lockCalls = 0;

        public array $sets = [];

        public function __construct(private array $gets) {}

        public function get(array $key): ?array
        {
            $this->getCalls++;
            $next = count($this->gets) > 1 ? array_shift($this->gets) : $this->gets[0];
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        public function set(array $key, array $credentials): void
        {
            $this->sets[] = [$key, $credentials];
        }

        public function delete(array $key): void {}

        public function withLock(array $key, callable $fn): mixed
        {
            $this->lockCalls++;

            return $fn();
        }
    };
    if ($locking) {
        return $store;
    }

    // Same spy without withLock: a store with only get/set/delete.
    return new class($store) implements WsaaSessionStore
    {
        public function __construct(public readonly object $inner) {}

        public function get(array $key): ?array
        {
            return $this->inner->get($key);
        }

        public function set(array $key, array $credentials): void
        {
            $this->inner->set($key, $credentials);
        }

        public function delete(array $key): void {}
    };
}

beforeEach(function () {
    $this->http = [];
    $this->mock = new MockHandler;
    $stack = HandlerStack::create($this->mock);
    $stack->push(Middleware::history($this->http));
    $this->client = new Client(['handler' => $stack]);
    // A fresh certificate per test, like createWsaaConfig(): the cache key includes its fingerprint.
    $this->makeAuth = function (array $overrides = [], ?Logger $logger = null): WsaaAuth {
        $pems = TestConfig::pems();

        return new WsaaAuth(TestConfig::make($overrides + [
            'certificatePem' => $pems['certificatePem'],
            'privateKeyPem' => $pems['privateKeyPem'],
            'httpClient' => $this->client,
            'retries' => 0,
        ]), $logger);
    };
});

function wsaaTestCaught(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException('Expected an exception.');
}

describe('createWsaaAuthModule', function () {
    it('requests and parses WSAA credentials', function () {
        $this->mock->append(wsaaTestTicketResponse());
        $auth = ($this->makeAuth)();

        expect($auth->login('wsfe'))->toBe(['token' => 'token', 'sign' => 'sign', 'expiresAt' => '2099-01-01T00:00:00Z']);

        $request = $this->http[0]['request'];
        expect((string) $request->getUri())->toBe('https://wsaahomo.afip.gov.ar/ws/services/LoginCms')
            ->and($request->getHeaderLine('Content-Type'))->toBe('text/xml; charset="utf-8"')
            ->and($request->getHeader('SOAPAction'))->toBe(['""'])
            ->and((string) $request->getBody())->toContain('<loginCms ')
            ->and((string) $request->getBody())->toContain('<in0>');
    });

    it('reuses cached credentials for a later login (sequential form of in-flight deduplication)', function () {
        // The TS test also starts two logins before the first answers and checks
        // they share one request. Synchronous PHP never has two logins in flight,
        // so only the sequential half applies: the second login hits the cache.
        $this->mock->append(wsaaTestTicketResponse());
        $auth = ($this->makeAuth)();

        $first = $auth->login('wsfe');
        $second = $auth->login('wsfe');

        expect($second)->toBe($first)
            ->and($auth->login('wsfe'))->toBe($first)
            ->and($this->http)->toHaveCount(1);
    });

    it('runs a forced refresh after ordinary work and serves its ticket to later callers', function () {
        // Sequential form of "queues and deduplicates forced refreshes behind
        // ordinary work": concurrent forced callers cannot overlap in PHP, so
        // each forced login is its own request.
        $this->mock->append(
            wsaaTestTicketResponse(['token' => 'ordinary-token']),
            wsaaTestTicketResponse(['token' => 'forced-token']),
        );
        $auth = ($this->makeAuth)();

        expect($auth->login('wsfe')['token'])->toBe('ordinary-token')
            ->and($auth->login('wsfe')['token'])->toBe('ordinary-token')
            ->and($this->http)->toHaveCount(1)
            ->and($auth->login('wsfe', ['forceRefresh' => true])['token'])->toBe('forced-token')
            ->and($this->http)->toHaveCount(2)
            ->and($auth->login('wsfe')['token'])->toBe('forced-token')
            ->and($this->http)->toHaveCount(2);
    });

    it('lets ordinary callers reuse the ticket of a finished forced refresh', function () {
        // Sequential form of "lets ordinary and forced callers join active forced work".
        $this->mock->append(wsaaTestTicketResponse(['token' => 'forced-token']));
        $auth = ($this->makeAuth)();

        expect($auth->login('wsmtxca', ['forceRefresh' => true])['token'])->toBe('forced-token')
            ->and($auth->login('wsmtxca')['token'])->toBe('forced-token')
            ->and($this->http)->toHaveCount(1);
    });

    it('runs a forced refresh after ordinary work rejects', function () {
        $this->mock->append(
            fn () => Create::rejectionFor(new RuntimeException('ordinary WSAA failure')),
            wsaaTestTicketResponse(['token' => 'recovered-token']),
        );
        $auth = ($this->makeAuth)();

        expect(wsaaTestCaught(fn () => $auth->login('wsfe'))->getMessage())->toBe('ordinary WSAA failure')
            ->and($auth->login('wsfe', ['forceRefresh' => true])['token'])->toBe('recovered-token')
            ->and($this->http)->toHaveCount(2);
    });

    it('forces a refresh when requested and keeps the default cache in memory only', function () {
        $this->mock->append(
            wsaaTestTicketResponse(['token' => 'first']),
            wsaaTestTicketResponse(['token' => 'second']),
            wsaaTestTicketResponse(['token' => 'third']),
        );
        $auth = ($this->makeAuth)();

        expect($auth->login('wsmtxca')['token'])->toBe('first')
            ->and($auth->login('wsmtxca', ['forceRefresh' => true])['token'])->toBe('second')
            ->and($this->http)->toHaveCount(2);

        // A new module (the TS test reloads it) starts with an empty cache.
        $reloadedAuth = ($this->makeAuth)();
        expect($reloadedAuth->login('wsmtxca')['token'])->toBe('third')
            ->and($this->http)->toHaveCount(3);
    });

    it('rejects missing or invalid WSAA login ticket payloads', function () {
        $this->mock->append(wsaaTestHttpResponse(wsaaTestSoapResponse()));
        $error = wsaaTestCaught(fn () => ($this->makeAuth)()->login('wsfe'));
        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($error->getMessage())->toBe('WSAA response did not include loginCmsReturn XML');

        $this->mock->append(wsaaTestHttpResponse(wsaaTestSoapResponse('<?xml version="1.0" encoding="UTF-8"?><loginTicketResponse><header /></loginTicketResponse>')));
        $error = wsaaTestCaught(fn () => ($this->makeAuth)()->login('wsfe'));
        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($error->getMessage())->toBe('Invalid WSAA login ticket response structure');
    });

    it('hydrates process memory from a durable store hit and avoids WSAA', function () {
        $credentials = ['token' => 'stored-token', 'sign' => 'stored-sign', 'expiresAt' => '2099-01-01T00:00:00Z'];
        $store = wsaaTestSpyStore([$credentials]);
        $auth = ($this->makeAuth)(['wsaaSessionStore' => $store]);

        expect($auth->login('wsfe'))->toBe($credentials)
            ->and($auth->login('wsfe'))->toBe($credentials)
            ->and($this->http)->toHaveCount(0)
            ->and($store->inner->getCalls)->toBe(1);
    });

    it('re-checks the durable store inside the lock before calling WSAA', function () {
        $credentials = ['token' => 'locked-token', 'sign' => 'locked-sign', 'expiresAt' => '2099-01-01T00:00:00Z'];
        $store = wsaaTestSpyStore([null, $credentials], locking: true);
        $auth = ($this->makeAuth)(['wsaaSessionStore' => $store]);

        expect($auth->login('wsfe'))->toBe($credentials)
            ->and($this->http)->toHaveCount(0)
            ->and($store->lockCalls)->toBe(1)
            ->and($store->getCalls)->toBe(2);
    });

    it('recovers coe.alreadyAuthenticated from a durable store hit', function () {
        $credentials = ['token' => 'recovered-token', 'sign' => 'recovered-sign', 'expiresAt' => '2099-01-01T00:00:00Z'];
        $store = wsaaTestSpyStore([null, null, $credentials]);
        $this->mock->append(wsaaTestHttpResponse(wsaaTestFaultResponse('ns1:coe.alreadyAuthenticated', 'El CEE ya posee un TA valido')));
        $auth = ($this->makeAuth)(['wsaaSessionStore' => $store]);

        expect($auth->login('wsfe'))->toBe($credentials)
            ->and($this->http)->toHaveCount(1);
    });

    it('throws an actionable error for coe.alreadyAuthenticated without a durable store', function () {
        $this->mock->append(wsaaTestHttpResponse(wsaaTestFaultResponse('ns1:coe.alreadyAuthenticated', 'El CEE ya posee un TA valido')));
        $auth = ($this->makeAuth)();

        $error = wsaaTestCaught(fn () => $auth->login('wsfe'));
        expect($error)->toBeInstanceOf(ArcaConfigurationError::class)
            ->and($error->name())->toBe('ArcaConfigurationError')
            ->and($error->getMessage())->toContain('durable wsaaSessionStore')
            ->and($error->getPrevious())->toBeInstanceOf(ArcaSoapFaultError::class);
    });

    it('logs WSAA faults without the Error instance or parsed fault detail', function () {
        $log = [];
        $logger = new Logger(['level' => 'error', 'log' => function (string $level, string $message, mixed ...$args) use (&$log) {
            $log[] = [$level, $message, ...$args];
        }]);
        $this->mock->append(wsaaTestHttpResponse(wsaaTestFaultResponse('soap:Server', 'Rejected', '<detail><Token>token-value</Token><Sign>sign-value</Sign></detail>')));
        $auth = ($this->makeAuth)([], $logger);

        $error = wsaaTestCaught(fn () => $auth->login('wsfe'));

        expect($error)->toBeInstanceOf(ArcaSoapFaultError::class)
            ->and($error->getCode())->toBe('ARCA_SOAP_FAULT')
            ->and($error->faultCode)->toBe('soap:Server')
            ->and(property_exists($error, 'detail'))->toBeFalse()
            ->and(json_encode(get_object_vars($error)))->not->toMatch('/token-value|sign-value/');
        expect($log[0][0])->toBe('error')
            ->and($log[0][1])->toBe('WSAA SOAP fault response')
            ->and($log[0][2])->toMatchArray([
                'service' => 'wsfe',
                'operation' => 'loginCms',
                'errorName' => 'ArcaSoapFaultError',
                'errorCode' => 'ARCA_SOAP_FAULT',
                'faultCode' => 'soap:Server',
            ])
            ->and($log[0][2])->not->toHaveKey('error')
            ->and(json_encode($log[0][2]))->not->toMatch('/token-value|sign-value/');
    });

    it('ignores expired durable credentials and replaces them after WSAA succeeds', function () {
        $store = wsaaTestSpyStore([['token' => 'expired', 'sign' => 'expired', 'expiresAt' => '2000-01-01T00:00:00Z']]);
        $this->mock->append(wsaaTestTicketResponse(['token' => 'fresh-token']));
        $auth = ($this->makeAuth)(['wsaaSessionStore' => $store]);

        expect($auth->login('wsfe')['token'])->toBe('fresh-token');
        [$key, $credentials] = $store->inner->sets[0];
        expect($key)->toMatchArray(['service' => 'wsfe', 'environment' => 'test'])
            ->and($credentials)->toMatchArray(['token' => 'fresh-token']);
    });

    it('surfaces store failures with useful context', function () {
        $store = wsaaTestSpyStore([new RuntimeException('redis unavailable')]);
        $auth = ($this->makeAuth)(['wsaaSessionStore' => $store]);

        $error = wsaaTestCaught(fn () => $auth->login('wsfe'));
        expect($error)->toBeInstanceOf(ArcaConfigurationError::class)
            ->and($error->getMessage())->toBe('WSAA session store get failed for service wsfe')
            ->and($error->getPrevious()?->getMessage())->toBe('redis unavailable');
    });

    it('surfaces store write and lock failures with useful context', function () {
        $failingSet = new class implements WsaaSessionStore
        {
            public function get(array $key): ?array
            {
                return null;
            }

            public function set(array $key, array $credentials): void
            {
                throw new RuntimeException('disk full');
            }

            public function delete(array $key): void {}
        };
        $this->mock->append(wsaaTestTicketResponse());
        expect(wsaaTestCaught(fn () => ($this->makeAuth)(['wsaaSessionStore' => $failingSet])->login('wsfe'))->getMessage())
            ->toBe('WSAA session store set failed for service wsfe');

        $failingLock = new class implements LockingWsaaSessionStore
        {
            public ?Throwable $failure = null;

            public function get(array $key): ?array
            {
                return null;
            }

            public function set(array $key, array $credentials): void {}

            public function delete(array $key): void {}

            public function withLock(array $key, callable $fn): mixed
            {
                throw $this->failure;
            }
        };
        $failingLock->failure = new RuntimeException('lock backend down');
        $error = wsaaTestCaught(fn () => ($this->makeAuth)(['wsaaSessionStore' => $failingLock])->login('wsfe'));
        expect($error)->toBeInstanceOf(ArcaConfigurationError::class)
            ->and($error->getMessage())->toBe('WSAA session store lock failed for service wsfe');

        // A lock timeout is already typed and passes through untouched.
        $failingLock->failure = new ArcaLockTimeoutError('ARCA store lock stayed held; no work was attempted.', 'held');
        expect(wsaaTestCaught(fn () => ($this->makeAuth)(['wsaaSessionStore' => $failingLock])->login('wsfe')))->toBe($failingLock->failure);

        // An error from inside the lock is the work's own failure, never wrapped.
        $this->mock->append(fn () => Create::rejectionFor(new RuntimeException('inside the lock')));
        $memory = new MemoryWsaaSessionStore;
        expect(wsaaTestCaught(fn () => ($this->makeAuth)(['wsaaSessionStore' => $memory])->login('wsfe'))->getMessage())->toBe('inside the lock')
            ->and($this->http)->toHaveCount(2); // the set-failure login above sent one too
    });

    it('lets independently created clients share durable credentials', function () {
        $this->mock->append(wsaaTestTicketResponse(['token' => 'shared-token']));
        $store = new MemoryWsaaSessionStore;
        $firstAuth = ($this->makeAuth)(['wsaaSessionStore' => $store]);
        $secondAuth = ($this->makeAuth)(['wsaaSessionStore' => $store]);

        expect($firstAuth->login('wsfe')['token'])->toBe('shared-token')
            ->and($secondAuth->login('wsfe')['token'])->toBe('shared-token')
            ->and($this->http)->toHaveCount(1);
    });

    it('serializes cold starts through a shared store lock', function () {
        // Sequential form: the TS test overlaps two cold starts. Here the first
        // login takes the lock and logs in; the second finds the stored ticket.
        $this->mock->append(wsaaTestTicketResponse(['token' => 'locked-shared-token']));
        $store = wsaaTestSpyStore([null], locking: true);
        $memory = new MemoryWsaaSessionStore;
        $shared = new class($store, $memory) implements LockingWsaaSessionStore
        {
            public function __construct(public readonly object $spy, private readonly MemoryWsaaSessionStore $memory) {}

            public function get(array $key): ?array
            {
                $this->spy->get($key);

                return $this->memory->get($key);
            }

            public function set(array $key, array $credentials): void
            {
                $this->memory->set($key, $credentials);
            }

            public function delete(array $key): void {}

            public function withLock(array $key, callable $fn): mixed
            {
                return $this->spy->withLock($key, $fn);
            }
        };
        $firstAuth = ($this->makeAuth)(['wsaaSessionStore' => $shared]);
        $secondAuth = ($this->makeAuth)(['wsaaSessionStore' => $shared]);

        expect([$firstAuth->login('wsfe')['token'], $secondAuth->login('wsfe')['token']])->toBe(['locked-shared-token', 'locked-shared-token'])
            ->and($this->http)->toHaveCount(1)
            ->and($store->lockCalls)->toBe(1);
    });

    it('stops at once for an already aborted signal without calling WSAA', function () {
        $this->mock->append(wsaaTestTicketResponse());
        $auth = ($this->makeAuth)();

        $error = wsaaTestCaught(fn () => $auth->login('wsfe', ['abortSignal' => AbortSignal::abortedSignal()]));

        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($error->getMessage())->toBe('The ARCA call was aborted')
            ->and($this->http)->toHaveCount(0);
    });

    it('reports an abort that fires while WSAA answers as the caller abort', function () {
        $controller = new AbortController;
        $this->mock->append(function (RequestInterface $request) use ($controller) {
            $controller->abort();

            return Create::rejectionFor(new \GuzzleHttp\Exception\ConnectException('socket destroyed', $request));
        });
        $auth = ($this->makeAuth)(['retries' => 3]);

        $error = wsaaTestCaught(fn () => $auth->login('wsfe', ['abortSignal' => $controller->signal]));

        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($error->getMessage())->toBe('The ARCA call was aborted')
            ->and($this->http)->toHaveCount(1);
    });
});
