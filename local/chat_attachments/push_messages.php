<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * Sends messages to Rocketchat.
 *
 * If you want to use on command line, use `php push_messages.php true`
 *
 * @TODO Limit messages based on the provided Timestamp
 */
$logToFile = true;
$cliScript = false;
if ((isset($argv)) && (isset($argv[1]))) {
    $cliScript = boolval($argv[1]);
    $logToFile = false;
}
if ((isset($argv)) && (isset($argv[2]))) {
    $logToFile = boolval($argv[2]);
}
if (isset($_GET['logging']) && ($_GET['logging'] === 'display')) {
    $logToFile = false;
}

define('CLI_SCRIPT', $cliScript);

set_time_limit(0);

require_once(dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'config.php');
require_once($CFG->libdir . DIRECTORY_SEPARATOR . 'filelib.php');
require_once(dirname(__FILE__) .DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'Attachment.php');
require_once(dirname(__FILE__) .DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'CurlUtility.php');
require_once(dirname(__FILE__) .DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'FileStorageUtility.php');
require_once(dirname(__FILE__) .DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'ReportingUtility.php');
// Uncomment if you want to disable emailing along with sending chat messages
//$CFG->noemailever = true;

$reporting = new ReportingUtility(dirname(__FILE__), $logToFile);
if (!$cliScript) {
    $reporting->printLineBreak = '<br>';
}
$url = get_config('local_chat_attachments', 'messaging_url');
$token = get_config('local_chat_attachments', 'messaging_token');
$machineIdFile = DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'machine-id';
$boxId = null;
if (file_exists($machineIdFile)) {
    $boxId = trim(file_get_contents($machineIdFile));
}
if ((!$boxId) || ($boxId === '')) {
    $reporting->error('Unable to retrieve the Box ID.');
    exit;
}
$reporting->saveResult('box_id', $boxId);
if ($url === '') {
    $reporting->error('No URL provided!');
    exit;
}
$reporting->info('Sending Requests to: ' . $url . '.');

$curl = new CurlUtility($url, $token, $boxId);
$fs = get_file_storage();
$systemContext = context_system::instance();
$storage = new FileStorageUtility($DB, $fs, $systemContext->id);

/**
 * Retrieve the last time we synced
 */
$reporting->info('Sending GET request to ' . $url . 'messageStatus.');
$lastSync = $curl->makeRequest('messageStatus', 'GET', []);
$reporting->saveResult('last_time_synced', $lastSync);
$reporting->saveResult('last_time_synced_pretty', date('F j, Y H:i:s', $lastSync));
/**
 * Create the course payload to send to the API
 */
$payload = [];
$courses = get_courses();
$studentRole = $DB->get_record('role', ['shortname' =>  'student']);
$teacherRole = $DB->get_record('role', ['shortname' =>  'teacher']);
$editingTeacherRole = $DB->get_record('role', ['shortname' =>  'editingteacher']);
foreach ($courses as $course) {
    $context = context_course::instance($course->id);
    $data = [
        'id'            =>  intval($course->id),
        'course_name'   =>  $course->fullname,
        'summary'       =>  $course->summary,
        'created_on'    =>  intval($course->timecreated),
        'updated_on'    =>  intval($course->timemodified),
        'students'      =>  [],
        'teachers'      =>  []
    ];
    $students = get_role_users($studentRole->id, $context);
    foreach ($students as $student) {
        $data['students'][] = [
            'id'            =>  intval($student->id),
            'username'      =>  $student->username,
            'first_name'    =>  $student->firstname,
            'last_name'     =>  $student->lastname,
            'email'         =>  $student->email,
            'last_accessed' =>  intval($student->lastaccess),
            'language'      =>  $student->lang
        ];
    }
    $teachers = get_role_users($teacherRole->id, $context);
    foreach ($teachers as $teacher) {
        $data['teachers'][] = [
            'id'            =>  intval($teacher->id),
            'username'      =>  $teacher->username,
            'first_name'    =>  $teacher->firstname,
            'last_name'     =>  $teacher->lastname,
            'email'         =>  $teacher->email,
            'last_accessed' =>  intval($teacher->lastaccess),
            'language'      =>  $teacher->lang
        ];
    }
    $editingTeachers = get_role_users($editingTeacherRole->id, $context);
    foreach ($editingTeachers as $teacher) {
        $data['teachers'][] = [
            'id'            =>  intval($teacher->id),
            'username'      =>  $teacher->username,
            'first_name'    =>  $teacher->firstname,
            'last_name'     =>  $teacher->lastname,
            'email'         =>  $teacher->email,
            'last_accessed' =>  intval($teacher->lastaccess),
            'language'      =>  $teacher->lang
        ];
    }
    $payload[] = $data;
}
$reporting->savePayload('course_rooster', $payload);

/**
 * Send the course payload to the API
 */
$reporting->info('Sending POST request to ' . $url . 'courseRosters.');
$curl->makeRequest('courseRosters', 'POST', json_encode($payload), null, true);
$reporting->info('The response was ' . $curl->responseCode . '.');

/**
 * Gather up the messages to send to the API
 */
$payload = [];
$attachments = [];
$query = 'SELECT m.id, m.conversationid, m.subject, m.fullmessagehtml, m.timecreated, s.id as sender_id, ' .
        's.username as sender_username, s.email as sender_email, r.id as recipient_id, r.username as recipient_username, ' .
        'r.email as recipient_email FROM {messages} AS m INNER JOIN {message_conversation_members} AS mcm ON m.conversationid=mcm.conversationid ' .
        'INNER JOIN {user} AS s ON mcm.userid = s.id INNER JOIN {user} AS r ON m.useridfrom = r.id ' .
        'WHERE m.useridfrom <> mcm.userid AND m.from_rocketchat = 0 ORDER BY m.timecreated ASC';
$chats = $DB->get_records_sql($query);
foreach ($chats as $chat) {
    $message = htmlspecialchars_decode($chat->fullmessagehtml);
    $attachment = null;
    if (Attachment::isAttachment($message)) {
        $attachment = new Attachment($message);
        $attachments[] = $attachment;
    }
    $data = [
        'id'                =>  intval($chat->id),
        'conversation_id'   =>  intval($chat->conversationid),
        'subject'           =>  $chat->subject,
        'message'           =>  $message,
        'sender'            =>  [
            'id'        =>  intval($chat->sender_id),
            'username'  =>  $chat->sender_username,
            'email'     =>  $chat->sender_email
        ],
        'recipient'            =>  [
            'id'        =>  intval($chat->recipient_id),
            'username'  =>  $chat->recipient_username,
            'email'     =>  $chat->recipient_email
        ],
        'attachment'    =>  null,
        'created_on'    =>  intval($chat->timecreated)
    ];
    if ($attachment) {
        $data['attachment'] = $attachment->toArray();
    }
    $payload[] = $data;
}
$reporting->savePayload('messages_to_send', $payload);

/**
 * Send the message payload to the API
 */
$reporting->info('Sending POST request to ' . $url . 'messages.');
$curl->makeRequest('messages', 'POST', json_encode($payload), null, true);
$reporting->info('The response was ' . $curl->responseCode . '.');
if ($curl->responseCode === 200) {
    $reporting->saveResult('total_messages_sent', count($chats));
} else {
    $reporting->saveResult('total_messages_sent', 0);
}

/**
 * Send each attachment to the API
 *
 */
$reporting->info('Sending attachments.');
$reporting->startProgress('Sending attachments', count($attachments));
foreach ($attachments as $attachment) {
    $filepath = $storage->retrieve($attachment->id, $attachment->filepath, $attachment->filename);
    if ((!$filepath) || (!file_exists($filepath))) {
        continue;
    }
    //Check if file exists.  If returns 404, then send file
    $curl->makeRequest('attachments/' . $attachment->id . '/exists', 'GET', []);
    if ($curl->responseCode === 404) {
        $response = $curl->makeRequest('attachments', 'POST', $attachment->toArray(), $filepath);
        if ($curl->responseCode === 200) {
            $reporting->reportProgressSuccess();
        } else {
            $reporting->reportProgressError();
        }
        $reporting->info('Sent attachment #' . $attachment->id . 'with status ' . $curl->responseCode . '.', 'send_attachments');
    } else {
        $reporting->info('Attachment #' . $attachment->id . ' previously sent.', 'send_attachments');
        $reporting->reportProgressSuccess();
    }
}
$reporting->saveResult('total_attachments_sent', $reporting->getProgressSuccess());
$reporting->saveResult('total_attachments_sent_failed', $reporting->getProgressError());
$reporting->stopProgress();
/**
 * Now request new messages from the API
 */
$reporting->info('Retrieving new messages.');
$reporting->info('Sending GET request to ' . $url . 'messages/' . $lastSync . '.');
$response = $curl->makeRequest('messages/' . $lastSync, 'GET', [], null, true);
$newMessages = json_decode($response);
$reporting->savePayload('messages_received', $newMessages);
$reporting->saveResult('total_messages_received', count($newMessages));
if (count($newMessages) == 0) {
    $reporting->info('There are no new messages.');
    $reporting->info('Script Complete!');
    exit();
}
$reporting->info('Total Messages Received: ' . number_format(count($newMessages)) . '.');

/**
 * For each message, retrieve the attachment, save it to moodle, and save the new message.
 */
$reporting->startProgress('Saving retrieved messages & attachments', count($newMessages));
foreach ($newMessages as $message) {
    $content = $message->message;
    $html = htmlspecialchars_decode($message->message);
    if (Attachment::isAttachment($content)) {
        $attachment = new Attachment($content);
        /**
         * Download and save the attachment
         */
        if ($attachment->id <= 0) {
            // cannot get the attachment.  Move along.
            $reporting->reportProgressError();
            continue;
        }

        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $attachment->filename;
        $downloaded = $curl->downloadFile('attachments/' . $attachment->id, $tempPath);
        if (!$downloaded) {
            $reporting->error('Unable to download attachment # ' . $attachment->id . '.', 'receive_message');
            $reporting->reportProgressError();
            continue;
        }
        $reporting->info('Received attachment #' . $attachment->id . '.', 'receive_message');
        $attachment->id = $storage->store($attachment->filename, $tempPath);
        $content = $attachment->toString();
    }
    // Location in messages/classes/api.php
    $message = \core_message\api::send_message_to_conversation(
        $message->sender->id,
        $message->conversation_id,
        htmlspecialchars($content),
        FORMAT_HTML
    );
    $DB->execute('UPDATE {messages} SET from_rocketchat = 1 WHERE id = ?', [$message->id]);
    $reporting->reportProgressSuccess();
}
$reporting->saveResult('total_attachments_received', $reporting->getProgressSuccess());
$reporting->saveResult('total_attachments_received_failed', $reporting->getProgressError());
$reporting->stopProgress();

$reporting->info('Checking if the API is missing attachments.');
$reporting->info('Sending POST request to ' . $url . 'attachments/missing.');
$response = $curl->makeRequest('attachments/missing', 'POST', [], null, true);
$missing = json_decode($response);
$reporting->savePayload('missing_attachments', $missing);
$reporting->saveResult('total_missing_attachments_requested', count($missing));
if ((!$response) || (count($missing) === 0)) {
    /**
     * Script finished
     */
    $reporting->info('There are no missing attachments.');
    $reporting->info('Script Complete!');
    exit();
}
$sent = 0;
$errored = 0;
$reporting->startProgress('Uploading missing attachments', count($missing));
foreach ($missing as $id) {
    $file = $storage->findById($id);
    if (!$file) {
        $reporting->error('Unable to find missing attachment with id: ' . $id . '.', 'missing_attachments');
        $reporting->reportProgressError();
        continue;
    }
    $filepath = $storage->retrieve($id, $file->filepath, $file->filename);
    if ((!$filepath) || (!file_exists($filepath))) {
        $reporting->error('Unable to move the attachment with id: ' . $id . '.', 'missing_attachments');
        $reporting->reportProgressError();
        continue;
    }
    $parts = explode('/', $file->mimetype);
    $type = $parts[0];
    if ($type === 'image') {
        $type = 'photo';
    }
    $data = [
        'type'      =>  $type,
        'id'        =>  $id,
        'filepath'  =>  $file->filepath,
        'filename'  =>  $file->filename
    ];
    $response = $curl->makeRequest('attachments', 'POST', $data, $filepath);
    if ($curl->responseCode === 200) {
        $reporting->reportProgressSuccess();
    } else {
        $reporting->reportProgressError();
    }
    $reporting->info('Sent attachment #' . $id . ' with status ' . $curl->responseCode . '.', 'missing_attachments');
}
$reporting->saveResult('total_missing_attachments_sent', $reporting->getProgressSuccess());
$reporting->saveResult('total_missing_attachments_failed_sending', $reporting->getProgressError());
$reporting->stopProgress();
/**
 * Script finished
 */
$reporting->info('Script Complete!');
