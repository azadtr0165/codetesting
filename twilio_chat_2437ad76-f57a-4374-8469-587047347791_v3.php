<?php
// --- Output buffering to keep headers available for redirect ---
ob_start();

// Initialize Joomla
$startTime = microtime(1);
$startMem = memory_get_usage();
define('_JEXEC', 1);
if (!defined('_JDEFINES')) {
    define('JPATH_BASE', __DIR__ . '/../api/');
    require_once JPATH_BASE . '/includes/defines.php';
}
require_once JPATH_BASE . '/includes/framework.php';
JDEBUG ? JProfiler::getInstance('Application')->setStart($startTime, $startMem)->mark('afterLoad') : null;
$app = JFactory::getApplication('site');
use Joomla\CMS\Factory;

session_start();

// ---------- Helpers ----------
function normalize_us_e164($n) {
    $n = preg_replace('/\D/', '', (string)$n);
    if (substr($n, 0, 1) === '1') { $n = substr($n, 1); }
    if (strlen($n) !== 10) { return false; }
    return '+1' . $n;
}

function current_script_absolute_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    $file   = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return $scheme . '://' . $host . ($path ? $path : '') . '/' . $file;
}

// ---------- Scheduled SMS DB Helpers ----------

function ensure_scheduled_sms_table() {
    $db = JFactory::getDbo();
    $db->setQuery("CREATE TABLE IF NOT EXISTS `#__twilio_scheduled_sms` (
        `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `from_number`  VARCHAR(20)      NOT NULL,
        `to_number`    VARCHAR(20)      NOT NULL,
        `message_body` TEXT             NOT NULL,
        `scheduled_at` DATETIME         NOT NULL COMMENT 'Stored as UTC',
        `status`       ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
        `twilio_sid`   VARCHAR(64)      DEFAULT NULL,
        `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_status_scheduled` (`status`, `scheduled_at`),
        KEY `idx_to_number` (`to_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->execute();
    return $db;
}

function get_pending_scheduled($to_number) {
    $db = JFactory::getDbo();
    $q  = $db->getQuery(true)
        ->select(['id', 'from_number', 'to_number', 'message_body', 'scheduled_at'])
        ->from($db->quoteName('#__twilio_scheduled_sms'))
        ->where($db->quoteName('to_number') . ' = ' . $db->quote($to_number))
        ->where($db->quoteName('status')    . ' = ' . $db->quote('pending'))
        ->order($db->quoteName('scheduled_at') . ' ASC');
    $db->setQuery($q);
    return $db->loadAssocList();
}

function validate_schedule_time_et($et_str) {
    try {
        $dt = new DateTime($et_str, new DateTimeZone('America/New_York'));
    } catch (Exception $e) {
        return [false, 'Invalid date/time format'];
    }
    if ($dt->format('w') == '0') return [false, 'Cannot schedule on Sunday'];
    $hour = (int)$dt->format('H');
    $min  = (int)$dt->format('i');
    if ($hour < 8 || $hour > 20 || ($hour == 20 && $min > 0))
        return [false, 'Time must be between 8:00 AM and 8:00 PM ET'];
    if (!in_array($min, [0, 15, 30, 45]))
        return [false, 'Time must be in 15-minute intervals'];
    $now = new DateTime('now', new DateTimeZone('America/New_York'));
    $now->modify('+10 minutes');
    if ($dt <= $now) return [false, 'Scheduled time must be at least 10 minutes in the future'];
    return [true, null];
}

function et_to_utc_str($et_str) {
    $dt = new DateTime($et_str, new DateTimeZone('America/New_York'));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

// ---------- Config / Twilio ----------
$config                    = JFactory::getConfig();
$twilio_sid                = $config->get('twilio_sid');
$twilio_token              = $config->get('twilio_token');
$twilio_from_sales_phone   = $config->get('twilio_from_sales_phone');
$twilio_from_support_phone = $config->get('twilio_from_support_phone');

$account_sid = $twilio_sid;
$auth_token  = $twilio_token;

$hardcoded_api_key   = '5ade80ef-179d-457f-bbd0-6c2ee5c7418c';
$twilio_api_base_url = 'https://api.twilio.com/2010-04-01/Accounts/' . $account_sid;

$twilio_numbers = [
    'Pre Sales'  => $twilio_from_sales_phone,
    'Post Sales' => $twilio_from_support_phone,
];

if (!empty($_SESSION['from_phone'])) {
    $twilio_numbers = ['Installs' => $_SESSION['from_phone']];
} else {
    foreach ($twilio_numbers as $label => $num) {
        $norm = normalize_us_e164($num);
        if ($norm) { $twilio_numbers[$label] = $norm; }
    }
}

// ---------- Action handling ----------
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

try {
    switch ($action) {

        // ------------------------------------------------------------------ startapp
        case 'startapp':
            $phone   = isset($_GET['phone'])      ? $_GET['phone']      : '';
            $name    = isset($_GET['name'])        ? $_GET['name']       : '';
            $apikey  = isset($_GET['apikey'])      ? $_GET['apikey']     : '';
            $from_raw= isset($_GET['from_phone'])  ? $_GET['from_phone'] : '';

            if ($apikey !== $hardcoded_api_key) throw new Exception('Invalid API Key');

            $phone = preg_replace('/\D/', '', $phone);
            if (substr($phone, 0, 1) === '1') { $phone = substr($phone, 1); }
            if (strlen($phone) !== 10) throw new Exception('Invalid phone number format. Please provide a valid 10-digit US phone number.');
            $phone = '+1' . $phone;

            $from_norm = null;
            if ($from_raw !== '') {
                $from_norm = normalize_us_e164($from_raw);
                if (!$from_norm) throw new Exception('Invalid from_phone format. Provide a 10-digit US number.');
                $_SESSION['from_phone'] = $from_norm;
            } else {
                unset($_SESSION['from_phone']);
            }

            $_SESSION['phone'] = $phone;
            $_SESSION['name']  = $name;
            unset($_SESSION['last_message_sid'], $_SESSION['last_from_number']);

            $redirectUrl = current_script_absolute_url();
            session_write_close();

            if (!headers_sent()) {
                header('Location: ' . $redirectUrl, true, 303);
                exit();
            } else {
                echo '<!doctype html><html><head><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl, ENT_QUOTES) . '"></head><body>';
                echo '<script>location.href=' . json_encode($redirectUrl) . ';</script>';
                echo '</body></html>';
                exit();
            }
            break;

        // ------------------------------------------------------------------ sendmessage
        case 'sendmessage':
            if (!isset($_SESSION['phone']) || !isset($_SESSION['name']))
                throw new Exception('Session expired. Please start the app again.');

            $message_body = isset($_POST['message'])     ? $_POST['message']     : '';
            $from_label   = isset($_POST['from_number']) ? $_POST['from_number'] : '';

            if (!isset($twilio_numbers[$from_label])) throw new Exception('Invalid from number selected.');
            $from_number = $twilio_numbers[$from_label];
            $_SESSION['last_from_number'] = $from_label;

            $media_url = [];
            if (isset($_FILES['media']) && $_FILES['media']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/';
                if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
                $filename    = uniqid('mms_', true) . '_' . basename($_FILES['media']['name']);
                $target_file = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['media']['tmp_name'], $target_file)) {
                    $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $media_url[] = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/' . $upload_dir . $filename;
                } else {
                    throw new Exception('Failed to move uploaded file.');
                }
            }

            if (trim($message_body) === '' && empty($media_url)) throw new Exception('Cannot send an empty message.');

            $post_fields = [
                'From' => $from_number,
                'To'   => $_SESSION['phone'],
                'Body' => ($message_body !== '' ? $message_body : ' '),
            ];
            if (!empty($media_url)) {
                foreach ($media_url as $i => $url) { $post_fields['MediaUrl[' . $i . ']'] = $url; }
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $twilio_api_base_url . '/Messages.json');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $account_sid . ':' . $auth_token);
            $response    = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($response === false) { $err = curl_error($ch); curl_close($ch); throw new Exception('cURL Error: ' . $err); }
            curl_close($ch);

            if ($http_status == 201) {
                if (!empty($media_url)) {
                    foreach ($media_url as $url) {
                        $path = parse_url($url, PHP_URL_PATH);
                        $file = $_SERVER['DOCUMENT_ROOT'] . $path;
                        if ($file && file_exists($file)) { @unlink($file); }
                    }
                }
                echo json_encode(['status' => 'success']);
            } else {
                $rd = json_decode($response, true);
                throw new Exception('Twilio API Error: ' . ($rd['message'] ?? 'An error occurred.'));
            }
            exit();

        // ------------------------------------------------------------------ schedulemessage
        case 'schedulemessage':
            if (!isset($_SESSION['phone']) || !isset($_SESSION['name']))
                throw new Exception('Session expired. Please start the app again.');

            $message_body = isset($_POST['message'])         ? trim($_POST['message'])         : '';
            $from_label   = isset($_POST['from_number'])     ? $_POST['from_number']           : '';
            $scheduled_et = isset($_POST['scheduled_at_et']) ? trim($_POST['scheduled_at_et']) : '';

            if ($message_body === '') throw new Exception('Message cannot be empty.');
            if (!isset($twilio_numbers[$from_label])) throw new Exception('Invalid from number selected.');

            [$valid, $err] = validate_schedule_time_et($scheduled_et);
            if (!$valid) throw new Exception($err);

            $from_number = $twilio_numbers[$from_label];
            $to_number   = $_SESSION['phone'];
            $utc_str     = et_to_utc_str($scheduled_et);

            $db  = ensure_scheduled_sms_table();
            $row = (object)[
                'from_number'  => $from_number,
                'to_number'    => $to_number,
                'message_body' => $message_body,
                'scheduled_at' => $utc_str,
            ];
            $db->insertObject('#__twilio_scheduled_sms', $row);

            echo json_encode(['status' => 'success', 'id' => $db->insertid()]);
            exit();

        // ------------------------------------------------------------------ run_scheduled (cron)
        case 'run_scheduled':
            $apikey = isset($_REQUEST['apikey']) ? $_REQUEST['apikey'] : '';
            if ($apikey !== $hardcoded_api_key) {
                http_response_code(403);
                echo json_encode(['error' => 'Unauthorized']);
                exit();
            }

            $db = ensure_scheduled_sms_table();
            $q  = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__twilio_scheduled_sms'))
                ->where($db->quoteName('status')       . ' = '  . $db->quote('pending'))
                ->where($db->quoteName('scheduled_at') . ' <= UTC_TIMESTAMP()');
            $db->setQuery($q);
            $due = $db->loadObjectList();

            $results = [];
            foreach ($due as $msg) {
                $post_fields = [
                    'From' => $msg->from_number,
                    'To'   => $msg->to_number,
                    'Body' => $msg->message_body,
                ];
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $twilio_api_base_url . '/Messages.json');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, $account_sid . ':' . $auth_token);
                $response    = curl_exec($ch);
                $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($response !== false && $http_status == 201) {
                    $rd         = json_decode($response, true);
                    $twilio_sid = $rd['sid'] ?? null;
                    $upd = $db->getQuery(true)
                        ->update($db->quoteName('#__twilio_scheduled_sms'))
                        ->set($db->quoteName('status')     . ' = ' . $db->quote('sent'))
                        ->set($db->quoteName('twilio_sid') . ' = ' . $db->quote($twilio_sid))
                        ->where($db->quoteName('id')        . ' = ' . (int)$msg->id);
                    $db->setQuery($upd)->execute();
                    $results[] = ['id' => $msg->id, 'status' => 'sent', 'sid' => $twilio_sid];
                } else {
                    $upd = $db->getQuery(true)
                        ->update($db->quoteName('#__twilio_scheduled_sms'))
                        ->set($db->quoteName('status') . ' = ' . $db->quote('failed'))
                        ->where($db->quoteName('id')    . ' = ' . (int)$msg->id);
                    $db->setQuery($upd)->execute();
                    $results[] = ['id' => $msg->id, 'status' => 'failed'];
                }
            }

            echo json_encode(['status' => 'ok', 'processed' => count($due), 'results' => $results]);
            exit();

        // ------------------------------------------------------------------ cancel_scheduled
        case 'cancel_scheduled':
            if (!isset($_SESSION['phone'])) throw new Exception('Session expired.');

            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id <= 0) throw new Exception('Invalid message ID.');

            $db = JFactory::getDbo();
            $q  = $db->getQuery(true)
                ->select('id')
                ->from($db->quoteName('#__twilio_scheduled_sms'))
                ->where($db->quoteName('id')        . ' = ' . $id)
                ->where($db->quoteName('to_number') . ' = ' . $db->quote($_SESSION['phone']))
                ->where($db->quoteName('status')    . ' = ' . $db->quote('pending'));
            $db->setQuery($q);
            if (!$db->loadResult()) throw new Exception('Scheduled message not found or already processed.');

            $upd = $db->getQuery(true)
                ->update($db->quoteName('#__twilio_scheduled_sms'))
                ->set($db->quoteName('status') . ' = ' . $db->quote('cancelled'))
                ->where($db->quoteName('id')   . ' = ' . $id);
            $db->setQuery($upd)->execute();

            echo json_encode(['status' => 'success']);
            exit();

        // ------------------------------------------------------------------ edit_scheduled
        case 'edit_scheduled':
            if (!isset($_SESSION['phone'])) throw new Exception('Session expired.');

            $id           = isset($_POST['id'])              ? (int)$_POST['id']              : 0;
            $message_body = isset($_POST['message'])         ? trim($_POST['message'])         : '';
            $scheduled_et = isset($_POST['scheduled_at_et']) ? trim($_POST['scheduled_at_et']) : '';

            if ($id <= 0)           throw new Exception('Invalid message ID.');
            if ($message_body === '') throw new Exception('Message cannot be empty.');

            [$valid, $err] = validate_schedule_time_et($scheduled_et);
            if (!$valid) throw new Exception($err);

            $db = JFactory::getDbo();
            $q  = $db->getQuery(true)
                ->select('id')
                ->from($db->quoteName('#__twilio_scheduled_sms'))
                ->where($db->quoteName('id')        . ' = ' . $id)
                ->where($db->quoteName('to_number') . ' = ' . $db->quote($_SESSION['phone']))
                ->where($db->quoteName('status')    . ' = ' . $db->quote('pending'));
            $db->setQuery($q);
            if (!$db->loadResult()) throw new Exception('Scheduled message not found or already processed.');

            $utc_str = et_to_utc_str($scheduled_et);
            $upd = $db->getQuery(true)
                ->update($db->quoteName('#__twilio_scheduled_sms'))
                ->set($db->quoteName('message_body') . ' = ' . $db->quote($message_body))
                ->set($db->quoteName('scheduled_at') . ' = ' . $db->quote($utc_str))
                ->where($db->quoteName('id')         . ' = ' . $id);
            $db->setQuery($upd)->execute();

            echo json_encode(['status' => 'success']);
            exit();

        // ------------------------------------------------------------------ checkmessage
        case 'checkmessage':
            if (!isset($_SESSION['phone']) || !isset($_SESSION['name']))
                throw new Exception('Session expired. Please start the app again.');

            $phone_number     = $_SESSION['phone'];
            $last_message_sid = isset($_SESSION['last_message_sid']) ? $_SESSION['last_message_sid'] : null;

            try {
                $all_messages = [];

                foreach ($twilio_numbers as $label => $twilio_number) {
                    // Customer -> Twilio
                    $ch  = curl_init();
                    $url = $twilio_api_base_url . '/Messages.json?PageSize=20&To=' . urlencode($twilio_number) . '&From=' . urlencode($phone_number);
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_USERPWD, $account_sid . ':' . $auth_token);
                    $resp = curl_exec($ch);
                    if ($resp === false) { $e = curl_error($ch); curl_close($ch); throw new Exception('cURL Error: ' . $e); }
                    $messages = json_decode($resp, true);
                    curl_close($ch);
                    if (!isset($messages['messages'])) throw new Exception('Unexpected response format from Twilio API.');
                    foreach ($messages['messages'] as $msg) {
                        $msg['from_label'] = 'Customer';
                        $all_messages[] = $msg;
                    }

                    // Twilio -> Customer
                    $ch  = curl_init();
                    $url = $twilio_api_base_url . '/Messages.json?PageSize=20&From=' . urlencode($twilio_number) . '&To=' . urlencode($phone_number);
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_USERPWD, $account_sid . ':' . $auth_token);
                    $resp = curl_exec($ch);
                    if ($resp === false) { $e = curl_error($ch); curl_close($ch); throw new Exception('cURL Error: ' . $e); }
                    $messages = json_decode($resp, true);
                    curl_close($ch);
                    if (!isset($messages['messages'])) throw new Exception('Unexpected response format from Twilio API.');
                    foreach ($messages['messages'] as $msg) {
                        $msg['from_label'] = $label;
                        $all_messages[] = $msg;
                    }
                }

                usort($all_messages, function ($a, $b) {
                    $aTime = strtotime($a['date_sent'] ?? $a['date_created'] ?? 'now');
                    $bTime = strtotime($b['date_sent'] ?? $b['date_created'] ?? 'now');
                    return $aTime <=> $bTime;
                });

                $new_messages = $all_messages;
                if ($last_message_sid !== null) {
                    $index = null;
                    foreach ($all_messages as $k => $m) {
                        if (($m['sid'] ?? '') === $last_message_sid) { $index = $k; break; }
                    }
                    if ($index !== null) { $new_messages = array_slice($all_messages, $index + 1); }
                }

                if (!empty($new_messages)) {
                    $_SESSION['last_message_sid'] = end($all_messages)['sid'];
                }

                $message_array = [];
                foreach ($new_messages as $message) {
                    $dateSent = $message['date_sent'] ?? $message['date_created'] ?? null;
                    $msg = [
                        'sid'        => $message['sid'],
                        'from'       => $message['from'],
                        'to'         => $message['to'],
                        'body'       => $message['body'],
                        'dateSent'   => $dateSent,
                        'media'      => [],
                        'from_label' => $message['from_label'],
                        'status'     => $message['status'] ?? null,
                    ];
                    if (!empty($message['num_media'])) {
                        $ch = curl_init();
                        $url = $twilio_api_base_url . '/Messages/' . $message['sid'] . '/Media.json';
                        curl_setopt($ch, CURLOPT_URL, $url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_USERPWD, $account_sid . ':' . $auth_token);
                        $media_response = curl_exec($ch);
                        curl_close($ch);
                        if ($media_response !== false) {
                            $media_list = json_decode($media_response, true);
                            $items = $media_list['media'] ?? $media_list['media_list'] ?? [];
                            foreach ($items as $item) {
                                $msg['media'][] = $twilio_api_base_url . '/Messages/' . $message['sid'] . '/Media/' . $item['sid'] . '?AuthToken=' . urlencode($auth_token);
                            }
                        }
                    }
                    $message_array[] = $msg;
                }

                // Fetch pending scheduled messages for this conversation
                ensure_scheduled_sms_table();
                $scheduled_rows = get_pending_scheduled($phone_number);
                $scheduled_out  = [];
                foreach ($scheduled_rows as $row) {
                    $dt = new DateTime($row['scheduled_at'], new DateTimeZone('UTC'));
                    $scheduled_out[] = [
                        'id'           => (int)$row['id'],
                        'from_number'  => $row['from_number'],
                        'to_number'    => $row['to_number'],
                        'body'         => $row['message_body'],
                        'scheduled_at' => $dt->format('c'), // ISO 8601 UTC
                    ];
                }

                $status = !empty($message_array) ? 'success' : 'no_new_messages';
                echo json_encode(['status' => $status, 'messages' => $message_array, 'scheduled' => $scheduled_out]);
                exit();

            } catch (Exception $e) {
                throw new Exception('Error fetching messages: ' . $e->getMessage());
            }
            break;

        // ------------------------------------------------------------------ default (UI)
        default:
            if (!isset($_SESSION['phone']) || !isset($_SESSION['name']))
                throw new Exception('Session expired. Please start the app again.');
            ?>
<!DOCTYPE html>
<html>
<head>
    <title>SMS Chat with <?php echo htmlspecialchars($_SESSION['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dayjs@1/dayjs.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dayjs@1/plugin/utc.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dayjs@1/plugin/timezone.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dayjs@1/plugin/relativeTime.js"></script>
    <script>
        dayjs.extend(dayjs_plugin_utc);
        dayjs.extend(dayjs_plugin_timezone);
        dayjs.extend(dayjs_plugin_relativeTime);
        dayjs.tz.setDefault("America/New_York");
    </script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        body { margin:0; padding:0; }
        .chat-container { display:flex; flex-direction:column; height:100vh; background-color:#f7f7f7; }
        .chat-header { padding:10px; background-color:#1D4ED8; color:white; }
        .chat-messages { flex:1; padding:10px; overflow-y:scroll; }
        .chat-input { padding:10px; background-color:#e5e7eb; }
        .message { margin-bottom:10px; max-width:70%; word-wrap:break-word; display:flex; flex-direction:column; position:relative; }
        .message.sent { margin-left:auto; align-items:flex-end; }
        .message.received { margin-right:auto; align-items:flex-start; }
        .message .text { padding:10px; border-radius:5px; background-color:#ffffff; position:relative; }
        .message.sent.pre-sales .text { background-color:#cce5ff; }
        .message.sent.post-sales .text { background-color:#ffe5b4; }
        .message.sent .text { background-color:#dcf8c6; }
        .message.received .text { background-color:#ffffff; }
        .message .time { font-size:.8em; color:#666; margin-top:2px; }
        .message img { max-width:250px; height:auto; cursor:pointer; }
        #chat-messages { overflow-y:auto; }
        #reload-button { display:none; text-align:center; margin-bottom:10px; }
        #reload-button button { background-color:#4CAF50; color:white; padding:10px 20px; border:none; border-radius:5px; cursor:pointer; }
        #reload-button button:hover { background-color:#45a049; }
        .chat-input form { display:flex; flex-wrap:wrap; align-items:center; }
        .chat-input .form-group { display:flex; align-items:center; margin-bottom:5px; flex-wrap:wrap; }
        .chat-input .form-group input[type="text"] { flex-grow:1; }
        .chat-input .form-group button, .chat-input .form-group select { flex-shrink:0; }
        @media (max-width: 600px) {
            .chat-input form { flex-direction:column; align-items:stretch; }
            .chat-input .form-group { width:100%; }
            .chat-input .form-group label { width:100%; margin-bottom:5px; }
            .chat-input .form-group button, .chat-input .form-group select, .chat-input .form-group input[type="text"] { width:100%; margin-bottom:5px; }
            .chat-input .form-group button { margin-bottom:0; }
        }
        .error-indicator { color:red; margin-left:5px; font-size:1.2em; margin-top:2px; }
        #image-preview { margin-top:10px; position:relative; display:inline-block; }
        #image-preview img { max-width:100px; max-height:100px; }
        #remove-image { position:absolute; top:0; right:0; background:rgba(255,255,255,0.7); border:none; font-size:16px; cursor:pointer; }

        /* Schedule picker */
        #schedule-picker {
            display:none; position:absolute; bottom:52px; right:0;
            background:#fff; border:1px solid #d1d5db; border-radius:10px;
            padding:16px; box-shadow:0 4px 20px rgba(0,0,0,.18); z-index:200; width:280px;
        }
        #schedule-picker h4 { margin:0 0 12px; font-size:14px; font-weight:600; color:#111; }
        #schedule-picker label { display:block; font-size:11px; font-weight:600; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:.5px; }
        #schedule-picker select { width:100%; border:1px solid #d1d5db; border-radius:6px; padding:7px 8px; font-size:13px; margin-bottom:10px; }

        /* Scheduled messages section */
        .scheduled-section-divider {
            text-align:center; padding:6px 0; color:#9ca3af;
            font-size:11px; font-weight:600; letter-spacing:1px;
            border-top:1px solid #e5e7eb; margin-top:8px;
        }
        .message.scheduled-item .text {
            background-color:#eff6ff !important;
            border:1px dashed #93c5fd;
        }
        .sched-actions { display:flex; gap:6px; margin-top:8px; }
        .sched-actions button {
            font-size:11px; padding:2px 10px;
            border-radius:4px; cursor:pointer; border:1px solid #d1d5db; background:#fff;
        }
        .sched-actions .btn-edit  { color:#1D4ED8; }
        .sched-actions .btn-cancel{ color:#dc2626; }
        .sched-actions button:hover { background:#f3f4f6; }

        /* Edit modal overlay */
        #edit-modal-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.5); z-index:300;
            align-items:center; justify-content:center;
        }
        #edit-modal-overlay.open { display:flex; }
        #edit-modal-box {
            background:#fff; border-radius:10px; padding:24px;
            width:340px; max-width:92vw; box-shadow:0 8px 32px rgba(0,0,0,.2);
        }
        #edit-modal-box h4 { margin:0 0 16px; font-size:15px; font-weight:600; }
        #edit-modal-box label { display:block; font-size:11px; font-weight:600; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:.5px; }
        #edit-modal-box textarea, #edit-modal-box select {
            width:100%; border:1px solid #d1d5db; border-radius:6px;
            padding:7px 8px; font-size:13px; margin-bottom:12px; box-sizing:border-box;
        }
        #edit-modal-box textarea { resize:vertical; }
    </style>
</head>
<body>
    <div class="chat-container" x-data="{ modalOpen: false, modalImageUrl: '' }">
        <div class="chat-header">
            <h3 class="text-lg font-semibold"><?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo htmlspecialchars($_SESSION['phone']); ?>)</h3>
        </div>
        <div class="chat-messages" id="chat-messages"></div>
        <div id="reload-button">
            <button id="check-messages-btn">Check for new messages</button>
        </div>
        <div class="chat-input">
            <form id="message-form" enctype="multipart/form-data">
                <div class="form-group flex items-center mr-2">
                    <label for="from_number" class="mr-2 font-semibold">From:</label>
                    <select name="from_number" id="from_number" class="border rounded px-2 py-1">
                        <?php
                        $firstLabel = array_key_first($twilio_numbers);
                        foreach ($twilio_numbers as $label => $number) {
                            $selected = '';
                            if (isset($_SESSION['last_from_number']) && $_SESSION['last_from_number'] == $label) {
                                $selected = 'selected';
                            } elseif (!isset($_SESSION['last_from_number']) && $label == $firstLabel) {
                                $selected = 'selected';
                            }
                            echo '<option value="' . htmlspecialchars($label) . '" ' . $selected . '>' . htmlspecialchars($label . ' (' . $number . ')') . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group flex items-center flex-grow">
                    <input type="file" name="media" id="media" style="display:none;" accept="image/*">
                    <input type="text" name="message" id="message" class="flex-grow border rounded px-3 py-2" placeholder="Type your message here..." autocomplete="off">
                </div>
                <div id="image-preview" style="display:none;">
                    <img id="preview-img" src="#" alt="Image Preview">
                    <button type="button" id="remove-image">&#x2716;</button>
                </div>
                <!-- Send + Schedule buttons -->
                <div class="form-group flex items-center ml-2" style="position:relative;">
                    <button type="submit" id="send-btn"
                        class="bg-blue-500 text-white px-4 py-2 rounded-l disabled:opacity-50"
                        style="border-right:1px solid rgba(255,255,255,0.3);"
                        disabled>Send</button>
                    <button type="button" id="schedule-btn"
                        class="bg-blue-500 text-white px-2 py-2 rounded-r disabled:opacity-50"
                        title="Schedule Send" disabled>
                        <!-- Calendar icon -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM2 2a1 1 0 0 0-1 1v1h14V3a1 1 0 0 0-1-1H2zm13 3H1v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V5z"/>
                            <path d="M6 9a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm2 0a1 1 0 1 1 2 0 1 1 0 0 1-2 0zm4 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM4 12a1 1 0 1 1 2 0 1 1 0 0 1-2 0zm4 0a1 1 0 1 1 2 0 1 1 0 0 1-2 0z"/>
                        </svg>
                    </button>

                    <!-- Schedule picker popover -->
                    <div id="schedule-picker">
                        <h4>&#128337; Schedule Send (Eastern Time)</h4>
                        <div>
                            <label for="sched-date">Date</label>
                            <select id="sched-date"></select>
                        </div>
                        <div>
                            <label for="sched-time">Time</label>
                            <select id="sched-time"></select>
                        </div>
                        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px;">
                            <button type="button" id="sched-dismiss-btn"
                                style="padding:6px 14px; border:1px solid #d1d5db; border-radius:6px; background:#fff; cursor:pointer; font-size:13px;">
                                Cancel
                            </button>
                            <button type="button" id="sched-confirm-btn"
                                style="padding:6px 14px; background:#1D4ED8; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600;">
                                Schedule
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Image modal -->
        <div x-show="modalOpen" class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-75">
            <div class="relative">
                <button @click="modalOpen = false" class="absolute top-0 right-0 m-2 text-white text-2xl">&times;</button>
                <img :src="modalImageUrl" alt="Image" class="max-w-full max-h-screen">
                <a :href="modalImageUrl" download class="absolute bottom-0 right-0 m-2 bg-white px-4 py-2 rounded">Download</a>
            </div>
        </div>
    </div>

    <!-- Edit scheduled message modal -->
    <div id="edit-modal-overlay">
        <div id="edit-modal-box">
            <h4>&#9998; Edit Scheduled Message</h4>
            <input type="hidden" id="edit-msg-id">
            <div>
                <label for="edit-msg-body">Message</label>
                <textarea id="edit-msg-body" rows="3"></textarea>
            </div>
            <div>
                <label for="edit-msg-date">Date (Eastern Time)</label>
                <select id="edit-msg-date"></select>
            </div>
            <div>
                <label for="edit-msg-time">Time (Eastern Time)</label>
                <select id="edit-msg-time"></select>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px;">
                <button type="button" id="edit-modal-cancel-btn"
                    style="padding:6px 14px; border:1px solid #d1d5db; border-radius:6px; background:#fff; cursor:pointer; font-size:13px;">
                    Cancel
                </button>
                <button type="button" id="edit-modal-save-btn"
                    style="padding:6px 14px; background:#1D4ED8; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600;">
                    Save Changes
                </button>
            </div>
        </div>
    </div>

    <script>
    $(function(){
        var pollingInterval, pollingCount = 0, maxPollingCount = 6;
        var scriptName = window.location.pathname.split('/').pop();

        // ---- Date / Time helpers ----

        // Returns [{value:'YYYY-MM-DD', label:'...'}] for the next 3 non-Sunday days.
        // Also includes today if it's not Sunday and future time slots still exist.
        function getAvailableDates() {
            var dates  = [];
            var now    = dayjs().tz("America/New_York");
            var today  = now.format('YYYY-MM-DD');

            // Include today if not Sunday and at least one slot is ≥10 min from now
            if (now.day() !== 0 && getTimeSlots(today, true).length > 0) {
                dates.push({ value: today, label: 'Today, ' + now.format('MMM D') });
            }

            var check = now.clone();
            while (dates.length < 4) { // today (maybe) + 3 future days
                check = check.add(1, 'day');
                if (check.day() !== 0) {
                    if (dates.length < 4) {
                        dates.push({ value: check.format('YYYY-MM-DD'), label: check.format('ddd, MMM D') });
                    }
                }
                if (dates.length >= 4) break;
            }

            // Keep at most today + 3 future non-Sunday days
            // (at most 4 total, at most 3 if today not included)
            return dates.slice(0, 4);
        }

        // Returns [{value:'HH:mm', label:'h:mm A'}] time slots 8 AM–8 PM in 15-min steps.
        // filterPast=true skips slots ≤ now+10min when dateStr is today.
        function getTimeSlots(dateStr, filterPast) {
            var slots = [];
            var now   = dayjs().tz("America/New_York");
            var today = now.format('YYYY-MM-DD');

            for (var h = 8; h <= 20; h++) {
                var maxM = (h === 20) ? 1 : 4; // only :00 at 8 PM
                for (var mi = 0; mi < maxM; mi++) {
                    var m       = mi * 15;
                    var hStr    = String(h).padStart(2, '0');
                    var mStr    = String(m).padStart(2, '0');
                    var timeVal = hStr + ':' + mStr;

                    if (filterPast && dateStr === today) {
                        var slotDt = dayjs.tz(dateStr + ' ' + timeVal, "America/New_York");
                        if (slotDt.valueOf() <= now.add(10, 'minute').valueOf()) continue;
                    }

                    // Format label using a reference date (dayjs format of the time)
                    var label = (h > 12 ? h - 12 : (h === 0 ? 12 : h)) + ':' + mStr + ' ' + (h < 12 ? 'AM' : 'PM');
                    if (h === 12) label = '12:' + mStr + ' PM';
                    if (h === 0)  label = '12:' + mStr + ' AM';

                    slots.push({ value: timeVal, label: label });
                }
            }
            return slots;
        }

        function populateDateSelect($sel, currentVal) {
            var dates = getAvailableDates();
            $sel.empty();
            $.each(dates, function(_, d) {
                var opt = $('<option>').val(d.value).text(d.label);
                if (currentVal && d.value === currentVal) opt.prop('selected', true);
                $sel.append(opt);
            });
            return dates.length > 0 ? dates[0].value : null;
        }

        function populateDateSelectForEdit($sel, currentVal) {
            // For edit: include the existing scheduled date even if it's beyond the 3-day window
            var dates = getAvailableDates();
            var found = dates.some(function(d) { return d.value === currentVal; });
            if (currentVal && !found) {
                var labelDt = dayjs.tz(currentVal + 'T12:00', "America/New_York");
                dates.unshift({ value: currentVal, label: labelDt.format('ddd, MMM D') + ' (current)' });
            }
            $sel.empty();
            $.each(dates, function(_, d) {
                var opt = $('<option>').val(d.value).text(d.label);
                if (d.value === currentVal) opt.prop('selected', true);
                $sel.append(opt);
            });
        }

        function populateTimeSelect($sel, dateStr, currentVal) {
            var slots = getTimeSlots(dateStr, false); // no filtering for edit
            $sel.empty();
            $.each(slots, function(_, s) {
                var opt = $('<option>').val(s.value).text(s.label);
                if (currentVal && s.value === currentVal) opt.prop('selected', true);
                $sel.append(opt);
            });
        }

        function populateTimeSelectFiltered($sel, dateStr, currentVal) {
            var slots = getTimeSlots(dateStr, true);
            $sel.empty();
            $.each(slots, function(_, s) {
                var opt = $('<option>').val(s.value).text(s.label);
                if (currentVal && s.value === currentVal) opt.prop('selected', true);
                $sel.append(opt);
            });
        }

        // ---- Polling / message load ----

        function startPolling(){
            pollingCount = 0;
            if (pollingInterval) clearInterval(pollingInterval);
            pollingInterval = setInterval(function(){
                if(document.visibilityState === 'visible'){
                    if (pollingCount < maxPollingCount) {
                        loadMessages();
                        pollingCount++;
                    } else {
                        clearInterval(pollingInterval);
                        $('#reload-button').show();
                    }
                }
            }, 10000);
        }

        function escapeHtml(text) {
            return $('<div>').text(text).html();
        }

        function renderScheduledMessages(scheduled) {
            // Remove previous scheduled section
            $('#scheduled-section').remove();

            if (!scheduled || scheduled.length === 0) return;

            var html = '<div id="scheduled-section">';
            html += '<div class="scheduled-section-divider">SCHEDULED (' + scheduled.length + ')</div>';

            $.each(scheduled, function(_, msg) {
                var dt            = dayjs(msg.scheduled_at).tz("America/New_York");
                var timeFormatted = dt.format('ddd, MMM D [at] h:mm A') + ' ET';
                var bodyEscaped   = escapeHtml(msg.body);
                var schedAtEsc    = escapeHtml(msg.scheduled_at);

                html += '<div class="message sent scheduled-item">';
                html += '  <div class="text">';
                html += '    <p>' + bodyEscaped + '</p>';
                html += '    <div class="sched-actions">';
                html += '      <button class="btn-edit sched-edit-btn"'
                            + ' data-id="' + msg.id + '"'
                            + ' data-body="' + bodyEscaped + '"'
                            + ' data-scheduled="' + schedAtEsc + '">&#9998; Edit</button>';
                html += '      <button class="btn-cancel sched-cancel-btn"'
                            + ' data-id="' + msg.id + '">&#10005; Cancel</button>';
                html += '    </div>';
                html += '  </div>';
                html += '  <div class="time">&#128337; Scheduled: ' + timeFormatted + '</div>';
                html += '</div>';
            });

            html += '</div>';
            $('#chat-messages').append(html);
            $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
        }

        function loadMessages(){
            $.ajax({
                url: scriptName,
                method: 'GET',
                data: { action: 'checkmessage' },
                dataType: 'json',
                success: function(response){
                    if(response.status === 'success'){
                        var messages = response.messages;
                        if (messages.length > 0) {
                            // Remove scheduled section temporarily so new messages insert before it
                            var $sched = $('#scheduled-section').detach();

                            for(var i=0; i<messages.length; i++){
                                var message   = messages[i];
                                var msgClass  = (message.from_label !== 'Customer') ? 'sent' : 'received';
                                var typeClass = (message.from_label === 'Pre Sales') ? 'pre-sales'
                                              : (message.from_label === 'Post Sales') ? 'post-sales' : '';
                                var html = '<div class="message ' + msgClass + ' ' + typeClass + '">';
                                html += '<div class="text">';
                                if(message.body){ html += '<p>' + escapeHtml(message.body) + '</p>'; }
                                if(message.media && message.media.length){
                                    for(var j=0;j<message.media.length;j++){
                                        var mUrl = message.media[j];
                                        html += '<img src="' + mUrl + '" alt="Image" @click="modalOpen=true;modalImageUrl=\'' + mUrl + '\'">';
                                    }
                                }
                                html += '</div>';
                                if(message.status === 'failed' || message.status === 'undelivered'){
                                    html += '<div class="error-indicator" title="Message failed to send">&#9888;&#65039;</div>';
                                }
                                var dUtc      = dayjs.utc(message.dateSent);
                                var dLocal    = dUtc.tz("America/New_York");
                                var dFromNow  = dLocal.fromNow();
                                var dFmt      = dLocal.format('MMM D, YYYY h:mm A');
                                html += '<div class="time" title="' + dFmt + '">' + dFromNow + ' - ' + escapeHtml(message.from_label) + '</div>';
                                html += '</div>';
                                $('#chat-messages').append(html);
                            }

                            // Re-attach old scheduled section (will be overwritten below)
                            if ($sched.length) $('#chat-messages').append($sched);
                            $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
                        }
                    }

                    // Always refresh scheduled messages (success OR no_new_messages)
                    renderScheduledMessages(response.scheduled || []);
                },
                error: function(xhr, status, error){
                    alert('AJAX Error: ' + error);
                    console.log(xhr.responseText);
                }
            });
        }

        // Initial load + polling
        loadMessages();
        startPolling();

        $('#check-messages-btn').on('click', function(){
            $('#reload-button').hide();
            startPolling();
        });

        // ---- Regular send ----
        $('#message-form').on('submit', function(e){
            e.preventDefault();
            var formData = new FormData(this);
            formData.append('action', 'sendmessage');
            $.ajax({
                url: scriptName,
                method: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(response){
                    if(response.status === 'success'){
                        $('#message').val('');
                        $('#media').val('');
                        $('#image-preview').hide();
                        $('#preview-img').attr('src','');
                        toggleSend();
                        loadMessages();
                    } else {
                        alert('Error: ' + (response.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error){
                    alert('AJAX Error: ' + error);
                    console.log(xhr.responseText);
                }
            });
        });

        // ---- Schedule picker ----
        $('#schedule-btn').on('click', function(e){
            e.stopPropagation();
            var $picker = $('#schedule-picker');
            if ($picker.is(':visible')) { $picker.hide(); return; }

            // Populate dates
            var firstDate = populateDateSelect($('#sched-date'), null);
            // Populate times for first date
            populateTimeSelectFiltered($('#sched-time'), firstDate, null);
            $picker.show();
        });

        $('#sched-date').on('change', function(){
            populateTimeSelectFiltered($('#sched-time'), $(this).val(), null);
        });

        $('#sched-dismiss-btn').on('click', function(){
            $('#schedule-picker').hide();
        });

        // Close picker when clicking outside
        $(document).on('click', function(e){
            if (!$(e.target).closest('#schedule-picker, #schedule-btn').length) {
                $('#schedule-picker').hide();
            }
        });

        $('#sched-confirm-btn').on('click', function(){
            var date    = $('#sched-date').val();
            var time    = $('#sched-time').val();
            var message = $.trim($('#message').val());
            var fromNum = $('#from_number').val();

            if (!date || !time) { alert('Please select a date and time.'); return; }
            if (!message)       { alert('Please type a message before scheduling.'); return; }

            $.ajax({
                url: scriptName,
                method: 'POST',
                data: {
                    action:          'schedulemessage',
                    message:         message,
                    from_number:     fromNum,
                    scheduled_at_et: date + ' ' + time
                },
                dataType: 'json',
                success: function(response){
                    if(response.status === 'success'){
                        $('#message').val('');
                        toggleSend();
                        $('#schedule-picker').hide();
                        loadMessages();
                    } else {
                        alert('Error: ' + (response.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error){
                    alert('AJAX Error: ' + error);
                    console.log(xhr.responseText);
                }
            });
        });

        // ---- Cancel scheduled ----
        $(document).on('click', '.sched-cancel-btn', function(){
            var id = $(this).data('id');
            if (!confirm('Cancel this scheduled message? This cannot be undone.')) return;
            $.ajax({
                url: scriptName,
                method: 'POST',
                data: { action: 'cancel_scheduled', id: id },
                dataType: 'json',
                success: function(response){
                    if(response.status === 'success'){
                        loadMessages();
                    } else {
                        alert('Error: ' + (response.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error){ alert('AJAX Error: ' + error); }
            });
        });

        // ---- Edit scheduled: open modal ----
        $(document).on('click', '.sched-edit-btn', function(){
            var id          = $(this).data('id');
            var body        = $(this).data('body');
            var scheduledAt = $(this).data('scheduled');

            var dt      = dayjs(scheduledAt).tz("America/New_York");
            var dateStr = dt.format('YYYY-MM-DD');
            var timeStr = dt.format('HH:mm');

            $('#edit-msg-id').val(id);
            $('#edit-msg-body').val(body);

            populateDateSelectForEdit($('#edit-msg-date'), dateStr);
            populateTimeSelect($('#edit-msg-time'), dateStr, timeStr);

            $('#edit-msg-date').off('change.editmodal').on('change.editmodal', function(){
                populateTimeSelect($('#edit-msg-time'), $(this).val(), null);
            });

            $('#edit-modal-overlay').addClass('open');
        });

        $('#edit-modal-cancel-btn').on('click', function(){
            $('#edit-modal-overlay').removeClass('open');
        });

        // Close edit modal on overlay click
        $('#edit-modal-overlay').on('click', function(e){
            if ($(e.target).is('#edit-modal-overlay')) {
                $(this).removeClass('open');
            }
        });

        $('#edit-modal-save-btn').on('click', function(){
            var id   = $('#edit-msg-id').val();
            var body = $.trim($('#edit-msg-body').val());
            var date = $('#edit-msg-date').val();
            var time = $('#edit-msg-time').val();

            if (!body)        { alert('Message cannot be empty.'); return; }
            if (!date || !time){ alert('Please select a date and time.'); return; }

            $.ajax({
                url: scriptName,
                method: 'POST',
                data: {
                    action:          'edit_scheduled',
                    id:              id,
                    message:         body,
                    scheduled_at_et: date + ' ' + time
                },
                dataType: 'json',
                success: function(response){
                    if(response.status === 'success'){
                        $('#edit-modal-overlay').removeClass('open');
                        loadMessages();
                    } else {
                        alert('Error: ' + (response.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error){ alert('AJAX Error: ' + error); }
            });
        });

        // ---- File / media helpers ----
        $('#media').on('change', function(){
            var file = this.files[0];
            if(file){
                var reader = new FileReader();
                reader.onload = function(e){
                    $('#preview-img').attr('src', e.target.result);
                    $('#image-preview').show();
                };
                reader.readAsDataURL(file);
            } else {
                $('#image-preview').hide();
                $('#preview-img').attr('src','');
            }
            toggleSend();
        });

        $('#remove-image').on('click', function(){
            $('#media').val('');
            $('#image-preview').hide();
            $('#preview-img').attr('src','');
            toggleSend();
        });

        $('#message').on('input', toggleSend);

        function toggleSend(){
            var hasContent = $.trim($('#message').val()) !== '' || $('#media').val();
            $('#send-btn').prop('disabled', !hasContent);
            $('#schedule-btn').prop('disabled', !hasContent);
        }

        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible' && $('#reload-button').is(':visible')) {
                $('#reload-button').hide();
                startPolling();
            }
        });
    });
    </script>
</body>
</html>
<?php
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo 'An error occurred. Please try again later.';
    // Uncomment during debugging:
    // echo '<pre>'.htmlspecialchars($e->getMessage()).'</pre>';
} finally {
    if (function_exists('ob_get_length') && ob_get_length()) {
        ob_end_flush();
    }
}
?>
