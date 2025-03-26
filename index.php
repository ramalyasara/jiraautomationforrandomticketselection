<?php

use Google\CloudFunctions\FunctionsFramework;
use Psr\Http\Message\ServerRequestInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Jira API Configuration (Use environment variables in production)
define("JIRA_WEBHOOK_URL", getenv("JIRA_WEBHOOK_URL") ?: "");
define("JIRA_WEBHOOK_SECRET", getenv("JIRA_WEBHOOK_SECRET") ?: "");
define("JIRA_API_EMAIL", getenv("JIRA_API_EMAIL") ?: "");
define("JIRA_API_TOKEN", getenv("JIRA_API_TOKEN") ?: "");

// Initialize Logger
$logger = new Logger('cloud-function');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

// Register HTTP function
FunctionsFramework::http('helloHttp', function (ServerRequestInterface $request) use ($logger): string {
    // Read and log the raw request body
    $rawBody = $request->getBody()->getContents();
    $logger->info('Received raw request body: ' . $rawBody);

    // Validate request body
    if (empty($rawBody)) {
        $logger->error("Request body is empty");
        return json_encode(["status" => "error", "message" => "Request body is empty"]);
    }

    // Decode JSON
    $json = json_decode(trim($rawBody), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $logger->error("Invalid JSON format: " . json_last_error_msg());
        return json_encode(["status" => "error", "message" => "Invalid JSON format"]);
    }

    // Log full JSON request
    $logger->info("Decoded JSON: " . json_encode($json));

    // Validate 'tickets' field
    $tickets = $json['tickets'] ?? null;
    if (!$tickets || !is_array($tickets)) {
        $logger->error("Missing or invalid 'tickets' field");
        return json_encode(["status" => "error", "message" => "Missing or invalid 'tickets' field"]);
    }

    // Select 5 random tickets
    shuffle($tickets);
    $selectedTickets = array_slice($tickets, 0, 5);

    // Generate Basic Auth Header
    $authHeader = 'Basic ' . base64_encode(JIRA_API_EMAIL . ':' . JIRA_API_TOKEN);

    // Log the tickets being sent
    $logger->info("Sending data to Jira: " . json_encode(["issues" => $selectedTickets]));

    // Prepare HTTP Client
    $client = new Client(['debug' => true]);

    try {
        $response = $client->post(JIRA_WEBHOOK_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $authHeader,
                'X-Automation-Webhook-Token' => JIRA_WEBHOOK_SECRET
            ],
            'json' => ["issues" => $selectedTickets] 
        ]);

        $responseBody = $response->getBody()->getContents();
        $logger->info("Jira webhook response: $responseBody, HTTP Code: " . $response->getStatusCode());

        return json_encode(["status" => "success", "message" => "Data sent to Jira", "response" => json_decode($responseBody, true)]);

    } catch (RequestException $e) {
        $errorMessage = $e->getMessage();
        $logger->error("Request failed: $errorMessage");

        if ($e->hasResponse()) {
            $errorResponse = $e->getResponse()->getBody()->getContents();
            $logger->error("Jira API error response: $errorResponse");
            return json_encode([
                "status" => "error",
                "message" => "Failed to send data to Jira",
                "jira_response" => json_decode($errorResponse, true)
            ]);
        }

        return json_encode(["status" => "error", "message" => "Request failed", "error" => $errorMessage]);
    }
});
