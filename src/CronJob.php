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

        // Get current time and time for the next 3 hour
        $today = new DateTime();
        $startTime = $today->format('Y-m-d');
        $endTime = $today->modify('+3 hour')->format('Y-m-d');

        // Format times in ISO 8601 format with timezone
        $startTimeFormatted = $startTime->format('Y-m-d\TH:i:s');
        $endTimeFormatted = $endTime->format('Y-m-d\TH:i:s');

        // Append minDate and maxDate query parameters to the API URL
        $apiUrlWithDate = $apiUrl . '?minDate=' . $startTimeFormatted . '&maxDate=' . $endTimeFormatted;

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
        $this->logCleanedResponse($responseData, $logFile);  // Log cleaned response

        // Step 1: Group appointments by user and time slot, ordered by time
        usort($responseData, function ($a, $b) {
            return strtotime($a['time']) - strtotime($b['time']);
        });

        $appointmentsByUser = [];
        foreach ($responseData as $appointment) {
            $userEmail = $appointment['email'];
            $appointmentsByUser[$userEmail][] = $appointment;
        }

        $usedKeys = [];

        // Step 2: Process each user’s appointments in order of time
        foreach ($appointmentsByUser as $userEmail => $appointments) {
            $lastEndTime = null;
            $currentGroup = [];
            foreach ($appointments as $appointment) {
                $appointmentStartTime = new DateTime($appointment['time']);
                if ($lastEndTime && $appointmentStartTime == $lastEndTime) {
                    // Consecutive slot, add to current group
                    $currentGroup[] = $appointment;
                    $lastEndTime = new DateTime($appointment['endTime']);
                } else {
                    // Non-consecutive, process previous group if exists
                    if (!empty($currentGroup)) {
                        $this->processAppointmentGroup($currentGroup, $usedKeys, $logFile);
                    }
                    // Start new group
                    $currentGroup = [$appointment];
                    $lastEndTime = new DateTime($appointment['endTime']);
                }
            }
            // Process the last group
            if (!empty($currentGroup)) {
                $this->processAppointmentGroup($currentGroup, $usedKeys, $logFile);
            }
        }

        curl_close($ch);
    }

    // Process group of appointments and assign a single PIN to the group
    private function processAppointmentGroup($appointments, &$usedKeys, $logFile)
    {
        $firstAppointment = reset($appointments);
        $timeSlotKey = $firstAppointment['time'] . '-' . $firstAppointment['endTime'];

        if (!isset($usedKeys[$timeSlotKey])) {
            // Generate a new PIN for this group
            $pinCode = $this->generatePinCode();
            $usedKeys[$timeSlotKey] = $pinCode;

            // Add 10-minute buffer before and after the time slot
            $startDateTime = new DateTime($firstAppointment['datetime']);
            $endDateTime = (clone $startDateTime)->modify('+' . $firstAppointment['duration'] . ' minutes');
            $startDateTime->modify('-10 minutes');
            $endDateTime->modify('+10 minutes');

            // Format the start and end dates
            $formattedStartDate = $startDateTime->format('Y-m-d\TH:i:s+08:00');
            $formattedEndDate = $endDateTime->format('Y-m-d\TH:i:s+08:00');

            // Create a name for the key based on the time slot, e.g., "2PM-4PM 06Oct"
            $timeSlotName = $startDateTime->format('gA') . '-' . $endDateTime->format('gA') . ' ' . $startDateTime->format('dM');

            // Send the PIN to Igloo for the entire time slot
            $this->sendToIgloo($pinCode, $timeSlotName, $formattedStartDate, $formattedEndDate, $logFile);
        }

        // Assign the PIN to all appointments in the group
        foreach ($appointments as $appointment) {
            $this->updateAppointmentWithPin($appointment['id'], $usedKeys[$timeSlotKey], $logFile);
        }
    }

    // Function to generate a 4-digit PIN
    private function generatePinCode()
    {
        return str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);  // Generate a random 4-digit PIN
    }

    // Function to send PIN to Igloo
    private function sendToIgloo($pinCode, $timeSlotName, $startDate, $endDate, $logFile)
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

        // Set the request payload for creating a PIN
        $postData = [
            'jobType' => 4,  // Create Custom PIN code
            'jobData' => [
                'accessName' => $timeSlotName,  // Use the time slot name as accessName
                'pin' => $pinCode,  // The generated 4-digit PIN
                'pinType' => 4,  // Duration-based PIN type
                'startDate' => $startDate,  // Start date with buffer
                'endDate' => $endDate       // End date with buffer
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

    // Clean up response to log only relevant attributes
    private function logCleanedResponse($responseData, $logFile)
    {
        // Useful attributes for debugging
        $usefulAttributes = [
            'id', 'firstName', 'lastName', 'phone', 'email', 'date', 'time', 'endTime',
            'calendarID', 'location', 'notes'
        ];

        $cleanedResponse = [];
        foreach ($responseData as $appointment) {
            $cleanedAppointment = [];
            foreach ($usefulAttributes as $attribute) {
                if (isset($appointment[$attribute])) {
                    $cleanedAppointment[$attribute] = $appointment[$attribute];
                }
            }
            $cleanedResponse[] = $cleanedAppointment;
        }

        // Log the cleaned response
        $this->log("Cleaned API Response: " . print_r($cleanedResponse, true), $logFile);
    }

    // Log function
    private function log($message, $logFile)
    {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "$timestamp - $message\n", FILE_APPEND);
    }
}
