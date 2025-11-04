<?php
/**
 * MailCow Provisioning Module for WHMCS
 *
 * @author Lucian Lazar
 * @version 11.2 (Button Rename)
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly.");
}

// Import the Capsule class for database interaction
use WHMCS\Database\Capsule;

// --- Custom Fields Configuration ---
define('MAILCOW_INTERNAL_USERNAME_FIELD', 'mailcow_admin_username');
// --- End Configuration ---

// Helper functions to generate username and password
function mailcow_generateRandomString($length = 5) {
    $characters = 'abcdefghijklmnopqrstuvwxyz';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function mailcow_generateStrongPassword($length = 22) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}|;:,.<>?';
    return substr(str_shuffle(str_repeat($chars, ceil($length/strlen($chars)))), 1, $length);
}

/**
 * Helper function to update a custom field value in the database.
 */
function mailcow_updateCustomFieldValue($serviceId, $fieldName, $value) {
    try {
        $fieldId = Capsule::table('tblcustomfields')->where('fieldname', $fieldName)->where('type', 'product')->value('id');
        if ($fieldId) {
            $existingValue = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fieldId)->where('relid', $serviceId)->first();
            if ($existingValue) {
                Capsule::table('tblcustomfieldsvalues')->where('id', $existingValue->id)->update(['value' => $value]);
            } else {
                Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $fieldId, 'relid' => $serviceId, 'value' => $value]);
            }
        }
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, "Failed to update custom field '{$fieldName}' for service {$serviceId}", $e->getMessage());
    }
}


/**
 * Defines the module metadata.
 *
 * @return array
 */
function mailcow_MetaData()
{
    return array(
        'DisplayName' => 'MailCow',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
    );
}


/**
 * Sends an API request to MailCow.
 *
 * @param array $params
 * @param string $endpoint
 * @param string $method
 * @param array $data
 * @return array
 * @throws Exception
 */
function mailcow_API_Call($params, $endpoint, $method = 'POST', $data = [])
{
    $hostname = trim($params['serverhostname']);
    $apiKey = $params['serveraccesshash'];
    $protocol = $params['serversecure'] ? 'https' : 'http';
    $url = "{$protocol}://{$hostname}/api/v1/{$endpoint}";

    $headers = ['X-API-Key: ' . $apiKey, 'Content-Type: application/json'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERAGENT => 'WHMCS-MailCow-Module/11.2',
    ]);

    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("cURL Error: " . $error);
    }

    $decodedResponse = json_decode($response, true);

    if ($httpCode >= 400) {
        $errorMessage = $decodedResponse[0]['msg'] ?? (is_string($response) ? $response : 'Invalid Response');
        throw new Exception("MailCow API Error (HTTP {$httpCode}): " . $errorMessage);
    }

    if (isset($decodedResponse[0]['type']) && ($decodedResponse[0]['type'] === 'error' || $decodedResponse[0]['type'] === 'danger')) {
        $errorMessage = $decodedResponse[0]['msg'] ?? 'Unspecified error from MailCow.';
        if (is_array($errorMessage)) {
            $errorMessage = implode(', ', $errorMessage);
        }
        throw new Exception("MailCow API Error: " . $errorMessage);
    }

    if ($method === 'POST' || $method === 'DELETE') {
        if (strpos($endpoint, 'get/') === 0) {
            return $decodedResponse;
        }
        if (!isset($decodedResponse[0]['type']) || $decodedResponse[0]['type'] !== 'success') {
            throw new Exception("Unexpected response from MailCow: " . (is_string($response) ? $response : json_encode($decodedResponse)));
        }
    }
    return $decodedResponse;
}

/**
 * Defines the custom command buttons.
 *
 * @return array
 */
function mailcow_AdminCustomButtonArray()
{
    return array(
        "Change Username" => "SyncUsername",
    );
}


/**
 * Creates a new account.
 *
 * @param array $params
 * @return string
 */
function mailcow_CreateAccount(array $params)
{
    try {
        // Step 1: Create the domain
        $domain = $params['domain'];
        $configOptions = $params['configoptions'];

        // **IMPORTANT: These keys must match the English names in README.en.html**
        $quota = (int)($configOptions['Total Domain Quota'] ?? 5120);
        $maxAccounts = (int)($configOptions['Max Mailboxes'] ?? 5);
        $maxAliases = (int)($configOptions['Max Aliases'] ?? 10);

        $data_domain = [
            'domain' => $domain,
            'description' => $params['clientsdetails']['companyname'] ?: $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'],
            'aliases' => (string)$maxAliases,
            'defquota' => "1024", // Default mailbox quota (1GB) as per README
            'maxquota' => (string)$quota,
            'quota' => (string)$quota,
            'mailboxes' => (string)$maxAccounts,
            'active' => "1"
        ];

        mailcow_API_Call($params, 'add/domain', 'POST', $data_domain);

        // Step 2: Generate and create the domain administrator
        $username = "admin-" . mailcow_generateRandomString(5) . "-" . $params['userid'];
        $password = mailcow_generateStrongPassword(22);

        $data_admin = [
            'username' => $username,
            'password' => $password,
            'password2' => $password,
            'domains' => $domain,
            'active' => '1'
        ];
        
        mailcow_API_Call($params, 'add/domain-admin', 'POST', $data_admin);

        // Step 3: Save the generated values
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->update([
                'username' => $username,
                'password' => encrypt($password),
            ]);
        // Save in the internal custom field as well
        mailcow_updateCustomFieldValue($params['serviceid'], MAILCOW_INTERNAL_USERNAME_FIELD, $username);

        return 'success';

    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Suspends an account.
 *
 * @param array $params
 * @return string
 */
function mailcow_SuspendAccount(array $params)
{
    try {
        // Step 1: Suspend the domain
        $domain = $params['domain'];
        $data_domain = ['attr' => ['active' => '0'], 'items' => [$domain]];
        mailcow_API_Call($params, 'edit/domain', 'POST', $data_domain);

        // Step 2: Suspend the domain administrator, using the internal field
        $adminUsername = $params['customfields'][MAILCOW_INTERNAL_USERNAME_FIELD] ?? $params['username'];
        if (!empty($adminUsername)) {
            $data_admin = [
                'items' => [$adminUsername],
                'attr' => ['active' => '0']
            ];
            mailcow_API_Call($params, 'edit/domain-admin', 'POST', $data_admin);
        }

        logModuleCall('mailcow', __FUNCTION__, ['domain' => $domain, 'admin' => $adminUsername], 'Success', null, []);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Unsuspends an account.
 *
 * @param array $params
 * @return string
 */
function mailcow_UnsuspendAccount(array $params)
{
    try {
        // Step 1: Reactivate the domain
        $domain = $params['domain'];
        $data_domain = [
            'attr' => ['active' => '1'],
            'items' => [$domain]
        ];
        mailcow_API_Call($params, 'edit/domain', 'POST', $data_domain);

        // Step 2: Reactivate the domain administrator, using the internal field
        $adminUsername = $params['customfields'][MAILCOW_INTERNAL_USERNAME_FIELD] ?? $params['username'];
        if (!empty($adminUsername)) {
            $data_admin = [
                'items' => [$adminUsername],
                'attr' => ['active' => '1']
            ];
            mailcow_API_Call($params, 'edit/domain-admin', 'POST', $data_admin);
        }
        
        logModuleCall('mailcow', __FUNCTION__, ['domain' => $domain, 'admin' => $adminUsername], 'Success', null, []);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Terminates an account.
 *
 * @param array $params
 * @return string
 */
function mailcow_TerminateAccount(array $params)
{
    try {
        // Step 1: Terminate the domain administrator, using the internal field
        $adminUsername = $params['customfields'][MAILCOW_INTERNAL_USERNAME_FIELD] ?? $params['username'];
        if (!empty($adminUsername)) {
            mailcow_API_Call($params, 'delete/domain-admin', 'POST', [$adminUsername]);
        }
        
        // Step 2: Terminate the domain and its mailboxes
        $domain = $params['domain'];
        $mailboxes = mailcow_API_Call($params, 'get/mailbox/all/' . $domain, 'GET');

        if (!empty($mailboxes) && is_array($mailboxes)) {
            $usernamesToDelete = array_column($mailboxes, 'username');
            if (!empty($usernamesToDelete)) {
                mailcow_API_Call($params, 'delete/mailbox', 'POST', $usernamesToDelete);
            }
        }
        mailcow_API_Call($params, 'delete/domain', 'POST', [$domain]);

        // Step 3: Clear the login data from the service in WHMCS
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->update([
                'username' => '',
                'password' => '',
            ]);
        
        // Step 4: Clear the internal custom field
        mailcow_updateCustomFieldValue($params['serviceid'], MAILCOW_INTERNAL_USERNAME_FIELD, '');

        logModuleCall('mailcow', __FUNCTION__, ['domain' => $domain, 'admin' => $adminUsername], 'Success, login data cleared.', null, []);
        return 'success';

    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, ['domain' => $domain], $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Changes the package (used for upgrade/downgrade).
 *
 * @param array $params
 * @return string
 */
function mailcow_ChangePackage(array $params)
{
    try {
        // Change the domain options
        $domain = $params['domain'];
        $configOptions = $params['configoptions'];

        // **IMPORTANT: These keys must match the English names in README.en.html**
        $quota = (int)($configOptions['Total Domain Quota'] ?? 5120);
        $maxAccounts = (int)($configOptions['Max Mailboxes'] ?? 5);
        $maxAliases = (int)($configOptions['Max Aliases'] ?? 10);
        
        $data_domain = [
            'attr' => [
                'maxquota' => (string)$quota,
                'quota' => (string)$quota,
                'mailboxes' => (string)$maxAccounts,
                'aliases' => (string)$maxAliases,
            ],
            'items' => [$domain]
        ];
        mailcow_API_Call($params, 'edit/domain', 'POST', $data_domain);

        logModuleCall('mailcow', __FUNCTION__, ['domain' => $domain], 'Success', null, []);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Changes the domain administrator's password.
 *
 * @param array $params
 * @return string
 */
function mailcow_ChangePassword(array $params)
{
    try {
        // Use the internal field to find the correct username
        $adminUsername = $params['customfields'][MAILCOW_INTERNAL_USERNAME_FIELD] ?? $params['username'];
        $newPassword = $params['password'];

        if (!empty($adminUsername) && !empty($newPassword)) {
             $data_admin = [
                'items' => [$adminUsername],
                'attr' => [
                    'password' => $newPassword,
                    'password2' => $newPassword,
                ]
            ];
            mailcow_API_Call($params, 'edit/domain-admin', 'POST', $data_admin);
        } else {
            throw new Exception("Username or new password missing.");
        }

        logModuleCall('mailcow', __FUNCTION__, ['admin' => $adminUsername], 'Success', null, []);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Synchronizes the new username from WHMCS with MailCow.
 *
 * @param array $params
 * @return string
 */
function mailcow_SyncUsername(array $params)
{
    try {
        $oldUsername = $params['customfields'][MAILCOW_INTERNAL_USERNAME_FIELD] ?? '';
        $newUsername = $params['username'];

        if (empty($oldUsername)) {
            throw new Exception("Old (internal) username not found. Unable to sync.");
        }
        if (empty($newUsername)) {
            throw new Exception("New username cannot be empty.");
        }
        if ($oldUsername === $newUsername) {
            return "Success"; // No action necessary
        }

        $data_sync = [
            'items' => [$oldUsername],
            'attr' => ['username_new' => $newUsername]
        ];
        mailcow_API_Call($params, 'edit/domain-admin', 'POST', $data_sync);
        
        // Update the internal field with the new username
        mailcow_updateCustomFieldValue($params['serviceid'], MAILCOW_INTERNAL_USERNAME_FIELD, $newUsername);

        logModuleCall('mailcow', __FUNCTION__, ['old' => $oldUsername, 'new' => $newUsername], 'Success', null, []);
        return 'success';

    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), null, []);
        return $e->getMessage();
    }
}


/**
 * Tests the connection to the server.
 *
 * @param array $params
 * @return array
 */
function mailcow_TestConnection(array $params)
{
    $success = true;
    $errorMsg = '';
    try {
        // Use a simple GET endpoint that requires auth
        mailcow_API_Call($params, 'get/status/vmail', 'GET');
    } catch (Exception $e) {
        $success = false;
        $errorMsg = $e->getMessage();
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), null, []);
    }
    return ['success' => $success, 'error' => $errorMsg];
}
