# Auto PIN Generator for Acuity & Igloo

This project is an automated solution that integrates with the Acuity Scheduling and Igloo APIs to generate 4-digit PINs for secure access control. The PIN is automatically generated for each scheduled appointment within the next hour and updated in both Acuity’s appointment notes and the Igloo system for managing smart locks.

## Features
- **Acuity Scheduling Integration**: Fetches upcoming appointments and updates the appointment's notes field with the generated PIN.
- **Igloo API Integration**: Generates a 4-digit PIN for Igloo smart locks and registers it with the lock via the Igloo API.
- **Customizable Time Frame**: By default, the script generates a PIN for appointments scheduled in the next hour.

## Tech Stack
- **PHP**: Core language used for the backend.
- **cURL**: For making API requests to Acuity and Igloo APIs.
- **Acuity Scheduling API**: Used to retrieve and update appointments.
- **Igloo API**: Used to generate and manage PINs for smart locks.

## Project Structure

project-root/ 
│ ├── config/ 
  │ └── config.php # Configuration file (API credentials, log file path, etc.) 
│ ├── logs/ 
  │ └── cron.log # Log file for tracking API responses and errors 
│ ├── src/ 
  │ └── CronJob.php # Core class to handle the automation logic 
│ ├── public/ 
  │ └── cronjob.php # Entry point to run the script 
│ └── README.md # Project description and setup instructions
