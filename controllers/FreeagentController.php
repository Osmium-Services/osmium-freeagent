<?php

declare(strict_types=1);

namespace Osmium\Services\Freeagent\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Freeagent\Models\FreeagentConfig;
use Osmium\Services\Freeagent\Models\FreeagentService;

/**
 * FreeAgent settings controller - credentials, OAuth2 connection and status.
 *
 * Routes:
 *   - index()    → /admin/settings/freeagent/           (status + settings form)
 *   - connect()  → /admin/settings/freeagent/connect/   (redirects to FreeAgent)
 *   - callback() → /admin/settings/freeagent/callback/  (FreeAgent redirects back here)
 *   - action()   → /admin/settings/freeagent/action/    (disconnect, test_connection)
 */
class FreeagentController extends AdminController
{
    private const STATE_SESSION_KEY = 'freeagent_oauth_state';
    private const FLASH_KEY = 'freeagent_flash';

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handleSubmit();

        $error = $this->osmium->getStashedParam(key: 'error', default: null);
        $justConnected = $this->osmium->getStashedParam(key: 'connected', default: null);
        $this->osmium->clearStashedQuerystring();

        $config = (array) FreeagentConfig::get();
        $connection = $this->freeagent()->getConnection();
        $lookups = $this->loadLookups(connected: $connection !== null);

        $this->data['admin']['freeagent'] = [
            'clientId' => $config['clientId'],
            'hasSecret' => $config['clientSecret'] !== '',
            'redirectUri' => $config['redirectUri'] ?: $this->suggestedRedirectUri(),
            'sandbox' => (bool) $config['sandbox'],
            'categoryUrl' => $config['categoryUrl'],
            'categories' => $lookups['categories'],
            'lookupError' => $lookups['error'],
            'allowedCompanyName' => $config['allowedCompanyName'],
            'clientConfigured' => $config['clientId'] !== '' && $config['clientSecret'] !== '',
            'connected' => $connection !== null,
            'companyName' => $connection['company_name'] ?? null,
            'connectedAt' => $connection['connected_at'] ?? null,
            'error' => $error,
            'justConnected' => $justConnected !== null,
        ];
        $this->data['admin']['flash'] = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);

        $this->setView('freeagent/index.phtml');
    }

    /**
     * Start the OAuth2 flow - redirects the admin to FreeAgent's consent screen.
     */
    public function connect(): void
    {
        $state = \bin2hex(\random_bytes(16));
        $_SESSION[self::STATE_SESSION_KEY] = $state;

        $this->osmium->header->redirect(targetURL: $this->freeagent()->getAuthorizationUrl(state: $state));
    }

    /**
     * OAuth2 redirect target - exchanges the code FreeAgent sent back for tokens.
     */
    public function callback(): void
    {
        // FreeAgent's ?code=&state= never reach $_GET here - the framework's global
        // stashQuerystring() already stripped them into $_SESSION['qs'] and
        // 302'd to this same clean URL before this method ever runs.
        $returnedState = (string) $this->osmium->getStashedParam(key: 'state', default: '');
        $error = (string) $this->osmium->getStashedParam(key: 'error', default: '');
        $code = (string) $this->osmium->getStashedParam(key: 'code', default: '');
        $this->osmium->clearStashedQuerystring();

        $expectedState = $_SESSION[self::STATE_SESSION_KEY] ?? '';
        unset($_SESSION[self::STATE_SESSION_KEY]);

        $stateInvalid = empty($expectedState) || !\hash_equals($expectedState, $returnedState);
        if ($stateInvalid) $this->redirect('settings/freeagent/?error=' . \rawurlencode('Invalid OAuth state'));

        if ($error) $this->redirect('settings/freeagent/?error=' . \rawurlencode($error));

        $codeMissing = empty($code);
        if ($codeMissing) $this->redirect('settings/freeagent/?error=' . \rawurlencode('No authorization code returned'));

        try {
            $this->freeagent()->completeAuthorization(code: $code);
        } catch (\Exception $e) {
            $this->redirect('settings/freeagent/?error=' . \rawurlencode($e->getMessage()));
        }

        $this->redirect('settings/freeagent/?connected=1');
    }

    /**
     * Action endpoint - disconnect and test_connection.
     */
    public function action()
    {
        \header('Content-Type: application/json');

        $isPostMethod = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPostMethod) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = $input['action'] ?? '';

        $invalidToken = !$this->admin->auth->validateCsrfJson($input);
        if ($invalidToken) {
            $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');
        }

        match ($action) {
            'test_connection' => $this->testConnection(),
            'disconnect' => $this->disconnect(),
            default => $this->admin->jsonError('Unknown action'),
        };
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->flashAndRedirect('danger', 'Invalid form submission. Please try again.');

        $current = FreeagentConfig::get();

        $postedSecret = \trim($_POST['client_secret'] ?? '');
        $clientSecret = $postedSecret === '' ? (string) $current->clientSecret : $postedSecret; // Blank keeps the stored secret

        FreeagentConfig::save([
            'clientId' => \trim($_POST['client_id'] ?? ''),
            'clientSecret' => $clientSecret,
            'redirectUri' => \trim($_POST['redirect_uri'] ?? ''),
            'sandbox' => !empty($_POST['sandbox']),
            'categoryUrl' => isset($_POST['category_url']) ? \trim($_POST['category_url']) : (string) $current->categoryUrl, // Not on the form until connected
            'allowedCompanyName' => \trim($_POST['allowed_company_name'] ?? ''),
        ]);

        $this->admin->model->changelog->log(
            description: 'Updated FreeAgent settings',
            recordType: 'settings',
        );

        $this->flashAndRedirect('success', 'Settings saved successfully!');
    }

    private function testConnection(): void
    {
        $result = $this->freeagent()->testConnection();

        if ($result['success']) {
            $this->admin->jsonSuccess($result);
        }

        $this->admin->jsonError($result['message']);
    }

    private function disconnect(): void
    {
        $this->freeagent()->disconnect();

        $this->admin->model->changelog->log(
            description: 'Disconnected FreeAgent',
            recordType: 'freeagent_connection',
            recordId: 0,
        );

        $this->admin->jsonSuccess(['success' => true]);
    }

    /**
     * The income categories for the settings dropdown. A failure
     * (expired link, FreeAgent down) shows as a message instead of breaking the page.
     */
    private function loadLookups(bool $connected): array
    {
        $empty = ['categories' => [], 'error' => null];
        if (!$connected) return $empty;

        try {
            return [
                'categories' => $this->freeagent()->listIncomeCategories(),
                'error' => null,
            ];
        } catch (\Exception $e) {
            return ['categories' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * A tax rate percentage between 0 and 100, kept as a plain decimal string.
     * Anything else becomes 0 rather than a surprise rate on a real invoice.
     */
    private function freeagent(): FreeagentService
    {
        return FreeagentConfig::buildService($this->osmium->dataSource);
    }

    private function suggestedRedirectUri(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return "{$scheme}://{$_SERVER['HTTP_HOST']}{$this->data['admin']['basePath']}settings/freeagent/callback/";
    }

    private function flashAndRedirect(string $type, string $text): void
    {
        $_SESSION[self::FLASH_KEY] = ['type' => $type, 'text' => $text];
        $this->redirect('settings/freeagent/');
    }
}
