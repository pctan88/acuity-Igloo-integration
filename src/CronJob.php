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
        //$this->log("API Response: " . print_r($responseData, true), $logFile);
        $this->logCleanedResponse($responseData, $logFile);

        // Group appointments by time slot
        $appointmentsBySlot = [];
        foreach ($responseData as $appointment) {
            // Use the start time (e.g., '2024-10-06T15:00:00') as the key for grouping
            $slotKey = $appointment['datetime'];  // Group by exact appointment time (adjust if needed)
            if (!isset($appointmentsBySlot[$slotKey])) {
                $appointmentsBySlot[$slotKey] = [];
            }
            $appointmentsBySlot[$slotKey][] = $appointment;
        }

        // Process each time slot
        foreach ($appointmentsBySlot as $slotKey => $appointments) {
            // Generate a single PIN for the entire time slot
            $pinCode = $this->generatePinCode();
            $this->log("Generated 4-digit PIN for slot $slotKey: $pinCode", $logFile);

            // Calculate the start and end time based on the first appointment in the slot
            $firstAppointment = $appointments[0];
            $startDate = new DateTime($firstAppointment['datetime']);
            $endDate = clone $startDate;
            $endDate->modify('+' . $firstAppointment['duration'] . ' minutes');

            // Add 10-minute buffer before and after
            $startDate->modify('-10 minutes');
            $endDate->modify('+10 minutes');

            // Format start and end times
            $formattedStartDate = $startDate->format('Y-m-d\TH:i:s+08:00');
            $formattedEndDate = $endDate->format('Y-m-d\TH:i:s+08:00');

            // Create a name for the key based on the time slot, e.g., "3-4pm 6Oct"
            $timeSlotName = $startDate->format('gA') . '-' . $endDate->format('gA') . ' ' . $startDate->format('dM');

            // Send the PIN to Igloo once for the entire slot with the time slot name as the accessName
            $this->sendToIgloo($pinCode, $timeSlotName, $formattedStartDate, $formattedEndDate, $logFile);

            // Update all appointments in the slot with the same PIN
            foreach ($appointments as $appointment) {
                $this->updateAppointmentWithPin($appointment['id'], $pinCode, $logFile);
            }
        }

        curl_close($ch);
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
            //$this->log("Appointment Updated with PIN: " . print_r(json_decode($response, true), true), $logFile);
            $this->logCleanedResponse($responseData, $logFile);
        }

        curl_close($ch);
    }

    // Basic log function
    private function log($message, $file)
    {
        file_put_contents($file, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
    }

    private function logCleanedResponse($responseData, $logFile)
    {
        // Useful attributes for debugging
        $usefulAttributes = [
            'id', 'firstName', 'lastName', 'phone', 'email', 'date', 'time', 'endTime',
            'dateCreated', 'datetimeCreated', 'datetime', 'price', 'priceSold', 'paid',
            'amountPaid', 'type', 'appointmentTypeID', 'duration', 'calendar',
            'calendarID', 'certificate', 'location', 'notes'
        ];

        $cleanedData = [];

        foreach ($responseData as $appointment) {
            $cleanedAppointment = [];
            foreach ($usefulAttributes as $attribute) {
                if (isset($appointment[$attribute])) {
                    $cleanedAppointment[$attribute] = $appointment[$attribute];
                }
            }
            $cleanedData[] = $cleanedAppointment;
        }

        // Log cleaned data
        $this->log("Cleaned API Response: " . print_r($cleanedData, true), $logFile);
    }

}
