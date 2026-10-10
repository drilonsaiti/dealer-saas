<?php

namespace App\Domain\Integrations\Channels\AutoScout24;

use App\Domain\Integrations\Contracts\ListingChannel;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\ChannelResult;
use App\Domain\Integrations\Support\IntegrationException;
use App\Domain\Listings\Models\Listing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * AutoScout24 Switzerland, DMS API: OAuth2 client credentials of the dealer (client id,
 * secret, seller number), the vehicles of that seller via REST.
 *
 * Addresses come from config/integrations.php; the field names live in AutoScout24Mapper.
 * Both must be checked against the API documentation before the first live use.
 */
class AutoScout24Channel implements ListingChannel
{
    public function __construct(private readonly AutoScout24Mapper $mapper) {}

    public function key(): string
    {
        return IntegrationAccount::AUTOSCOUT24;
    }

    public function test(IntegrationAccount $account): void
    {
        Cache::forget($this->tokenKey($account));
        $this->send($account, 'get', 'listings', query: ['page' => 1, 'size' => 1]);
    }

    public function payload(IntegrationAccount $account, Listing $listing): array
    {
        return $this->mapper->toPortal($account, $listing);
    }

    public function publish(IntegrationAccount $account, array $payload): ChannelResult
    {
        return $this->result($this->send($account, 'post', 'listings', body: $payload));
    }

    public function update(IntegrationAccount $account, string $externalId, array $payload): ChannelResult
    {
        $response = $this->send($account, 'put', 'listing', ['id' => $externalId], body: $payload);

        return $response->json('id') !== null ? $this->result($response) : new ChannelResult($externalId, self::nullableString($response->json('url')));
    }

    public function remove(IntegrationAccount $account, string $externalId): void
    {
        try {
            $this->send($account, 'delete', 'listing', ['id' => $externalId]);
        } catch (IntegrationException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    public function stock(IntegrationAccount $account): iterable
    {
        $size = (int) config('integrations.autoscout24.page_size', 100);

        for ($page = 1; $page <= 100; $page++) {
            $response = $this->send($account, 'get', 'listings', query: ['page' => $page, 'size' => $size]);
            $items = $response->json('items') ?? $response->json('data') ?? $response->json('listings') ?? [];

            foreach ((array) $items as $item) {
                if (is_array($item) && ($vehicle = $this->mapper->fromPortal($item)) !== null) {
                    yield $vehicle;
                }
            }

            $pages = $response->json('totalPages') ?? $response->json('meta.totalPages');

            if (count((array) $items) < $size || (is_numeric($pages) && $page >= (int) $pages)) {
                return;
            }
        }
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function send(IntegrationAccount $account, string $method, string $path, array $parameters = [], array $query = [], ?array $body = null, bool $retried = false): Response
    {
        $url = rtrim((string) config('integrations.autoscout24.base_url'), '/').$this->path($account, $path, $parameters);

        try {
            $request = $this->client($account)->withToken($this->token($account));
            $response = match ($method) {
                'get' => $request->get($url, $query),
                'post' => $request->post($url, $body ?? []),
                'put' => $request->put($url, $body ?? []),
                default => $request->delete($url),
            };
        } catch (ConnectionException $e) {
            throw new IntegrationException(__('AutoScout24 cannot be reached: :reason', ['reason' => $e->getMessage()]));
        }

        if ($response->status() === 401 && ! $retried) {
            // The token may have been revoked before it expired: fetch a new one once.
            Cache::forget($this->tokenKey($account));

            return $this->send($account, $method, $path, $parameters, $query, $body, retried: true);
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->failure($response);
    }

    private function token(IntegrationAccount $account): string
    {
        $cached = Cache::get($this->tokenKey($account));

        if (is_string($cached)) {
            return $cached;
        }

        $clientId = $account->credential('client_id');
        $secret = $account->credential('client_secret');

        if ($clientId === null || $secret === null || $account->credential('seller_id') === null) {
            throw new IntegrationException(__('Enter the client ID, the secret and the customer number from AutoScout24.'), retryable: false);
        }

        try {
            $response = $this->client($account)->asForm()->post((string) config('integrations.autoscout24.token_url'), [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $secret,
            ]);
        } catch (ConnectionException $e) {
            throw new IntegrationException(__('AutoScout24 cannot be reached: :reason', ['reason' => $e->getMessage()]));
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token)) {
            throw new IntegrationException(
                __('AutoScout24 refused the login (:code). Check the client ID and the secret.', ['code' => $response->status()]),
                $response->status(),
                retryable: $response->serverError(),
            );
        }

        $ttl = max(60, (int) ($response->json('expires_in') ?? 3600) - 60);
        Cache::put($this->tokenKey($account), $token, $ttl);

        return $token;
    }

    private function failure(Response $response): IntegrationException
    {
        $status = $response->status();
        $detail = $response->json('message') ?? $response->json('error_description') ?? $response->json('error') ?? $response->json('title');

        if (is_array($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }

        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            $detail = trim(((string) $detail).' '.implode('; ', array_map(
                fn ($e): string => is_array($e) ? trim(((string) ($e['field'] ?? $e['path'] ?? '')).': '.((string) ($e['message'] ?? json_encode($e))), ': ') : (string) $e,
                Arr::isList($errors) ? $errors : array_map(fn ($v, $k) => ['field' => $k, 'message' => is_array($v) ? implode(', ', $v) : $v], $errors, array_keys($errors)),
            )));
        }

        $detail = mb_substr(filled($detail) ? (string) $detail : mb_substr($response->body(), 0, 300), 0, 1000);

        $message = match (true) {
            $status === 401, $status === 403 => __('AutoScout24 refused access (:code). Check the credentials and the customer number.', ['code' => $status]),
            $status === 404 => __('AutoScout24 does not know this vehicle (any more).'),
            $status === 429 => __('AutoScout24 asks to slow down; trying again later.'),
            $status >= 500 => __('AutoScout24 has a problem (:code); trying again later.', ['code' => $status]),
            default => __('AutoScout24 refused the vehicle (:code): :detail', ['code' => $status, 'detail' => $detail]),
        };

        return new IntegrationException($message, $status, retryable: $status === 429 || $status >= 500);
    }

    private function result(Response $response): ChannelResult
    {
        $id = $response->json('id') ?? $response->json('listingId');

        if (! is_scalar($id) || (string) $id === '') {
            throw new IntegrationException(__('AutoScout24 did not return an id for the vehicle.'), $response->status(), retryable: false);
        }

        return new ChannelResult((string) $id, self::nullableString($response->json('url')));
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function path(IntegrationAccount $account, string $key, array $parameters): string
    {
        $replace = ['{seller}' => rawurlencode((string) $account->credential('seller_id'))];

        foreach ($parameters as $name => $value) {
            $replace['{'.$name.'}'] = rawurlencode($value);
        }

        return strtr((string) config("integrations.autoscout24.paths.{$key}"), $replace);
    }

    private function client(IntegrationAccount $account): PendingRequest
    {
        return Http::acceptJson()
            ->timeout((int) config('integrations.autoscout24.timeout', 20))
            ->withUserAgent('DealerSaaS/1 ('.$account->tenant_id.')');
    }

    private function tokenKey(IntegrationAccount $account): string
    {
        return 'integrations:autoscout24:token:'.$account->getKey().':'.hash('sha256', (string) $account->credential('client_id').'|'.(string) $account->credential('client_secret'));
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
