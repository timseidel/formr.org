<?php

/**
 * @todo
 * Wrap all public actions in try..catch construct
 */
class ApiController extends Controller
{

    /**
     * POST Request variables
     *
     * @var Request
     */
    protected $post;

    /**
     * GET Request variables
     *
     * @var Request
     */
    protected $get;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var OAuth2\Server
     */
    protected $oauthServer;

    protected $unrestrictedActions = ['end-last-external'];

    public function __construct(Site &$site)
    {
        parent::__construct($site);
        $this->initialize();
    }

    /**
     * Main API Entry Point.
     * * Determines if the request targets the V1 REST API or falls back to legacy behavior.
     *
     * @param string|null $resource The requested resource (or 'v1').
     * @param string|null $version  The version string (optional).
     * @return mixed Response object or void.
     */
    public function indexAction($resource = null, $version = null)
    {
        if ($version === 'v1' && $resource) {
            // Pass the rest of the arguments
            $args = array_slice(func_get_args(), 2);
            return $this->dispatchV1($resource, $args);
        }

        // Default behavior (Legacy 403)
        $this->respond(Response::STATUS_FORBIDDEN, 'Forbidden', array(
            'code' => Response::STATUS_FORBIDDEN,
            'message' => 'No valid API entry point found'
        ));
    }

    /**
     * V1 API Dispatcher.
     * * Authenticates the user, instantiates the V1 Helper, and dynamically calls 
     * the requested resource method. Wraps execution in a global try/catch for JSON error handling.
     *
     * @param string $resource The API resource to invoke (e.g., 'user', 'runs').
     * @param array $arguments Additional arguments passed from the router.
     * @return void Sends a JSON response.
     */
    private function dispatchV1($resource, $arguments = [])
    {
        $this->authenticate($resource);

        try {
            // Explicit allowlist instead of method_exists($api, $resource).
            // ApiV1 declares only these three top-level resource methods,
            // but it also inherits public helpers from ApiBase
            // (getData, getPathSegments, setPathSegments) that
            // method_exists would happily resolve — leaving them
            // dispatchable through /api/v1/getData etc. None do anything
            // privileged, but routing is exactly the wrong place to
            // trust inheritance.
            $allowedResources = ['user', 'surveys', 'runs'];
            if (!in_array($resource, $allowedResources, true)) {
                $this->respond(404, 'Not Found', ['error' => "Resource '$resource' not found in V1 API."]);
                return;
            }

            $token_data = $this->oauthServer->getAccessTokenData(OAuth2\Request::createFromGlobals());

            if (!class_exists('ApiV1')) {
                throw new Exception("V1 API not installed.");
            }

            $api = new ApiV1($this->site->request, $this->fdb, $token_data);

            // Execute the helper method
            $apiResult = $api->$resource(...$arguments);
            $data = $apiResult->getData();
        } catch (Throwable $e) {
            // Throwable, not Exception — PHP raises Error (not Exception) for
            // typed-arg mismatches, undefined methods, type errors etc., and
            // we want those to become a JSON 500 envelope rather than crash
            // the request with a default PHP error page.
            //
            // getCode() returns int 0 by default — `?:` (not `??`) is what
            // falls back to 500. Throwers that mean a 4xx (e.g.
            // ApiBase::checkScope) supply an explicit code.
            $code = $e->getCode() ?: Response::STATUS_INTERNAL_SERVER_ERROR;
            // Only log unexpected (5xx) failures; 4xx is client-driven and
            // doesn't belong in the server error log.
            if ($code >= 500) {
                formr_log_exception($e, 'API-V1-Dispatcher');
            }
            $data = [
                'statusCode' => $code,
                'statusText' => ApiBase::getStatusText($code),
                'response' => [
                    'code' => $code,
                    'message' => $e->getMessage()
                ]
            ];
        }

        $this->respond($data['statusCode'], $data['statusText'], $data['response']);
    }

    /**
     * Non-OAuth ingestion endpoint for external key-value data.
     *
     *   POST /api/ingest/<run>/<key>        (key in the URL — webhook style)
     *   POST /api/ingest/<run>              + X-Api-Key: <key>  header
     *                                       (or Authorization: Bearer <key>)
     *   body: { "ref": "...", "data": { ... } }
     *
     * Authenticated by a run ingestion key (RunIngestKey), NOT OAuth — so
     * it lives outside dispatchV1/doAction and verifies the key itself.
     * The key is pinned to one run + one `source` and is write-only, so
     * the body carries only ref + data. Writes go through the same
     * ExternalData::mergePayload() as the OAuth endpoint (atomic
     * JSON_MERGE_PATCH; null deletes a key).
     *
     * NOTE: per-key rate limiting is intentionally NOT done here — the
     * app Cache is per-request (non-persistent), so a meaningful limiter
     * needs the reverse proxy (e.g. traefik rate-limit middleware on
     * /api/ingest) or a DB-backed counter. Enforce it at the proxy.
     */
    public function ingestAction($run = null, $key = null)
    {
        if (!Request::isHTTPPostRequest()) {
            return $this->respond(Response::STATUS_METHOD_NOT_ALLOWED, 'Method Not Allowed', array(
                'code' => Response::STATUS_METHOD_NOT_ALLOWED,
                'message' => 'Use POST to send ingestion data.',
            ));
        }

        $key = $this->extractIngestKey($key);
        if ($key === null) {
            return $this->ingestError(Response::STATUS_UNAUTHORIZED, 'An ingestion key is required (in the URL, X-Api-Key, or Authorization: Bearer header).');
        }

        $row = RunIngestKey::resolve($key);
        if (!$row) {
            // Unknown or revoked — don't distinguish.
            return $this->ingestError(Response::STATUS_UNAUTHORIZED, 'Invalid or revoked ingestion key.');
        }

        // The key is the authority for which run is written; the <run> in
        // the path is for readability and must match, or we 404 to avoid
        // silently writing a different run than the URL implies.
        $runModel = new Run($run);
        if (!$runModel->valid || (int) $runModel->id !== (int) $row['run_id']) {
            return $this->ingestError(Response::STATUS_NOT_FOUND, 'Run not found for this ingestion key.');
        }

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->ingestError(Response::STATUS_BAD_REQUEST, 'Request body must be a JSON object.');
        }

        // source is pinned to the key — body cannot override it.
        $source_name = $row['source_name'];
        $run_id = (int) $row['run_id'];

        // Batch mode: top-level "entries" array with per-entry ref+data.
        if (isset($body['entries']) && is_array($body['entries'])) {
            return $this->ingestBatch($run_id, $source_name, $body['entries'], $row['id']);
        }

        // Single-ref mode (original).
        $ref = isset($body['ref']) ? $body['ref'] : null;
        $data = isset($body['data']) ? $body['data'] : null;
        if ($err = ExternalData::validateRefAndData($ref, $data)) {
            return $this->ingestError(Response::STATUS_BAD_REQUEST, $err);
        }

        $merged = ExternalData::mergePayload($run_id, $source_name, trim($ref), $data);
        if ($merged === null && $data !== array()) {
            return $this->ingestError(Response::STATUS_INTERNAL_SERVER_ERROR, 'Failed to store ingestion data.');
        }
        RunIngestKey::touchLastUsed($row['id']);

        return $this->respond(Response::STATUS_OK, 'OK', array(
            'source' => $source_name,
            'ref' => trim($ref),
            'payload' => $merged,
        ));
    }

    private function ingestBatch($run_id, $source_name, array $entries, $key_id)
    {
        if ($err = ExternalData::validateBatch($entries)) {
            return $this->ingestError(Response::STATUS_BAD_REQUEST, $err);
        }

        // Validate all entries before writing anything.
        foreach ($entries as $i => $entry) {
            $ref = isset($entry['ref']) ? $entry['ref'] : null;
            $data = isset($entry['data']) ? $entry['data'] : null;
            if ($err = ExternalData::validateRefAndData($ref, $data)) {
                return $this->ingestError(Response::STATUS_BAD_REQUEST, "entries[{$i}]: {$err}");
            }
        }

        try {
            $results = ExternalData::mergePayloadBatch($run_id, $source_name, $entries);
        } catch (\Exception $e) {
            return $this->ingestError(Response::STATUS_INTERNAL_SERVER_ERROR, 'Failed to store batch: ' . $e->getMessage());
        }
        RunIngestKey::touchLastUsed($key_id);

        return $this->respond(Response::STATUS_OK, 'OK', array(
            'source' => $source_name,
            'entries' => $results,
        ));
    }

    /** Pull the ingestion key from the path, X-Api-Key, or Bearer header. */
    private function extractIngestKey($pathKey)
    {
        if (is_string($pathKey) && $pathKey !== '') {
            return $pathKey;
        }
        $headers = $this->requestHeaders();
        if (!empty($headers['x-api-key'])) {
            return $headers['x-api-key'];
        }
        $auth = $headers['authorization'] ?? '';
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Case-insensitive request headers. Apache strips `Authorization`
     * from $_SERVER on many configs, so fall back to
     * apache_request_headers() — the same path bshaffer's OAuth2\Request
     * uses to find the bearer token.
     */
    private function requestHeaders()
    {
        $out = array();
        if (function_exists('apache_request_headers')) {
            foreach ((array) apache_request_headers() as $k => $v) {
                $out[strtolower($k)] = $v;
            }
        }
        if (empty($out['authorization'])) {
            if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
                $out['authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $out['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
        }
        if (empty($out['x-api-key']) && !empty($_SERVER['HTTP_X_API_KEY'])) {
            $out['x-api-key'] = $_SERVER['HTTP_X_API_KEY'];
        }
        return $out;
    }

    private function ingestError($code, $message)
    {
        return $this->respond($code, ApiBase::getStatusText($code), array(
            'code' => $code,
            'message' => $message,
        ));
    }

    public function oauthAction($action = null)
    {
        if (!$this->isValidAction('oauth', $action)) {
            $this->response->badRequest('Invalid Auth Request');
        }

        $this->oauthServer = Site::getOauthServer();
        if ($action === 'authorize') {
            $this->authorize();
        } elseif ($action === 'access_token') {
            $this->access_token();
        } elseif ($action === 'delete_token') {
            $this->delete_token();
        }
    }

    public function postAction($action = null)
    {
        if (!Request::isHTTPPostRequest()) {
            $this->response->badMethod('Invalid Request Method');
        }

        if (!$this->isValidAction('post', $action)) {
            $this->response->badRequest('Invalid Post Request');
        }

        $this->doAction($this->post, $action);
    }

    public function getAction($action = null)
    {
        if (!Request::isHTTPGetRequest()) {
            $this->response->badMethod('Invalid Request Method');
        }

        if (!$this->isValidAction('get', $action)) {
            $this->response->badRequest('Invalid Get Request');
        }

        $this->doAction($this->get, $action);
    }

    /**
     * OSF Integration Handler.
     * * Manages the OAuth2 flow with the Open Science Framework. Handles:
     * 1. Login redirection.
     * 2. Authorization code exchange.
     * 3. Error handling for denied access.
     *
     * @param string $do The specific action to perform (e.g., 'login').
     * @return void Redirects user based on authentication outcome.
     */
    public function osfAction($do = '')
    {
        $user = Site::getCurrentUser();
        if (!$user->loggedIn()) {
            alert('You need to login to access this section', 'alert-warning');
            redirect_to('admin/account/login');
        }

        $osfconfg = Config::get('osf');
        $osfconfg['state'] = $user->user_code;

        $osf = new OSF($osfconfg);

        // Case 1: User wants to login to give formr authorization
        // If user has a valid access token then just use it (i.e redirect to where access token is needed
        if ($do === 'login') {
            $redirect = $this->request->getParam('redirect', 'admin/osf') . '#osf';
            if ($token = OSF::getUserAccessToken($user)) {
                // redirect user to where he came from and get access token from there for current user
                alert('You have authorized FORMR to act on your behalf on the OSF', 'alert-success');
            } else {
                Session::set('formr_redirect', $redirect);
                // redirect user to login link
                $redirect = $osf->getLoginUrl();
            }
            redirect_to($redirect);
        }

        // Case 2: User is oauth2-ing. Handle authorization code exchange
        if ($code = $this->request->getParam('code')) {
            if ($this->request->getParam('state') != $user->user_code) {
                throw new Exception("Invalid OSF-OAUTH 2.0 request");
            }

            $params = $this->request->getParams();
            try {
                $logged = $osf->login($params);
            } catch (Exception $e) {
                formr_log_exception($e, 'OSF');
                $logged = array('error' => $e->getMessage());
            }

            if (!empty($logged['access_token'])) {
                // save this access token for this user
                // redirect user to where osf actions need to happen (@todo pass this in a 'redirect session parameter'
                OSF::setUserAccessToken($user, $logged);
                alert('You have authorized FORMR to act on your behalf on the OSF', 'alert-success');
                if ($redirect = Session::get('formr_redirect')) {
                    Session::delete('formr_redirect');
                } else {
                    $redirect = 'admin/osf';
                }
                redirect_to($redirect);
            } else {
                $error = !empty($logged['error']) ? $logged['error'] : 'Access token could not be obtained';
                alert('OSF API Error: ' . $error, 'alert-danger');
                redirect_to('admin');
            }
        }

        // Case 3: User is oauth2-ing. Handle case when user cancels authorization
        if ($error = $this->request->getParam('error')) {
            alert('Access was denied at OSF-Formr with error code: ' . $error, 'alert-danger');
            redirect_to('admin');
        }

        redirect_to('index');
    }

    protected function doAction(Request $request, $action)
    {
        try {
            $this->authenticate($action); // only proceed if authenticated, if not exit via response
            $token_data = $this->oauthServer->getAccessTokenData(OAuth2\Request::createFromGlobals());
            $method = $this->getPrivateAction($action, '-', true);

            $api = new ApiV0($request, $this->fdb, $token_data);
            $data = $api->{$method}()->getData();
        } catch (Throwable $e) {
            // Throwable for the same reason as dispatchV1: PHP Errors must
            // turn into a JSON envelope, not a default error page.
            formr_log_exception($e, 'API');
            $data = array(
                'statusCode' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'statusText' => 'Internal Server Error',
                'response' => array('code' => Response::STATUS_INTERNAL_SERVER_ERROR, 'message' => 'An unexpected error occurred'),
            );
        }

        $this->respond($data['statusCode'], $data['statusText'], $data['response']);
    }

    protected function isValidAction($type, $action)
    {
        $actions = array(
            'oauth' => array('authorize', 'access_token', 'delete_token'),
            'post' => array('create-session', 'end-last-external'),
            'get' => array('results'),
        );

        return isset($actions[$type]) && in_array($action, $actions[$type]);
    }

    protected function authorize()
    {
        /*
         * @todo
         * Implement authorization under oauth
         */
        $this->response->badRequest('Not Implemented');
    }

    protected function access_token()
    {
        // Ex: curl -u testclient:testpass https://<host>/api/oauth/token -d 'grant_type=client_credentials'
        $this->oauthServer->handleTokenRequest(OAuth2\Request::createFromGlobals())->send();
    }

    protected function delete_token()
    {
        OAuthHelper::getInstance()->deleteAccessToken($this->post->access_token);
        $this->respond(Response::STATUS_OK, 'Token deleted');
    }

    protected function authenticate($action)
    {
        if (!in_array($action, $this->unrestrictedActions)) {
            $this->oauthServer = Site::getOauthServer();

            // Enforce header-only bearer tokens. bshaffer's default
            // BearerToken accepts access_token from the Authorization
            // header, the request body (POST), AND the query string
            // (`?access_token=…`). The body/URI paths leak the token
            // into Apache access logs, Traefik logs, browser history,
            // and HTTP Referrer headers, so the v1 work explicitly
            // moved API clients onto the header (commit b17be066) —
            // but the server side was never tightened to match. Reject
            // here BEFORE verifyResourceRequest so a client that
            // accidentally sends both header + query gets a clear 401
            // pointing at the misuse rather than a silent acceptance
            // of the URL token.
            if (!empty($_GET['access_token']) || !empty($_POST['access_token'])) {
                $this->respond(Response::STATUS_UNAUTHORIZED, 'Unauthorized', array(
                    'code' => Response::STATUS_UNAUTHORIZED,
                    'message' => 'access_token must be sent in the Authorization: Bearer <token> header, not in URL query string or request body',
                ));
            }

            // Handle a request to a resource and authenticate the access token
            // Ex: curl -H "Authorization: Bearer YOUR_TOKEN" https://<host>/api/get/results
            if (!$this->oauthServer->verifyResourceRequest(OAuth2\Request::createFromGlobals())) {
                $this->respond(Response::STATUS_UNAUTHORIZED, 'Unauthorized', array(
                    'code' => Response::STATUS_UNAUTHORIZED,
                    'message' => 'Access token for this resource request is invalid or unauthorized',
                ));
            }
        }
    }

    protected function respond($statusCode = Response::STATUS_OK, $statusText = 'OK', $response = null)
    {
        $this->response->setStatusCode($statusCode, $statusText);
        $this->response->setContentType('application/json');
        $this->response->setJsonContent($response);
        return $this->sendResponse();
    }

    protected function initialize()
    {
        $this->view = null;
        $this->post = new Request($_POST);
        $this->get = new Request($_GET);
        $this->response = new Response();
    }
}
