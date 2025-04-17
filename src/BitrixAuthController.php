<?php

require_once __DIR__ . "/../services/CacheService.php";
require_once __DIR__ . "/../services/ResponseService.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

class BitrixAuthController
{
    private ResponseService $response;

    public function __construct()
    {
        $this->response = new ResponseService();
    }

    public function processRequest(string $method): void
    {
        if ($method !== 'POST') {
            $this->response->sendError(405, "Method Not Allowed");
            return;
        }

        $contentType = $_SERVER["CONTENT_TYPE"] ?? '';

        $grant_type = $client_id = $client_secret = $code = $redirect_uri = null;

        if (stripos($contentType, 'application/json') !== false) {
            // Handle JSON body
            $input = json_decode(file_get_contents('php://input'), true);

            $grant_type    = $input['grant_type'] ?? null;
            $client_id     = $input['client_id'] ?? null;
            $client_secret = $input['client_secret'] ?? null;
            $code          = $input['code'] ?? null;
            $redirect_uri  = $input['redirect_uri'] ?? null;
        } else {
            // Handle form-urlencoded (default behavior)
            $grant_type    = $_POST['grant_type'] ?? null;
            $client_id     = $_POST['client_id'] ?? null;
            $client_secret = $_POST['client_secret'] ?? null;
            $code          = $_POST['code'] ?? null;
            $redirect_uri  = $_POST['redirect_uri'] ?? null;
        }

        $missing = [];

        if (!$grant_type) $missing[] = 'grant_type';
        if (!$client_id) $missing[] = 'client_id';
        if (!$client_secret) $missing[] = 'client_secret';
        if (!$code) $missing[] = 'code';
        if (!$redirect_uri) $missing[] = 'redirect_uri';

        if (!empty($missing)) {
            $this->response->sendError(400, "Missing required parameters: " . implode(', ', $missing));
            return;
        }

        // Prepare POST data
        $postData = http_build_query([
            'grant_type' => $grant_type,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'code' => $code,
            'redirect_uri' => $redirect_uri
        ]);

        // cURL request
        $ch = curl_init("https://oauth.bitrix.info/oauth/token/");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->response->sendError(500, "cURL Error: " . $error);
            return;
        }

        $responseData = json_decode($result, true);

        if ($httpCode !== 200 || isset($responseData['error'])) {
            $this->response->sendError($httpCode, $responseData['error_description'] ?? 'OAuth token request failed.');
            return;
        }

        $this->response->sendSuccess(200, $responseData);
    }
}
