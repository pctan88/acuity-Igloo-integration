<?php
// src/CronJob.php

class CronJob
{
    private $config;

    public function __construct()
    {
        // Load configuration details
        $this->config = include __DIR__ . '/../config/config.php';
    }

    public function run()
    {
        // Set the correct time zone
        date_default_timezone_set('Asia/Singapore');  // Adjust this to your time zone

        // Get Acuity API details
        $apiUrl = $this->config['acuity_api_url'];
        $userId = $this->config['acuity_user_id'];
        $apiKey = $this->config['acuity_api_key'];
        $logFile = $this->config['log_file'];

        // Get current time and time for the next hour
        $now = new DateTime();
``        $nextHour = (clone $now)->modify('+1 hour');

        // Format times in ISO 8601 format with timezone
        $nowFormatted = $now->format('Y-m-d\TH:i:s');
        $nextHourFormatted = $nextHour->format('Y-m-d\TH:i:s');

        // Append minDate and maxDate query parameters to the API URL
        $apiUrlWithDate = $apiUrl . '?minDate=' . $nowFormatted . '&maxDate=' . $nextHourFormatted;

        // Fetch appointments
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrlWithDate);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$userId:$apiKey");

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            $this->log("Error: $error", $logFile);
            return;
        }

        $responseData = json_decode($response, true);
        $this->log("API Response: " . print_r($responseData, true), $logFile);

        // Process appointments and generate PINs
        if (is_array($responseData) && count($responseData) > 0) {
            foreach ($responseData as $appointment) {
                $pinCode = $this->generatePinCode();
                $this->log("Generated 4-digit PIN: $pinCode", $logFile);

                // Send the PIN to Igloo
                $this->sendToIgloo($pinCode, $appointment, $logFile);

                // Update the appointment with the generated PIN
                $this->updateAppointmentWithPin($appointment['id'], $pinCode, $logFile);
            }
        } else {
            $this->log("No appointments found in the next hour.", $logFile);
        }

        curl_close($ch);
    }

    // Function to generate a 4-digit PIN
    private function generatePinCode()
    {
        return str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);  // Generate a random 4-digit PIN
    }

    // Function to send PIN to Igloo
    private function sendToIgloo($pinCode, $appointment, $logFile)
    {
        // Get Igloo API details
        $deviceId = $this->config['igloo_device_id'];
        $bridgeId = $this->config['igloo_bridge_id'];
        $accessToken = $this->config['igloo_access_token'];

        // Construct the Igloo API URL
        $iglooUrl = str_replace(
            ['{deviceId}', '{bridgeId}'],
            [$deviceId, $bridgeId],
            $this->config['igloo_api_url']
        );

        // Parse appointment start time
        $appointmentStartTime = new DateTime($appointment['datetime']);  // Appointment start time
        $appointmentEndTime = clone $appointmentStartTime;
        $appointmentEndTime->modify('+' . $appointment['duration'] . ' minutes');  // Appointment end time

        // Add 10-minute buffer before and after
        $startDate = clone $appointmentStartTime;
        $startDate->modify('-10 minutes');  // Buffer of 10 minutes before the appointment

        $endDate = clone $appointmentEndTime;
        $endDate->modify('+10 minutes');  // Buffer of 10 minutes after the appointment

        // Format start and end times
        $formattedStartDate = $startDate->format('Y-m-d\TH:i:s+08:00');
        $formattedEndDate = $endDate->format('Y-m-d\TH:i:s+08:00');

        // Set the request payload for creating a PIN
        $postData = [
            'jobType' => 4,  // Create Custom PIN code
            'jobData' => [
                'accessName' => $appointment['firstName'] . ' ' . $appointment['lastName'],  // Use appointment's first and last name
                'pin' => $pinCode,  // The generated 4-digit PIN
                'pinType' => 4,  // Duration-based PIN type
                'startDate' => $formattedStartDate,  // Start date with buffer
                'endDate' => $formattedEndDate       // End date with buffer
            ]
        ];

        // Log the request body
        $this->log("Igloo API Request Body: " . json_encode($postData), $logFile);

        // Initialize cURL for the Igloo API call
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $iglooUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            $this->log("Error generating PIN with Igloo: $error", $logFile);
        } else {
            $this->log("Igloo API Response: " . print_r(json_decode($response, true), true), $logFile);
        }

        curl_close($ch);
    }

    // Function to update Acuity Scheduling appointment with the PIN in the notes
    private function updateAppointmentWithPin($appointmentId, $pinCode, $logFile)
    {
        // Get Acuity API details
        $apiUrl = $this->config['acuity_api_url'] . '/' . $appointmentId . '?admin=true';
        $userId = $this->config['acuity_user_id'];
        $apiKey = $this->config['acuity_api_key'];

        // Prepare data to update the notes field
        $putData = ['notes' => $pinCode];

        // Log the request body
        $this->log("Acuity API Request Body: " . json_encode($putData), $logFile);

        // Initialize cURL for the PUT request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode("$userId:$apiKey"),
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($putData));

        // Execute the PUT request
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            $this->log("Error updating appointment notes: $error", $logFile);
        } else {
            $this->log("Appointment Updated with PIN: " . print_r(json_decode($response, true), true), $logFile);
        }

        curl_close($ch);
    }

    // Basic log function
    private function log($message, $file)
    {
        file_put_contents($file, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
    }
}
