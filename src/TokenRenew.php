<?php

class TokenRenew
{
    private $config;

    public function __construct()
    {
        // Load configuration details
        $this->config = include __DIR__ . '/../config/config.php';
    }

    public function run()
    {
        $logFile = $this->config['log_file'];

        // Attempt to fetch the new access token
        $newToken = $this->getAccessToken();
        if (!$newToken) {
            $this->log("Failed to fetch new access token.", $logFile);
            return;
        }

        // Update the configuration file with the new token
        $this->updateConfigWithNewToken($newToken, $logFile);
    }

    /**
     * Fetch new access token from Igloo API
     *
     * @return string|null Returns new token or null if failed
     */
    private function getAccessToken()
    {
        $tokenUrl = $this->config['igloo_token_url'];
        $client_id = $this->config['igloo_client_id'];
        $client_secret = $this->config['igloo_client_secret'];
        $credentials = base64_encode("$client_id:$client_secret");  // Base64 encoding of client_id:client_secret
        $logFile = $this->config['log_file'];

        // Prepare the data to be sent in the request
        $postData = http_build_query([
            'grant_type' => 'client_credentials',
            'scope' => 'igloohomeapi/algopin-hourly igloohomeapi/algopin-daily igloohomeapi/algopin-permanent igloohomeapi/algopin-onetime igloohomeapi/create-pin-bridge-proxied-job igloohomeapi/delete-pin-bridge-proxied-job igloohomeapi/lock-bridge-proxied-job igloohomeapi/unlock-bridge-proxied-job igloohomeapi/get-devices igloohomeapi/get-job-status'
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $this->log("Error fetching access token: " . curl_error($ch), $logFile);
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        // Check if the response is empty or invalid
        if (empty($response)) {
            $this->log("No response or empty response from Igloo API", $logFile);
            return null;
        }

        $responseData = json_decode($response, true);
        if (!isset($responseData['access_token'])) {
            $this->log("Invalid response fetching access token: " . print_r($responseData, true), $logFile);
            return null;
        }

        return $responseData['access_token'];
    }

    /**
     * Update the configuration file with the new token.
     *
     * @param string $newToken The new access token to update in the config file
     * @param string $logFile The log file to log the update activity
     */
    private function updateConfigWithNewToken($newToken, $logFile)
    {
        $configPath = __DIR__ . '/../config/config.php';

        // Load current config and update the access token
        $config = include $configPath;
        $config['igloo_access_token'] = $newToken;

        // Prepare the new config content as a string
        $configContent = "<?php\nreturn " . var_export($config, true) . ";\n";

        // Write the updated config back to the file
        if (file_put_contents($configPath, $configContent)) {
            $this->log("Successfully updated access token in config.php.", $logFile);
        } else {
            $this->log("Failed to update access token in config.php.", $logFile);
        }
    }

    /**
     * Simple logging function
     *
     * @param string $message The message to log
     * @param string $logFile The log file path
     */
    private function log($message, $logFile)
    {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "$timestamp - $message\n", FILE_APPEND);
    }
}
