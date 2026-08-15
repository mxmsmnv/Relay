<?php

declare(strict_types=1);

namespace ProcessWire;

/** Versioned JSON transport with ProcessWire session or scoped Bearer authentication. */
final class RelayRestApi extends Wire
{
    private const MAX_BODY_BYTES = 65536;
    private const RATE_SESSION_KEY = 'RelayRestRate';

    public function __construct(private Relay $relay) {}

    public function handle(string $version, string $resource): string
    {
        $this->headers();
        try {
            if (strtolower(trim($version)) !== Relay::REST_API_VERSION) return $this->response(404, null, 'Unsupported API version.');
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $resource = strtolower(trim($resource));
            if (!preg_match('/^[a-z-]{2,32}$/', $resource)) return $this->response(404, null, 'Not found.');
            if ($resource === 'session') return $this->sessionResponse($method);
            [$api, $mode, $key] = $this->authentication();
            $this->rateLimit($method !== 'GET', $mode, $key);
            $body = $method === 'POST' ? $this->jsonBody() : [];
            if ($method === 'POST' && $mode === 'session') $this->validateCsrf($body);
            $result = match ($resource) {
                'capabilities' => $this->read($method, fn() => $api->capabilities()),
                'counts' => $this->read($method, fn() => $api->counts()),
                'jobs' => $this->read($method, fn() => $api->jobs($this->jobFilters())),
                'job' => $this->read($method, fn() => $api->job($this->queryId())),
                'schedule' => $this->write($method, fn() => $api->schedule($body)),
                'reschedule' => $this->write($method, fn() => $api->reschedule($this->bodyId($body), $body)),
                'cancel' => $this->write($method, fn() => $api->cancel($this->bodyId($body))),
                'run' => $this->write($method, fn() => $api->runDue(isset($body['limit']) ? max(1, min(500, (int)$body['limit'])) : null)),
                default => throw new RelayRestException('Not found.', 404),
            };
            return $this->response(200, $result);
        } catch (RelayRestAuthException $e) {
            header('WWW-Authenticate: Bearer realm="Relay API", error="invalid_token"');
            return $this->response(401, null, $e->getMessage());
        } catch (WirePermissionException $e) { return $this->response(403, null, $e->getMessage()); }
        catch (RelayRestException $e) { return $this->response($e->status(), null, $e->getMessage()); }
        catch (Wire404Exception $e) { return $this->response(404, null, $e->getMessage()); }
        catch (\InvalidArgumentException|WireException $e) { return $this->response(400, null, $e->getMessage()); }
        catch (\Throwable $e) {
            if ((int)$this->relay->enable_logging === 1) {
                $this->wire()->log->save('relay', 'REST request failed (' . get_class($e) . ').');
            }
            return $this->response(500, null, 'Relay request failed.');
        }
    }

    private function authentication(): array
    {
        $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($authorization === '') return [$this->relay->api($this->wire()->user, 'rest'), 'session', (string)$this->wire()->session->id];
        if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,128})$/i', $authorization, $match)) throw new RelayRestAuthException('Invalid Bearer token.');
        $stored = trim((string)$this->relay->rest_bearer_token_hash);
        $actorId = (int)$this->relay->rest_bearer_user_id;
        if ($stored === '' || $actorId < 1 || !hash_equals($stored, hash('sha256', $match[1]))) throw new RelayRestAuthException('Invalid Bearer token.');
        $actor = $this->wire()->users->get($actorId);
        $api = $this->relay->api($actor, 'rest');
        if (!$actor->id || !$actor->isLoggedin() || $actor->isUnpublished() || !$api->canRead()) throw new RelayRestAuthException('Bearer token actor is unavailable.');
        return [$api, 'bearer', substr($stored, 0, 24)];
    }

    private function sessionResponse(string $method): string
    {
        $this->allow($method, ['GET']);
        $api = $this->relay->api($this->wire()->user, 'rest');
        $result = ['isLogin' => (bool)$this->wire()->user->isLoggedin(), 'canRead' => $api->canRead(), 'canWrite' => $api->canWrite(), 'canAdmin' => $api->canAdmin()];
        if ($result['canRead']) {
            $token = $this->wire()->session->CSRF->getToken('relay-rest');
            $result['csrf'] = ['name' => $token['name'], 'value' => $token['value'], 'header' => 'X-' . $token['name']];
        }
        return $this->response(200, $result);
    }

    private function jobFilters(): array
    {
        $filters = [];
        foreach (['from','to','page_id','status','action','limit'] as $name) {
            $value = trim((string)$this->wire()->input->get($name));
            if ($value !== '') $filters[$name] = mb_substr($value, 0, 100);
        }
        return $filters;
    }

    private function queryId(): int { return $this->validId((string)$this->wire()->input->get('id')); }
    private function bodyId(array $body): int { return $this->validId((string)($body['id'] ?? '')); }
    private function validId(string $value): int
    {
        if (!preg_match('/^-?[1-9][0-9]*$/', trim($value))) throw new \InvalidArgumentException('A valid job id is required.');
        return (int)$value;
    }

    private function jsonBody(): array
    {
        $type = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($type !== 'application/json') throw new \InvalidArgumentException('Content-Type application/json is required.');
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) throw new \InvalidArgumentException('JSON request body is too large.');
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) throw new \InvalidArgumentException('A JSON object is required.');
        return $data;
    }

    private function validateCsrf(array $body): void
    {
        $token = $this->wire()->session->CSRF->getToken('relay-rest');
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', 'X-' . $token['name']));
        $provided = (string)($body[$token['name']] ?? ($_SERVER[$serverKey] ?? ''));
        if ($provided === '' || !hash_equals((string)$token['value'], $provided)) throw new WirePermissionException('Invalid CSRF token.');
    }

    private function rateLimit(bool $mutation, string $mode, string $key): void
    {
        if ($mode === 'bearer') {
            $cacheKey = 'RelayRestBearer:' . $key . ':' . intdiv(time(), 60) . ':' . ($mutation ? 'w' : 'r');
            $count = (int)$this->wire()->cache->get($cacheKey) + 1;
            $this->wire()->cache->save($cacheKey, $count, 120);
        } else {
            $state = $this->wire()->session->get(self::RATE_SESSION_KEY);
            if (!is_array($state) || (int)($state['started'] ?? 0) < time() - 60) $state = ['started' => time(), 'r' => 0, 'w' => 0];
            $bucket = $mutation ? 'w' : 'r'; $state[$bucket]++; $count = $state[$bucket];
            $this->wire()->session->set(self::RATE_SESSION_KEY, $state);
        }
        if ($count > ($mutation ? 30 : 120)) throw new RelayRestException('Relay API rate limit exceeded.', 429);
    }

    private function read(string $method, callable $callback): mixed { $this->allow($method, ['GET']); return $callback(); }
    private function write(string $method, callable $callback): mixed { $this->allow($method, ['POST']); return $callback(); }
    private function allow(string $method, array $allowed): void
    {
        if (in_array($method, $allowed, true)) return;
        header('Allow: ' . implode(', ', $allowed)); throw new RelayRestException('Method not allowed.', 405);
    }
    private function headers(): void
    {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
    private function response(int $status, mixed $result = null, string $error = ''): string
    {
        http_response_code($status);
        return (string)json_encode($error === '' ? ['ok'=>true,'api_version'=>Relay::REST_API_VERSION,'result'=>$result] : ['ok'=>false,'api_version'=>Relay::REST_API_VERSION,'error'=>$error], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }
}

final class RelayRestAuthException extends \RuntimeException {}
final class RelayRestException extends \RuntimeException
{
    public function __construct(string $message, private int $httpStatus) { parent::__construct($message); }
    public function status(): int { return $this->httpStatus; }
}
