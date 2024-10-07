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
        $logFile = $this->config['log_file'];

        // Get current time
        //$currentTime = new DateTime();
        //$currentHour = (int) $currentTime->format('H');

        $currentTime = new DateTime();
        $currentTime->modify('-1 day');
        $currentHour = (int) 14;

        // Process the time slot for 3 hours ahead (for example, if the cron runs at 1:45, it processes the 5-6 PM slot)
        $startTime = new DateTime($currentTime->format('Y-m-d') . ' ' . ($currentHour + 3) . ':00:00');
        $endTime = (clone $startTime)->modify('+1 hour');

        // Check for consecutive slots in the next 3 hours (to handle consecutive bookings)
        $maxConsecutiveTime = (clone $startTime)->modify('+3 hours');

        // Fetch appointments for the current time slot
        $responseData = $this->fetchAppointments($startTime, $maxConsecutiveTime);

        // If no appointments found, log and exit
        if (empty($responseData)) {
            $this->log("No appointments found for time slot: " . $startTime->format('gA') . '-' . $endTime->format('gA'), $this->config['log_file']);
            return;
        }

//        $this->logCleanedResponse($responseData, $logFile);  // Log cleaned response

        $this->processAppointments($responseData, $startTime, $endTime);
        $this->log("Cron job completed successfully", $this->config['log_file']);
    }

    private function fetchAppointments($startTime, $endTime)
    {
        $apiUrl = $this->config['acuity_api_url'];
        $userId = $this->config['acuity_user_id'];
        $apiKey = $this->config['acuity_api_key'];
        $logFile = $this->config['log_file'];

        // Append minDate and maxDate query parameters to the API URL
        $apiUrlWithDate = $apiUrl . '?minDate=' . $startTime->format('Y-m-d\TH:i:s') . '&maxDate=' . $endTime->format('Y-m-d\TH:i:s');

//        $this->log("API Request Body: " . $apiUrlWithDate, $logFile);
        // Make the API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrlWithDate);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$userId:$apiKey");

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            $this->log("Error fetching appointments: " . $error, $logFile);
            curl_close($ch);
            return [];
        }

        curl_close($ch);

        // Check if the response is empty or invalid
        if (empty($response)) {
            $this->log("No response or empty response from Acuity API", $logFile);
            return [];
        }

        // If response is already processed and cleaned, no need to use json_decode again
        $responseData = json_decode($response, true);
        if (!is_array($responseData)) {
            $this->log("Invalid response data: " . print_r($response, true), $logFile);
            return [];
        }

        return $responseData;
    }

    private function processAppointments($responseData, $startTime, $endTime)
    {
        $logFile = $this->config['log_file'];
        // Step 1: Sort appointments by time as before
        usort($responseData, function ($a, $b) {
            return strtotime($a['time']) - strtotime($b['time']);
        });

        // Step 2: Group appointments by user and calculate consecutive slots
        $appointmentsByUser = [];
        $consecutiveCounts = [];  // Store consecutive slot count for each user
        foreach ($responseData as $appointment) {
        // Skip if the appointment already has a PIN in the 'notes' field
                    if (!empty($appointment['notes'])) {
                        continue;
                    }

            $userEmail = $appointment['email'];
            $appointmentsByUser[$userEmail][] = $appointment;
        }

        // Step 3: Calculate consecutive slots for each user
        foreach ($appointmentsByUser as $userEmail => $appointments) {
            $lastEndTime = null;
            $consecutiveCount = 0;  // Tracks how many consecutive appointments the user has
            foreach ($appointments as $appointment) {
                $appointmentStartTime = new DateTime($appointment['time']);
                if ($lastEndTime && $appointmentStartTime == $lastEndTime) {
                    // Found consecutive slot
                    $consecutiveCount++;
                }
                $lastEndTime = new DateTime($appointment['endTime']);
            }
            // Save the consecutive count for the user
            $consecutiveCounts[$userEmail] = $consecutiveCount + 1; // +1 to include the first appointment
        }

        // Step 4: Sort users based on consecutive slots (descending order)
        uksort($appointmentsByUser, function ($a, $b) use ($consecutiveCounts) {
            return $consecutiveCounts[$b] - $consecutiveCounts[$a]; // Sort by most consecutive slots first
        });

        // Step 5: Process each user’s appointments in order of time, with sorted users
        $usedKeys = [];
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
                        $this->processAppointmentGroup($currentGroup, $usedKeys, $logFile, $startTime);
                    }
                    // Start new group
                    $currentGroup = [$appointment];
                    $lastEndTime = new DateTime($appointment['endTime']);
                }
            }
            // Process the last group
            if (!empty($currentGroup)) {
                $this->processAppointmentGroup($currentGroup, $usedKeys, $logFile, $startTime);
            }
        }
    }

    // Process group of appointments and assign a single PIN to the group
    private function processAppointmentGroup($appointments, &$usedKeys, $logFile, $startTime)
    {
        $firstAppointment = reset($appointments);
        $appointmentStartTime = new DateTime($firstAppointment['date'] . ' ' . $firstAppointment['time']);

        // Check if the first appointment's start time matches the input start time
        if ($appointmentStartTime != $startTime) {
            $this->log("Skipping group. Appointment start time " . $appointmentStartTime->format('Y-m-d H:i:s') . " does not match input start time " . $startTime->format('Y-m-d H:i:s'), $logFile);
            return;  // Skip this group
        }

        $timeSlotKey = $firstAppointment['time'] . '-' . $firstAppointment['endTime'];

        if (!isset($usedKeys[$timeSlotKey])) {
            // Generate a new PIN for this group
            $pinCode = $this->generatePinCode();
            $usedKeys[$timeSlotKey] = $pinCode;

            // Add 10-minute buffer before the start time of the first appointment
            $startDateTime = new DateTime($firstAppointment['datetime']);
            $startDateTime->modify('-10 minutes');

            // Find the maximum duration in the group of appointments
            $totalDuration = 0;
            foreach ($appointments as $appointment) {
                $totalDuration += $appointment['duration'];
            }

            // Calculate the end time based on the maximum duration
            $endDateTime = new DateTime($firstAppointment['datetime']);
            $endDateTime->modify('+' . $totalDuration . ' minutes');
            $endDateTime->modify('+10 minutes');  // Add 10-minute buffer after the end time

            // Create a timeSlotName based on the appointment time, not the buffer
            $appointmentStartTime = new DateTime($firstAppointment['time']);
            $appointmentEndTime = clone $endDateTime;  // Use the calculated end time
            $timeSlotName = $appointmentStartTime->format('gA') . '-' . $appointmentEndTime->format('gA') . ' ' . $appointmentStartTime->format('dM');

            // Format the start and end dates with the buffer
            $formattedStartDate = $startDateTime->format('Y-m-d\TH:i:s+08:00');
            $formattedEndDate = $endDateTime->format('Y-m-d\TH:i:s+08:00');

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
    //        $this->log("Appointment Updated with PIN: " . print_r(json_decode($response, true), true), $logFile);
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
