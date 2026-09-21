<?php

namespace App\Service;

use App\Entity\Device;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Client HTTP pour parler à un device ISAPI (Hikvision) en Digest Auth (RFC 2617).
 * Symfony HttpClient ne fait pas de digest auto -> on gère le handshake nous-mêmes:
 *   1. requête sans auth -> 401 + header WWW-Authenticate avec le challenge
 *   2. on recalcule la réponse digest et on rejoue la requête avec Authorization
 */
class HikvisionDigestClient
{
    private int $nonceCount = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Device $device,
    ) {
    }

    public function get(string $path, array $query = []): array
    {
        $query = array_merge(['format' => 'json'], $query);
        return $this->request('GET', $path, ['query' => $query]);
    }

    public function put(string $path, array $jsonBody): array
    {
        return $this->request('PUT', $path . (str_contains($path, '?') ? '&' : '?') . 'format=json', [
            'json' => $jsonBody,
        ]);
    }

    public function post(string $path, array $jsonBody): array
    {
        return $this->request('POST', $path . (str_contains($path, '?') ? '&' : '?') . 'format=json', [
            'json' => $jsonBody,
        ]);
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $url = $this->device->getBaseUrl() . '/ISAPI' . $path;

        return $this->decode($this->requestUrl($method, $url, $options));
    }

    /**
     * Récupère une ressource binaire (image) via une URL absolue déjà
     * fournie par la pointeuse (ex. UserInfo.faceURL) — ces URLs ne sont
     * pas sous /ISAPI, contrairement aux autres appels de ce client.
     */
    public function fetchBinary(string $absoluteUrl): string
    {
        return $this->requestUrl('GET', $absoluteUrl)->getContent();
    }

    private function requestUrl(string $method, string $url, array $options = []): ResponseInterface
    {
        // 1ère requête, sans auth, pour récupérer le challenge digest
        $first = $this->httpClient->request($method, $url, $options);

        if ($first->getStatusCode() !== 401) {
            return $first;
        }

        $authHeader = $first->getHeaders(false)['www-authenticate'][0] ?? null;
        if (! $authHeader || ! str_starts_with($authHeader, 'Digest')) {
            throw new \RuntimeException("Réponse 401 sans challenge Digest exploitable pour {$url}");
        }

        $challenge = $this->parseDigestHeader($authHeader);
        $requestUri = parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) ? '?' . parse_url($url, PHP_URL_QUERY) : '');
        $authorization = $this->buildAuthorizationHeader($method, $requestUri, $challenge);

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => $authorization,
        ]);

        return $this->httpClient->request($method, $url, $options);
    }

    private function decode(ResponseInterface $response): array
    {
        $content = $response->getContent(false);
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function parseDigestHeader(string $header): array
    {
        $header = trim(substr($header, strlen('Digest')));
        preg_match_all('/(\w+)="?([^",]+)"?/', $header, $matches, PREG_SET_ORDER);

        $parsed = [];
        foreach ($matches as $m) {
            $parsed[$m[1]] = trim($m[2], '"');
        }

        return $parsed;
    }

    private function buildAuthorizationHeader(string $method, string $path, array $challenge): string
    {
        $this->nonceCount++;
        $nc = sprintf('%08x', $this->nonceCount);
        $cnonce = bin2hex(random_bytes(8));

        $realm = $challenge['realm'] ?? '';
        $nonce = $challenge['nonce'] ?? '';
        $qop = $challenge['qop'] ?? 'auth';
        $opaque = $challenge['opaque'] ?? null;

        $user = $this->device->getAdminUser();
        $pass = $this->device->getAdminPassword();
        $uri = $path;

        $ha1 = md5("{$user}:{$realm}:{$pass}");
        $ha2 = md5("{$method}:{$uri}");
        $response = md5("{$ha1}:{$nonce}:{$nc}:{$cnonce}:{$qop}:{$ha2}");

        $parts = [
            'username' => $user,
            'realm' => $realm,
            'nonce' => $nonce,
            'uri' => $uri,
            'qop' => $qop,
            'nc' => $nc,
            'cnonce' => $cnonce,
            'response' => $response,
        ];
        if ($opaque) {
            $parts['opaque'] = $opaque;
        }

        $serialized = implode(', ', array_map(
            fn ($k, $v) => $k === 'nc' ? "{$k}={$v}" : "{$k}=\"{$v}\"",
            array_keys($parts),
            $parts
        ));

        return 'Digest ' . $serialized;
    }

    public function deviceInfo(): array
    {
        return $this->get('/System/deviceInfo');
    }

    public function registerWebhook(string $webhookUrl): array
    {
        return $this->put('/Event/notification/httpHosts', [
            'HttpHostNotificationList' => [[
                'id' => '1',
                'url' => $webhookUrl,
                'protocolType' => 'HTTP',
                'parameterFormatType' => 'JSON',
                'addressingFormatType' => 'ipaddress',
                'httpAuthenticationMethod' => 'none',
            ]],
        ]);
    }

    /**
     * Pagine sur /AccessControl/AcsEvent pour rapatrier l'historique de pointages
     * déjà stocké sur le device (avant l'enregistrement du webhook, ou en cas de
     * coupure réseau). $from/$to au format ISO 8601, ex. '2026-08-01T00:00:00+01:00'.
     *
     * @return array<int, array> payloads bruts, un par événement (mêmes clés que
     *         le payload webhook — voir AttendanceEventMapper)
     */
    public function searchEvents(\DateTimeImmutable $from, \DateTimeImmutable $to, int $maxResults = 30): array
    {
        $all = [];
        $position = 0;

        do {
            $response = $this->post('/AccessControl/AcsEvent', [
                'AcsEventCond' => [
                    'searchID' => (string) time(),
                    'searchResultPosition' => $position,
                    'maxResults' => $maxResults,
                    'major' => 0,
                    'minor' => 0,
                    'startTime' => $from->format('Y-m-d\TH:i:sP'),
                    'endTime' => $to->format('Y-m-d\TH:i:sP'),
                ],
            ]);

            $list = $response['AcsEvent']['InfoList'] ?? [];
            $all = array_merge($all, $list);

            $status = $response['AcsEvent']['responseStatusStrg'] ?? 'OK';
            // Avance du nombre de résultats réellement reçus, pas de $maxResults
            // demandé — certains devices plafonnent silencieusement en dessous
            // (voir le même correctif sur listUsers() pour le détail).
            $position += count($list);
        } while ($status === 'MORE' && count($list) > 0);

        return $all;
    }

    /**
     * Pagine sur /AccessControl/UserInfo/Search pour lister tous les employeeNo
     * enregistrés. $maxResults est une limite demandée — certains devices la
     * plafonnent silencieusement en dessous (ex. 30 max même si on demande
     * 100, voir UserInfo/Capabilities.UserInfoSearchCond.maxResults.@max) :
     * on avance donc la position du nombre de résultats réellement reçus,
     * jamais de $maxResults, sous peine de sauter des pages entières.
     */
    public function listUsers(int $maxResults = 100): array
    {
        $all = [];
        $position = 0;

        do {
            $response = $this->post('/AccessControl/UserInfo/Search', [
                'UserInfoSearchCond' => [
                    'searchID' => (string) time(),
                    'searchResultPosition' => $position,
                    'maxResults' => $maxResults,
                ],
            ]);

            $list = $response['UserInfoSearch']['UserInfo'] ?? [];
            $all = array_merge($all, $list);

            $status = $response['UserInfoSearch']['responseStatusStrg'] ?? 'OK';
            $position += count($list);
        } while ($status === 'MORE' && count($list) > 0);

        return $all;
    }
}
