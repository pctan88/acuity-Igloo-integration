# Appointment Processor with Igloo PIN Integration

This project is designed to process appointments fetched from Acuity Scheduling, assign PIN codes for Igloo systems, and handle consecutive appointment time slots efficiently. The system generates PINs for users based on their booked appointment slots and sends these to the Igloo API. Additionally, it updates appointments in Acuity Scheduling with the generated PINs.

## Features

- **Time Slot Processing**: The system processes appointments for a 1-hour time slot, starting 3 hours ahead of the current time.
- **Consecutive Appointment Handling**: If a user has consecutive appointments (up to 3 hours), the system handles these as a group, generating a single PIN for the entire duration.
- **PIN Generation**: A 4-digit PIN is generated for each group of appointments. This PIN is sent to the Igloo system and used to update the `notes` field in Acuity Scheduling.
- **Skipping Already Processed Appointments**: Appointments that already have a PIN assigned (indicated by a non-empty `notes` field) are skipped during processing.
- **Buffer Handling**: A 10-minute buffer is added before and after the appointment time when sending PINs to the Igloo system.

## Requirements

- **PHP**: The system is built with PHP, so a PHP runtime is required.
- **cURL**: Used for making API requests to Acuity and Igloo.
- **API Access**: Acuity and Igloo API keys must be set in the configuration file.

## Installation

1. Clone the repository:

    ```bash
    git clone https://github.com/your-repo/acuity-igloo-integration.git
    ```

2. Configure environment variables in `config.php`:

    ```php
    return [
        'acuity_api_url' => 'https://acuityscheduling.com/api/v1/appointments',
        'acuity_user_id' => 'your_acuity_user_id',
        'acuity_api_key' => 'your_acuity_api_key',
        'igloo_api_url' => 'https://api.igloo.com/pin',
        'igloo_token_url' => 'https://auth.igloohome.co/oauth2/token',
        'igloo_device_id' => 'your_igloo_device_id',
        'igloo_bridge_id' => 'your_igloo_bridge_id',
        'igloo_client_id' => 'your-client_id',
        'igloo_client_secret' => 'your-client_secret',
        'igloo_access_token' => 'your_igloo_access_token',
        'log_file' => '/path/to/your/logfile.log'
    ];
    ```

3. Set up a cron job:

    - Add the following cron job to run the PHP script every hour at 45 minutes past the hour:

    ```bash
    45 * * * * /usr/bin/php /path/to/project/public/appointmentProcessor.php >> /path/to/logfile.log 2>&1
    ```

## Usage

To manually run the cron job (for testing purposes), execute:

```bash
php public/appointmentProcessor.php
```

## Logs

All logs are written to the log file specified in the `config.php`. This includes:

- Errors during API requests.
- PIN generation and Igloo request data.
- Processing details for each user and appointment group.

## Igloo Token Renewal

The Igloo access token needs to be renewed daily. A separate cron job is provided to handle the token renewal automatically. The token renewal job will fetch a new access token and update the `config.php` file with the new token.

Example renewal cron job:

```bash
0 0 * * * /usr/bin/php /path/to/project/public/renew_token.php >> /path/to/logfile.log 2>&1
```

## Support

If you encounter any issues or have questions, feel free to open an issue on GitHub.
