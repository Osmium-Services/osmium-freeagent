<?php

/**
 * FreeAgent API Service (v2)
 *
 * Handles OAuth2 connection (authorize, token exchange, refresh) and the
 * accounting calls needed to push a paid order to FreeAgent as a sales invoice.
 *
 * API Documentation: https://dev.freeagent.com/docs
 */

namespace Osmium\Services\Freeagent\Models;

use Osmium\Core\Library\OsmiumPDO;

class FreeagentService
{
    private const LIVE_HOST = 'https://api.freeagent.com';
    private const SANDBOX_HOST = 'https://api.sandbox.freeagent.com';
    private const USER_AGENT = 'Osmium FreeAgent service';
    private const TOKEN_REFRESH_MARGIN_SECONDS = 60; // Access tokens live an hour
    private const CONTACTS_PER_PAGE = 100;
    private const CONTACTS_MAX_PAGES = 50; // Stops a lookup running away on a huge contact list

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $host;
    private OsmiumPDO $database;
    private string $tablePrefix;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        bool $sandbox,
        OsmiumPDO $database,
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->host = $sandbox ? self::SANDBOX_HOST : self::LIVE_HOST;
        $this->database = $database;
        $this->tablePrefix = $database->tablePrefix();
    }

    // ----------------------------------------
    // OAuth2 connection flow
    // ----------------------------------------

    /**
     * Build the URL to send the admin to in order to authorize this app.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $query = \http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
        ]);

        return $this->host . '/v2/approve_app?' . $query;
    }

    /**
     * Exchange an authorization code for tokens, read the company's name, and
     * persist the connection. Called from the OAuth callback.
     */
    public function completeAuthorization(string $code): array
    {
        $tokens = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);

        $company = $this->request(method: 'GET', path: 'company', accessToken: $tokens['access_token']);
        $companyName = (string) ($company['company']['name'] ?? '');

        $noName = $companyName === '';
        if ($noName) throw new \Exception('FreeAgent did not return a company name');

        $this->saveConnection(
            companyName: $companyName,
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'],
            expiresIn: (int) $tokens['expires_in'],
        );

        return ['companyName' => $companyName];
    }

    /**
     * The currently connected company, or null if never connected.
     */
    public function getConnection(): ?array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}freeagent_connection ORDER BY id DESC LIMIT 1";
        $this->database->query($sql);

        return $this->database->single() ?: null;
    }

    /**
     * Remove the stored connection. The admin must re-authorize to reconnect.
     */
    public function disconnect(): void
    {
        $sql = "DELETE FROM {$this->tablePrefix}freeagent_connection";
        $this->database->query($sql);
        $this->database->execute();
    }

    /**
     * A valid access token for the connected company, refreshing it first if
     * it's expired or close to it. FreeAgent may issue a new refresh token
     * with the refresh, so whatever comes back is saved.
     *
     * @throws \Exception If FreeAgent has never been connected
     */
    private function ensureValidAccessToken(): string
    {
        $connection = $this->getConnection();
        $notConnected = $connection === null;
        if ($notConnected) throw new \Exception('FreeAgent is not connected');

        $expiresAt = \strtotime($connection['access_token_expires_at']);
        $expiringSoon = $expiresAt - \time() <= self::TOKEN_REFRESH_MARGIN_SECONDS;
        if (!$expiringSoon) return $connection['access_token'];

        $tokens = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection['refresh_token'],
        ]);

        $this->saveConnection(
            companyName: $connection['company_name'],
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'] ?? $connection['refresh_token'],
            expiresIn: (int) $tokens['expires_in'],
        );

        return $tokens['access_token'];
    }

    /**
     * The connection is single-company, so refreshing just replaces the one row.
     */
    private function saveConnection(
        string $companyName,
        string $accessToken,
        string $refreshToken,
        int $expiresIn,
    ): void {
        $expiresAt = \date('Y-m-d H:i:s', \time() + $expiresIn);

        $this->disconnect();

        $sql = "INSERT INTO {$this->tablePrefix}freeagent_connection "
            . "(company_name, access_token, refresh_token, access_token_expires_at) "
            . "VALUES (:company_name, :access_token, :refresh_token, :expires_at)";

        $this->database->query($sql);
        $this->database->bind(param: ':company_name', value: $companyName);
        $this->database->bind(param: ':access_token', value: $accessToken);
        $this->database->bind(param: ':refresh_token', value: $refreshToken);
        $this->database->bind(param: ':expires_at', value: $expiresAt);
        $this->database->execute();
    }

    /**
     * Exchange a code or refresh token for a new access token. FreeAgent
     * takes the client credentials as HTTP Basic auth.
     */
    private function requestToken(array $parameters): array
    {
        $ch = \curl_init($this->host . '/v2/token_endpoint');
        \curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \http_build_query($parameters),
            CURLOPT_USERPWD => $this->clientId . ':' . $this->clientSecret,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'User-Agent: ' . self::USER_AGENT,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = \curl_exec($ch);
        $curlError = \curl_error($ch);

        if ($curlError) throw new \Exception("FreeAgent token request curl error: {$curlError}");

        $decoded = \json_decode(json: (string) $response, associative: true);

        $hasError = !\is_array($decoded) || isset($decoded['error']) || !isset($decoded['access_token']);
        if ($hasError) {
            $description = \is_array($decoded) ? ($decoded['error_description'] ?? $decoded['error'] ?? 'unexpected response') : 'invalid response';
            throw new \Exception("FreeAgent token request failed: {$description}");
        }

        return $decoded;
    }

    // ----------------------------------------
    // Accounting API
    // ----------------------------------------

    /**
     * Income categories, for the settings dropdown.
     *
     * @return array [['url', 'name'], ...]
     */
    public function listIncomeCategories(): array
    {
        $response = $this->callApi(method: 'GET', path: 'categories');

        return \array_map(static fn(array $item): array => [
            'url' => (string) $item['url'],
            'name' => \trim(($item['nominal_code'] ?? '') . ' ' . ($item['description'] ?? '')),
        ], $response['income_categories'] ?? []);
    }

    /**
     * Find a contact by email, creating one if none exists. FreeAgent has no
     * email search on contacts, so this pages through them and compares locally.
     *
     * @return string The FreeAgent contact URL
     */
    public function findOrCreateCustomer(string $name, string $email): string
    {
        $wanted = \mb_strtolower(\trim($email));

        for ($page = 1; $page <= self::CONTACTS_MAX_PAGES; $page++) {
            $response = $this->callApi(
                method: 'GET',
                path: 'contacts?view=all&per_page=' . self::CONTACTS_PER_PAGE . '&page=' . $page,
            );

            $contacts = $response['contacts'] ?? [];
            foreach ($contacts as $contact) {
                $emails = [\mb_strtolower((string) ($contact['email'] ?? '')), \mb_strtolower((string) ($contact['billing_email'] ?? ''))];
                $isMatch = $wanted !== '' && \in_array($wanted, $emails, true);
                if ($isMatch) return (string) $contact['url'];
            }

            $lastPage = \count($contacts) < self::CONTACTS_PER_PAGE;
            if ($lastPage) break;
        }

        $created = $this->callApi(method: 'POST', path: 'contacts', body: [
            'contact' => $this->contactFields($name, $email),
        ]);

        return (string) $created['contact']['url'];
    }

    /**
     * A contact needs an organisation name, or both first and last name.
     * A single word goes in as the organisation name.
     */
    private function contactFields(string $name, string $email): array
    {
        $name = \trim($name) ?: $email;
        $parts = \preg_split('/\s+/', $name, 2);

        $singleWord = \count($parts) < 2;
        if ($singleWord) return ['organisation_name' => \mb_substr($name, 0, 200), 'email' => $email];

        return ['first_name' => \mb_substr($parts[0], 0, 100), 'last_name' => \mb_substr($parts[1], 0, 100), 'email' => $email];
    }

    /**
     * Create a sales invoice for a contact and mark it as sent, which makes
     * it an open (unpaid) invoice. FreeAgent always creates invoices as drafts.
     *
     * @param array $lineItems Each item: ['description', 'quantity', 'unitAmount', 'itemType']
     * @return array ['invoiceUrl', 'invoiceNumber', 'markedSent', 'markError']
     */
    public function createInvoice(
        string $contactUrl,
        array $lineItems,
        string $categoryUrl,
        float $taxRate,
        string $orderRef,
        string $invoiceDate,
    ): array {
        $invoiceItems = \array_map(static fn(array $item): array => [
            'description' => $item['description'],
            'item_type' => $item['itemType'],
            'quantity' => (string) $item['quantity'],
            'price' => \number_format($item['unitAmount'], 2, '.', ''),
            'sales_tax_rate' => (string) $taxRate,
            'category' => $categoryUrl,
        ], $lineItems);

        // No reference: FreeAgent auto-numbers, and a number of our own can leave gaps
        $response = $this->callApi(method: 'POST', path: 'invoices', body: [
            'invoice' => [
                'contact' => $contactUrl,
                'dated_on' => $invoiceDate,
                'payment_terms_in_days' => 0,
                'po_reference' => $orderRef,
                'comments' => "Order {$orderRef}",
                'invoice_items' => $invoiceItems,
            ],
        ]);

        $invoice = $response['invoice'] ?? [];
        $invoiceUrl = (string) ($invoice['url'] ?? '');

        $noUrl = $invoiceUrl === '';
        if ($noUrl) throw new \Exception('FreeAgent created the invoice but returned no URL');

        $result = [
            'invoiceUrl' => $invoiceUrl,
            'invoiceNumber' => $invoice['reference'] ?? null,
            'markedSent' => true,
            'markError' => null,
        ];

        // The draft exists now, so a failure here must not hide it from the caller
        try {
            $this->callApi(method: 'PUT', path: $this->pathFromUrl($invoiceUrl) . '/transitions/mark_as_sent');
        } catch (\Exception $e) {
            $result['markedSent'] = false;
            $result['markError'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Confirm the connection still works by fetching the company.
     */
    public function testConnection(): array
    {
        try {
            $response = $this->callApi(method: 'GET', path: 'company');

            return [
                'success' => true,
                'message' => 'Connection successful',
                'company' => (string) ($response['company']['name'] ?? ''),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Turn a resource URL such as https://api.freeagent.com/v2/invoices/3
     * back into the path after /v2/.
     */
    private function pathFromUrl(string $url): string
    {
        $position = \strpos($url, '/v2/');

        $notV2Url = $position === false;
        if ($notV2Url) throw new \Exception("Unexpected FreeAgent URL: {$url}");

        return \substr($url, $position + 4);
    }

    /**
     * Make an authenticated call to the FreeAgent API, refreshing the access
     * token first if it's about to expire.
     *
     * @throws \Exception On curl failure, malformed JSON, or an API error
     */
    private function callApi(string $method, string $path, ?array $body = null): array
    {
        return $this->request(
            method: $method,
            path: $path,
            accessToken: $this->ensureValidAccessToken(),
            body: $body,
        );
    }

    private function request(
        string $method,
        string $path,
        string $accessToken,
        ?array $body = null,
    ): array {
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
        ];

        $options = [
            CURLOPT_URL => $this->host . '/v2/' . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];

        $hasBody = $body !== null;
        if ($hasBody) {
            $options[CURLOPT_POSTFIELDS] = \json_encode($body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }

        // A PUT with no body (the status transitions) still needs a length header
        $emptyWrite = !$hasBody && \in_array($method, ['PUT', 'POST'], true);
        if ($emptyWrite) {
            $options[CURLOPT_POSTFIELDS] = '';
            $headers[] = 'Content-Length: 0';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;

        $ch = \curl_init();
        \curl_setopt_array($ch, $options);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo(handle: $ch, option: CURLINFO_HTTP_CODE);
        $curlError = \curl_error($ch);

        if ($curlError) throw new \Exception("FreeAgent API curl error: {$curlError}");

        $tooManyRequests = $httpCode === 429;
        if ($tooManyRequests) throw new \Exception('FreeAgent rate limit reached. Wait a minute and try again.');

        // Transitions return 200 with a body that may be empty
        $emptyBody = \trim((string) $response) === '';
        $isError = $httpCode >= 400;
        if ($emptyBody && !$isError) return [];

        $decoded = \json_decode(json: (string) $response, associative: true);

        $jsonDecodeError = \json_last_error() !== JSON_ERROR_NONE || !\is_array($decoded);
        if ($jsonDecodeError && !$isError) throw new \Exception("FreeAgent API returned invalid JSON (HTTP {$httpCode}): {$response}");

        if ($isError) {
            $message = (\is_array($decoded) ? $this->extractErrorMessage($decoded) : null) ?? "HTTP {$httpCode}";
            throw new \Exception("FreeAgent API error: {$message}");
        }

        return $decoded;
    }

    /**
     * Pulls readable text out of an error body. The error format is not
     * documented on the pages checked, so this accepts the common shapes
     * ({"errors":[{"message":...}]}, {"errors":{"field":[...]}}, {"message":...})
     * and otherwise returns the raw body.
     */
    private function extractErrorMessage(array $decoded): ?string
    {
        $errors = $decoded['errors'] ?? null;

        if (\is_array($errors)) {
            $messages = [];
            \array_walk_recursive($errors, static function ($value, $key) use (&$messages): void {
                $isText = \is_string($value) && $value !== '';
                if ($isText) $messages[] = \is_string($key) && $key !== 'message' ? "{$key}: {$value}" : $value;
            });

            $hasMessages = !empty($messages);
            if ($hasMessages) return \implode('; ', $messages);
        }

        $single = $decoded['message'] ?? $decoded['error'] ?? null;
        if (\is_string($single) && $single !== '') return $single;

        return \mb_substr((string) \json_encode($decoded), 0, 300);
    }
}
