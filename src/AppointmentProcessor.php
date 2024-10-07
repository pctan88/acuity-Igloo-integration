<?php

class AppointmentProcessor
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
        date_default_timezone_set('Asia/Singapore');
        $logFile = $this->config['log_file'];

        // Define time slot range (processing 3 hours ahead of current time)
        $currentTime = new DateTime();
        $currentHour = (int) $currentTime->format('H');

        $startTime = new DateTime($currentTime->format('Y-m-d') . ' ' . ($currentHour + 3) . ':00:00');
        $endTime = (clone $startTime)->modify('+1 hour');
        $maxConsecutiveTime = (clone $startTime)->modify('+3 hours');

        // Fetch appointments for the current time slot
        $responseData = $this->fetchAppointments($startTime, $maxConsecutiveTime);

        // If no appointments found, log and exit
        if (empty($responseData)) {
            $this->log("No appointments found for time slot: " . $startTime->format('gA') . '-' . $endTime->format('gA'), $logFile);
            return;
        }

        // Process appointments
        $this->processAppointments($responseData, $startTime, $endTime);
        $this->log("Cron job completed successfully", $logFile);
    }

    private function fetchAppointments($startTime, $endTime)
    {
        $apiUrl = $this->config['acuity_api_url'];
        $userId = $this->config['acuity_user_id'];
        $apiKey = $this->config['acuity_api_key'];
        $logFile = $this->config['log_file'];

        // API URL with date range
        $apiUrlWithDate = $apiUrl . '?minDate=' . $startTime->format('Y-m-d\TH:i:s') . '&maxDate=' . $endTime->format('Y-m-d\TH:i:s');

        // Make the API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrlWithDate);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$userId:$apiKey");

        $response = curl_exec($ch);
        if ($response === false) {
            $this->log("Error fetching appointments: " . curl_error($ch), $logFile);
            curl_close($ch);
            return [];
        }

        curl_close($ch);

        if (empty($response)) {
            $this->log("No response or empty response from Acuity API", $logFile);
            return [];
        }

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

        // Step 1: Sort appointments by time
        usort($responseData, function ($a, $b) {
            return strtotime($a['time']) - strtotime($b['time']);
        });

        // Step 2: Group appointments by user and calculate consecutive slots
        $appointmentsByUser = [];
        $consecutiveCounts = [];
        foreach ($responseData as $appointment) {
            if (!empty($appointment['notes'])) {
//                continue;  // Skip if already assigned a PIN
            }
            $userEmail = $appointment['email'];
            $appointmentsByUser[$userEmail][] = $appointment;
        }

        // Step 3: Calculate consecutive slots for each user
        foreach ($appointmentsByUser as $userEmail => $appointments) {
            $lastEndTime = null;
            $consecutiveCount = 0;
            foreach ($appointments as $appointment) {
                $appointmentStartTime = new DateTime($appointment['time']);
                if ($lastEndTime && $appointmentStartTime == $lastEndTime) {
                    $consecutiveCount++;
                }
                $lastEndTime = new DateTime($appointment['endTime']);
            }
            $consecutiveCounts[$userEmail] = $consecutiveCount + 1;  // Include the first appointment
        }

        // Step 4: Sort users based on consecutive slots
        uksort($appointmentsByUser, function ($a, $b) use ($consecutiveCounts) {
            return $consecutiveCounts[$b] - $consecutiveCounts[$a];
        });

        // Step 5: Process each user's appointments
        $usedKeys = [];
        foreach ($appointmentsByUser as $userEmail => $appointments) {
            $lastEndTime = null;
            $currentGroup = [];
            foreach ($appointments as $appointment) {
                $appointmentStartTime = new DateTime($appointment['time']);
                if ($lastEndTime && $appointmentStartTime == $lastEndTime) {
                    $currentGroup[] = $appointment;
                    $lastEndTime = new DateTime($appointment['endTime']);
                } else {
                    if (!empty($currentGroup)) {
                        $this->processAppointmentGroup($currentGroup, $usedKeys, $logFile, $startTime);
                    }
                    $currentGroup = [$appointment];
                    $lastEndTime = new DateTime($appointment['endTime']);
                }
            }
            if (!empty($currentGroup)) {
                $this->processAppointmentGroup($currentGroup, $usedKeys, $logFile, $startTime);
            }
        }
    }

    private function processAppointmentGroup($appointments, &$usedKeys, $logFile, $startTime)
    {
        $firstAppointment = reset($appointments);
        $appointmentStartTime = new DateTime($firstAppointment['date'] . ' ' . $firstAppointment['time']);

        if ($appointmentStartTime != $startTime) {
            $this->log("Skipping group. Appointment start time " . $appointmentStartTime->format('Y-m-d H:i:s') . " does not match input start time " . $startTime->format('Y-m-d H:i:s'), $logFile);
            return;
        }

        $timeSlotKey = $firstAppointment['time'] . '-' . $firstAppointment['endTime'];

        if (!isset($usedKeys[$timeSlotKey])) {
            $pinCode = $this->generatePinCode();
            $usedKeys[$timeSlotKey] = $pinCode;

            $startDateTime = new DateTime($firstAppointment['datetime']);
            $startDateTime->modify('-10 minutes');

            $totalDuration = 0;
            foreach ($appointments as $appointment) {
                $totalDuration += $appointment['duration'];
            }

            $endDateTime = new DateTime($firstAppointment['datetime']);
            $endDateTime->modify('+' . $totalDuration . ' minutes');
            $endDateTime->modify('+10 minutes');

            // Create a timeSlotName based on the appointment time, not the buffer
            $appointmentStartTime = new DateTime($firstAppointment['time']);
            $appointmentEndTime = clone $endDateTime;

            $timeSlotName = $appointmentStartTime->format('gA') . '-' . $appointmentEndTime->format('gA') . ' ' . $appointmentStartTime->format('dM');

            $formattedStartDate = $startDateTime->format('Y-m-d\TH:i:s+08:00');
            $formattedEndDate = $endDateTime->format('Y-m-d\TH:i:s+08:00');

            $this->sendToIgloo($pinCode, $timeSlotName, $formattedStartDate, $formattedEndDate, $logFile);
        }

        foreach ($appointments as $appointment) {
            $this->updateAppointmentWithPin($appointment['id'], $usedKeys[$timeSlotKey], $logFile);
        }
    }

    private function generatePinCode()
    {
        return str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function sendToIgloo($pinCode, $timeSlotName, $startDate, $endDate, $logFile)
    {
        $deviceId = $this->config['igloo_device_id'];
        $bridgeId = $this->config['igloo_bridge_id'];
        $accessToken = $this->config['igloo_access_token'];

        $iglooUrl = str_replace(
            ['{deviceId}', '{bridgeId}'],
            [$deviceId, $bridgeId],
            $this->config['igloo_api_url']
        );

        $postData = [
            'jobType' => 4,
            'jobData' => [
                'accessName' => $timeSlotName,
                'pin' => $pinCode,
                'pinType' => 4,
                'startDate' => $startDate,
                'endDate' => $endDate
            ]
        ];

        $this->log("Igloo API Request Body: " . json_encode($postData), $logFile);

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
        $apiUrl = $this->config['acuity_api_url'] . '/' . $appointmentId . '?admin=true';
        $userId = $this->config['acuity_user_id'];
        $apiKey = $this->config['acuity_api_key'];

        $putData = ['notes' => $pinCode];

        $this->log("Acuity API Request Body: " . json_encode($putData), $logFile);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode("$userId:$apiKey"),
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($putData));

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            $this->log("Error updating appointment notes: $error", $logFile);
        } else {
            // $this->log("Acuity API Response: " . print_r(json_decode($response, true), true), $logFile);
        }

        curl_close($ch);
    }

    // Clean up response to log only relevant attributes
    private function logCleanedResponse($responseData, $logFile)
    {
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

        $this->log("Cleaned API Response: " . print_r($cleanedResponse, true), $logFile);
    }

    // Log function
    private function log($message, $logFile)
    {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "$timestamp - $message\n", FILE_APPEND);
    }
}
