<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Jmrashed\Zkteco\Lib\ZKTeco;

class FetchAttendance extends Command
{
    protected $signature = 'attendance:fetch';
    protected $description = 'Fetch attendance from ZKTeco and send to server via API';

    public function handle()
    {
        // Step 1: Fetch attendance data from ZKTeco
        $attendanceData = $this->fetchAttendanceFromZKTeco();

        // Step 2: Send attendance data to server via API
        $this->sendAttendanceToServer($attendanceData);
    }

    /**
     * Fetch attendance data from ZKTeco device.
     *
     * @return array
     */
    private function fetchAttendanceFromZKTeco()
    {
        $this->info('Fetching attendance from ZKTeco...');

        // Initialize ZKTeco object and connect to the device
        $zk = new ZKTeco('192.168.1.201');
        $zk->connect();

        // Get attendance data from the device
        $attendanceData = $zk->getAttendance();

        // Close connection to the device
        $zk->disconnect();

        // Get today's date
        $todayDate = date('Y-m-d');

        // Filter attendance records for today
        $todayRecords = [];
        foreach ($attendanceData as $record) {
            // Extract the date from the timestamp
            $recordDate = substr($record['timestamp'], 0, 10);

            // Check if the date matches today's date
            if ($recordDate === $todayDate) {
                $todayRecords[] = $record;
            }
        }

        return $todayRecords;
    }

    /**
     * Send attendance data to server via API.
     *
     * @param array $attendanceData
     * @return void
     */
    private function sendAttendanceToServer($attendanceData)
    {

        // loop through each attendance record and store attendance table in database
        foreach ($attendanceData as $record) {
            $attendance = new Attendance();
            $attendance->uid = $record['uid'];
            $attendance->employee_id = $record['id'];
            $attendance->state = $record['state'];
            $attendance->timestamp = $record['timestamp'];
            $attendance->type = $record['type'];
            $attendance->save();
        }

        Log::info($attendanceData);
        // Process attendance data
        $processedData = $this->processAttendanceData($attendanceData);

        foreach ($processedData as $userId => $data) {
            // Find existing attendance log based on uid, employee_id, and date
            $attendanceLog = AttendanceLog::where('uid', $userId)
                ->where('date', date('Y-m-d'))
                ->first();

            // If attendance log exists, update it; otherwise, create a new one
            if ($attendanceLog) {
                $attendanceLog->min_time = date('H:i:s', $data['min_time']);
                $attendanceLog->max_time = date('H:i:s', $data['max_time']);
                $attendanceLog->save();
            } else {
                // Create a new attendance log
                $attendanceLog = new AttendanceLog();
                $attendanceLog->uid = $userId;
                $attendanceLog->employee_id = $attendanceData[0]['id'];
                $attendanceLog->date = date('Y-m-d');
                $attendanceLog->min_time = date('H:i:s', $data['min_time']);
                $attendanceLog->max_time = date('H:i:s', $data['max_time']);
                $attendanceLog->save();
            }
        }

        $this->info('Sending attendance to server via API...');
        // Here you would implement the logic to send attendance data to the server via API
        // Example: Use GuzzleHttp or other HTTP client to make API requests
        // Example:
        // $httpClient = new \GuzzleHttp\Client();
        // $response = $httpClient->post('https://example.com/api/attendance', [
        //     'json' => $attendanceData,
        // ]);
        // $responseData = $response->getBody()->getContents();
        // Handle response as per your application requirements
        $this->info('Attendance sent successfully.');
    }

    private function processAttendanceData($attendanceData)
    {
        // Initialize an array to store processed data
        $processedData = [];

        // Loop through each attendance record
        foreach ($attendanceData as $record) {
            // Extract user ID and timestamp from the record
            $userId = $record['uid'];
            $timestamp = strtotime($record['timestamp']); // Convert timestamp to UNIX timestamp

            // If user ID is not yet present in processed data, initialize it
            if (!isset($processedData[$userId])) {
                $processedData[$userId] = [
                    'min_time' => $timestamp,
                    'max_time' => $timestamp,
                ];
            } else {
                // Update minimum and maximum timestamps if necessary
                if ($timestamp < $processedData[$userId]['min_time']) {
                    $processedData[$userId]['min_time'] = $timestamp;
                }
                if ($timestamp > $processedData[$userId]['max_time']) {
                    $processedData[$userId]['max_time'] = $timestamp;
                }
            }
        }

        Log::info($processedData);
        return $processedData;
    }

}
