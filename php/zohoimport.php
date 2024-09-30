<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Hard-coded configuration array
/*
Angel:
$config = [
    'database' => [
        'host' => 'localhost',
        'user' => 'uufejrmd_angelado',
        'password' => 'lXd700Qu9l',
        'db' => 'uufejrmd_angelado',
        'dbprefix' => 'hpz8r_'
    ]
];

LifeLong:
$config = [
    'database' => [
        'host' => 'localhost',
        'user' => 'lifelong_lifelong',
        'password' => 'm1eCYd08l7',
        'db' => 'lifelong_lifelong',
        'dbprefix' => 'xukh5_'
    ]
];
*/
$config = [
    'database' => [
        'host' => 'localhost',
        'user' => 'uufejrmd_angelado',
        'password' => 'lXd700Qu9l',
        'db' => 'uufejrmd_angelado',
        'dbprefix' => 'hpz8r_'
    ]
];

// Function to submit data to Zoho
function submitToZoho($data, $type, &$zoho, $id) {
    $post = ['data' => [$data]];
    $ch = curl_init('https://www.zohoapis.com/crm/v2/Leads');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Zoho-oauthtoken ' . $zoho["zohov2.{$type}.access_token"]
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    if (isset($result['status']) && $result['status'] == 'error') {
        if ($result['code'] == 'INVALID_TOKEN') {
            $zoho = refreshAccessToken($zoho, $type);
            if ($zoho === false) {
                return "Failed to refresh token";
            } else {
                return submitToZoho($data, $type, $zoho, $id);
            }
        } else {
            return $result['code'];
        }
    } elseif (isset($result['data'][0]['code']) && $result['data'][0]['code'] != 'SUCCESS') {
        if ($result['data'][0]['code'] == 'INVALID_DATA') {
            unset($data[$result['data'][0]['details']['api_name']]);
            return submitToZoho($data, $type, $zoho, $id);
        } else {
            return "Failed to submit";
        }
    } else {
        return "Success";
    }
}

// Function to refresh access token
function refreshAccessToken($zoho, $type) {
    $ch = curl_init('https://accounts.zoho.com/oauth/v2/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'refresh_token',
        'client_id' => $zoho["zohov2.{$type}.id"],
        'client_secret' => $zoho["zohov2.{$type}.secret"],
        'refresh_token' => $zoho["zohov2.{$type}.refresh_token"]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    if (isset($result['access_token'])) {
        $zoho["zohov2.{$type}.access_token"] = $result['access_token'];
        return $zoho;
    }
    return false;
}

// Main script execution
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submissions = isset($_POST['submissions']) ? $_POST['submissions'] : '';
    $submissionIds = array_filter(explode("\n", str_replace("\r\n", "\n", $submissions)));

    try {
        $dsn = "mysql:host={$config['database']['host']};dbname={$config['database']['db']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['database']['user'], $config['database']['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Get Zoho config
        $stmt = $pdo->query("SELECT SettingName, SettingValue FROM {$config['database']['dbprefix']}rsform_config WHERE SettingName LIKE 'zohov2%'");
        $zoho = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $zoho[$row['SettingName']] = $row['SettingValue'];
        }

        if (count($zoho) < 8) {
            echo "Zoho config not found.<br>\n";
            exit;
        }

        // Process submissions
        foreach ($submissionIds as $submissionId) {
            $stmt = $pdo->prepare("SELECT FormId FROM {$config['database']['dbprefix']}rsform_submissions WHERE SubmissionId = ?");
            $stmt->execute([$submissionId]);
            $formId = $stmt->fetchColumn();

            if (!$formId) {
                echo "Submission {$submissionId}: Form ID not found<br>\n";
                continue;
            }

            $stmt = $pdo->prepare("SELECT map, type FROM {$config['database']['dbprefix']}rsform_zohov2 WHERE form_id = ? AND published = 1");
            $stmt->execute([$formId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                echo "Submission {$submissionId}: Zoho mapping not found<br>\n";
                continue;
            }

            $map = json_decode($result['map'], true);
            $type = $result['type'];

            $stmt = $pdo->prepare("SELECT FieldName, FieldValue FROM {$config['database']['dbprefix']}rsform_submission_values WHERE SubmissionId = ?");
            $stmt->execute([$submissionId]);
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isset($map[$row['FieldName']])) {
                    $data[$map[$row['FieldName']]] = $row['FieldValue'];
                }
            }

            if (isset($data['First_Name']) && (!isset($data['Last_Name']) || $data['Last_Name'] === '')) {
                $data['Last_Name'] = 'Not Provided';
            }

            $result = submitToZoho($data, $type, $zoho, $submissionId);
            echo "Submission {$submissionId}: {$result}<br>\n";
        }

    } catch (PDOException $e) {
        echo "Database error: " . $e->getMessage() . "<br>\n";
    }

    echo "Finished processing submissions.<br>\n";
} else {
    // Display the form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Submit Zoho CRM Data</title>
    </head>
    <body>
        <h1>Submit Zoho CRM Data</h1>
        <form method="post">
            <label for="submissions">Enter submission IDs (one per line):</label><br>
            <textarea name="submissions" id="submissions" rows="10" cols="50"></textarea><br>
            <input type="submit" value="Process Submissions">
        </form>
    </body>
    </html>
    <?php
}
?>