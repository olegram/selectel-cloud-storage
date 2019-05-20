<?php

namespace ArgentCrusade\Selectel\CloudStorage\Api;

use Carbon\Carbon;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use ArgentCrusade\Selectel\CloudStorage\Contracts\Api\ApiClientContract;
use ArgentCrusade\Selectel\CloudStorage\Exceptions\AuthenticationFailedException;

class ApiClient implements ApiClientContract
{
    const AUTH_URL = 'https://auth.selcdn.ru';
    const TOKEN_TTL = 86400;

    const CACHE_KEY = 'selectelCloudStorage.apiClient';

    /**
     * API Username.
     *
     * @var string
     */
    protected $username;

    /**
     * API Password.
     *
     * @var string
     */
    protected $password;

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var Carbon
     */
    protected $authenticatedAt;

    /**
     * Authorization token.
     *
     * @var string
     */
    protected $token;

    /**
     * Storage URL.
     *
     * @var string
     */
    protected $storageUrl;

    /**
     * HTTP Client.
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $httpClient;

    /**
     * Creates new API Client instance.
     *
     * @param string $username
     * @param string $password
     * @param CacheInterface $cache
     */
    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * @param CacheInterface $cache
     * @return $this
     */
    public function setCache(CacheInterface $cache)
    {
        $this->cache = $cache;
        return $this;
    }

    /**
     * Replaces HTTP Client instance.
     *
     * @param \GuzzleHttp\ClientInterface $httpClient
     *
     * @return \ArgentCrusade\Selectel\CloudStorage\Contracts\Api\ApiClientContract
     */
    public function setHttpClient(ClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    /**
     * HTTP Client.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return \GuzzleHttp\ClientInterface|null
     */
    public function getHttpClient()
    {
        if (!is_null($this->httpClient)) {
            return $this->httpClient;
        }

        return $this->httpClient = new Client([
            'base_uri' => $this->storageUrl(),
            'headers' => [
                'X-Auth-Token' => $this->token(),
            ],
        ]);
    }

    /**
     * Authenticated user's token.
     *
     * @return string
     */
    public function token()
    {
        return $this->token;
    }

    /**
     * Storage URL.
     *
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function storageUrl()
    {
        if (isset($this->storageUrl)) {
            return $this->storageUrl;
        }
        
        if (isset($this->cache)) {
            $this->storageUrl = $this->cache->get(static::CACHE_KEY . '.storageUrl');
        }

        if (is_null($this->storageUrl)) {
            $this->authenticate();
        }

        return $this->storageUrl;
    }

    /**
     * Determines if user is authenticated.
     *
     * @return bool
     */
    public function authenticated()
    {
        return ! is_null($this->token())
            && $this->authenticatedAt->diffInSeconds(Carbon::now()) < static::TOKEN_TTL;
    }

    /**
     * Performs authentication request.
     *
     * @throws \ArgentCrusade\Selectel\CloudStorage\Exceptions\AuthenticationFailedException
     * @throws \RuntimeException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function authenticate()
    {
        if ($this->authenticated()) {
            return;
        }

        $response = $this->authenticationResponse();

        if (!$response->hasHeader('X-Auth-Token')) {
            throw new AuthenticationFailedException('Given credentials are wrong.', 403);
        }

        if (!$response->hasHeader('X-Storage-Url')) {
            throw new RuntimeException('Storage URL is missing.', 500);
        }

        $this->token = $response->getHeaderLine('X-Auth-Token');
        $this->authenticatedAt = Carbon::now();
        $this->storageUrl = $response->getHeaderLine('X-Storage-Url');

        if (isset($this->cache)) {
            $this->cache->set(static::CACHE_KEY . '.storageUrl', $this->storageUrl);
        }
    }

    /**
     * Performs authentication request and returns its response.
     *
     * @throws \ArgentCrusade\Selectel\CloudStorage\Exceptions\AuthenticationFailedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function authenticationResponse()
    {
        $client = new Client();

        try {
            $response = $client->request('GET', static::AUTH_URL, [
                'headers' => [
                    'X-Auth-User' => $this->username,
                    'X-Auth-Key' => $this->password,
                ],
            ]);
        } catch (RequestException $e) {
            throw new AuthenticationFailedException('Given credentials are wrong.', 403);
        }

        return $response;
    }

    /**
     * Performs new API request. $params array will be passed to HTTP Client as is.
     *
     * @param string $method
     * @param string $url
     * @param array  $params = []
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function request($method, $url, array $params = [])
    {
        if (!$this->authenticated()) {
            $this->authenticate();
        }

        if (!isset($params['query'])) {
            $params['query'] = [];
        }

        $params['query']['format'] = 'json';

        try {
            $response = $this->getHttpClient()->request($method, $url, $params);
        } catch (RequestException $e) {
            return $e->getResponse();
        }

        return $response;
    }
}
