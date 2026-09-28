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

        $response = $this->requestUrl($method, $url, $options);
        $decoded = $this->decode($response);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            // Le device répond une erreur HTTP (400/403/...) — le corps ISAPI
            // contient généralement statusCode/statusString/subStatusCode/errorMsg
            // exploitables pour comprendre pourquoi (ex. payload UserInfo rejeté).
            // Sans ça l'appelant ne voit qu'un tableau vide silencieux.
            $reason = $decoded['errorMsg'] ?? $decoded['statusString'] ?? $decoded['subStatusCode'] ?? $response->getContent(false);
            throw new \RuntimeException("{$method} {$path} a échoué (HTTP {$status}) : {$reason}");
        }

        return $decoded;
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
     * Pousse un planning hebdomadaire (Access Schedule Template / Week Plan)
     * vers le terminal — format ISAPI standard AccessControl
     * UserRightWeekPlanCfg, $planNo identifie le plan sur le device (1 par
     * défaut, un seul plan utilisé par ce projet). $weekPlanCfg est construit
     * par WeekPlanSyncService::buildPayload(). Noms de clés JSON à valider
     * contre un device réel — divergent parfois selon le firmware, comme le
     * reste du mapping ISAPI de ce projet (voir AttendanceEventMapper).
     */
    public function setWeekPlan(int $planNo, array $weekPlanCfg): array
    {
        return $this->put("/AccessControl/UserRightWeekPlanCfg/{$planNo}", $weekPlanCfg);
    }

    /** Lit le planning hebdomadaire actuellement en place sur le device pour le plan $planNo. */
    public function getWeekPlan(int $planNo): array
    {
        return $this->get("/AccessControl/UserRightWeekPlanCfg/{$planNo}");
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
     * Crée un utilisateur côté device (sans biométrie — l'empreinte
     * faciale reste à prendre physiquement sur le terminal après coup).
     * Format `AccessControl/UserInfo/Record`, validé en POST sur un device
     * réel de ce déploiement (DS-K1T341CMF) : `PUT` renvoie 400
     * `methodNotAllowed` sur ce firmware — seul `POST` est accepté pour la
     * création, contrairement à ce que suggère `Capabilities.supportFunction`
     * (`"post,delete,put,get,setUp"`, `put` sert visiblement à autre chose
     * sur `UserInfo`, pas à l'écriture d'un enregistrement).
     */
    public function addUser(string $employeeNo, string $name): array
    {
        return $this->post('/AccessControl/UserInfo/Record', $this->userInfoPayload($employeeNo, $name));
    }

    /**
     * Met à jour le nom d'un utilisateur déjà enregistré côté device
     * (employeeNo inchangé) — endpoint et méthode différents de addUser(),
     * validé sur un device réel de ce déploiement : POST UserInfo/Record
     * (celui de addUser()) rejette un employeeNo déjà existant en 400
     * `employeeNoAlreadyExist` (Record ne sert qu'à créer sur ce firmware,
     * contrairement au comportement générique documenté par le SDK
     * Hikvision où le même appel ferait l'upsert) ; PUT UserInfo/Modify
     * avec ce même employeeNo répond 200 et applique bien le changement.
     * N'affecte ni la biométrie ni les droits déjà enregistrés (RightPlan,
     * numOfCard/FP/Face, faceURL) — confirmé inchangés après un appel réel,
     * seuls les champs envoyés ici sont réécrits.
     */
    public function updateUser(string $employeeNo, string $name): array
    {
        return $this->put('/AccessControl/UserInfo/Modify', $this->userInfoPayload($employeeNo, $name));
    }

    /**
     * beginTime/endTime bornés à la plage acceptée par ce device, lue sur
     * son propre `UserInfo/Capabilities.Valid` (`timeRangeBegin`/`timeRangeEnd`,
     * confirmé "2000-01-01T00:00:00" → "2037-12-31T23:59:59" sur un device
     * réel de ce déploiement) — une valeur hors plage (ex. +20 ans depuis
     * aujourd'hui) est rejetée en 400 `badJsonContent` sur `endTime`. 2037
     * est la borne de l'epoch 32 bits, probable limite matérielle commune à
     * ce type de firmware plutôt qu'une config spécifique à ce device —
     * gardé en dur plutôt que lu dynamiquement via Capabilities à chaque
     * appel (coût d'une requête HTTP en plus pour une constante qui ne
     * bouge pas).
     *
     * Utilisé par addUser() ET updateUser() : sur un updateUser(), ceci
     * réécrit aussi Valid.beginTime à la date du jour même s'il était plus
     * ancien avant (ex. date d'enrôlement d'origine) — testé sur un device
     * réel, sans effet fonctionnel puisque endTime reste 2037 dans les deux
     * cas, mais à savoir si Valid.beginTime est un jour affiché/exploité
     * ailleurs. RightPlan/doorRight/numOfCard/FP/Face/faceURL ne sont pas
     * dans ce payload et restent inchangés côté device (confirmé) — seuls
     * name et Valid sont réécrits.
     */
    private function userInfoPayload(string $employeeNo, string $name): array
    {
        return [
            'UserInfo' => [
                'employeeNo' => $employeeNo,
                'name' => $name,
                'userType' => 'normal',
                'Valid' => [
                    'enable' => true,
                    'beginTime' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s'),
                    'endTime' => '2037-12-31T23:59:59',
                ],
            ],
        ];
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
