<?php
/**
 * Modulo di Provisioning MailCow per WHMCS
 *
 * @author Lucian Lazar
 * @version 11.3 (Sanitize log entries)
 */

if (!defined("WHMCS")) {
    die("Questo file non può essere aceduto direttamente.");
}

// Importa la classe Capsule pentru interacțiunea cu baza de date
use WHMCS\Database\Capsule;

// --- Configurazione Campi Personalizzati ---
define('MAILCOW_INTERNAL_USERNAME_FIELD', 'mailcow_admin_username');
// --- Fine Configurazione ---

// Funzioni helper per generare username e password
function mailcow_generateRandomString($length = 5)
{
    $characters = 'abcdefghijklmnopqrstuvwxyz';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function mailcow_generateStrongPassword($length = 22)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}|;:,.<>?';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 1, $length);
}

/**
 * Funzione helper per aggiornare il valore di un campo personalizzato nel database.
 */
function mailcow_updateCustomFieldValue($serviceId, $fieldName, $value)
{
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
 * Sanitizes parameters for logging, removing sensitive data.
 *
 * @param array $params
 * @return array
 */
function mailcow_sanitizeParamsForLog($params)
{
    $cleanParams = $params;
    $sensitiveKeys = ['password', 'serveraccesshash', 'password2', 'username', 'mailcow_admin_username'];

    // Sanitize top-level keys
    foreach ($sensitiveKeys as $key) {
        if (isset($cleanParams[$key])) {
            $cleanParams[$key] = '***SANITIZED***';
        }
    }

    // Sanitize custom fields if present
    if (isset($cleanParams['customfields']) && is_array($cleanParams['customfields'])) {
        foreach ($cleanParams['customfields'] as $key => $value) {
            // Check if key name resembles a sensitive field (basic check)
            if (stripos($key, 'password') !== false || stripos($key, 'secret') !== false) {
                $cleanParams['customfields'][$key] = '***SANITIZED***';
            }
        }
        // Explicitly sanitize internal username field if present in customfields
        if (isset($cleanParams['customfields'][MAILCOW_INTERNAL_USERNAME_FIELD])) {
            $cleanParams['customfields'][MAILCOW_INTERNAL_USERNAME_FIELD] = '***SANITIZED***';
        }
    }

    return $cleanParams;
}


/**
 * Definisce i metadati del modulo.
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
 * Invia una richiesta API a MailCow.
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
 * Definisci pulsanti personalizzati.
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
 * Crea un nuovo account.
 *
 * @param array $params
 * @return string
 */
function mailcow_CreateAccount(array $params)
{
    try {
        // Step 1: Crea il dominio
        $domain = $params['domain'];
        $configOptions = $params['configoptions'];

        $quota = (int) ($configOptions['Quota Totale Dominio'] ?? 5120);
        $maxAccounts = (int) ($configOptions['Max Caselle'] ?? 5);
        $maxAliases = (int) ($configOptions['Max Alias'] ?? 10);

        $data_domain = [
            'domain' => $domain,
            'description' => $params['clientsdetails']['companyname'] ?: $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'],
            'aliases' => (string) $maxAliases,
            'defquota' => "1024",
            'maxquota' => (string) $quota,
            'quota' => (string) $quota,
            'mailboxes' => (string) $maxAccounts,
            'active' => "1"
        ];

        mailcow_API_Call($params, 'add/domain', 'POST', $data_domain);

        // Step 2: Genera e crea l'amministratore del dominio
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

        // Step 3: Salva i valori generati
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->update([
                'username' => $username,
                'password' => encrypt($password),
            ]);
        // Salvează și în câmpul personalizat intern
        mailcow_updateCustomFieldValue($params['serviceid'], MAILCOW_INTERNAL_USERNAME_FIELD, $username);

        return 'success';

    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, mailcow_sanitizeParamsForLog($params), $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Sospende un account.
 *
 * @param array $params
 * @return string
 */
function mailcow_SuspendAccount(array $params)
{
    try {
        // Step 1: Sospende il dominio
        $domain = $params['domain'];
        $data_domain = ['attr' => ['active' => '0'], 'items' => [$domain]];
        mailcow_API_Call($params, 'edit/domain', 'POST', $data_domain);

        // Step 2: Sospende l'amministratore del dominio, folosind câmpul intern
        $adminUsername = $params['customfields'][MAILCOW_INTERNAL_USERNAME_FIELD] ?? $params['username'];
        if (!empty($adminUsername)) {
            $data_admin = [
                'items' => [$adminUsername],
                'attr' => ['active' => '0']
            ];
            mailcow_API_Call($params, 'edit/domain-admin', 'POST', $data_admin);
        }

        logModuleCall('mailcow', __FUNCTION__, ['domain' => $domain, 'admin' => $adminUsername], 'Successo', null, []);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, mailcow_sanitizeParamsForLog($params), $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Riattiva un account.
 *
 * @param array $params
 * @return string
 */
function mailcow_UnsuspendAccount(array $params)
{
    try {
        // Step 1: Riattiva il dominio
        $domain = $params['domain'];
        $data_domain = [
            'attr' => ['active' => '1'],
            'items' => [$domain]
        ];
        mailcow_API_Call($params, 'edit/domain', 'POST', $data_domain);

        // Step 2: Riattiva l'amministratore del dominio, folosind câmpul intern
        $adminUsername = $params['customfields'][MAILCOW_INTERNAL_USERNAME_FIELD] ?? $params['username'];
        if (!empty($adminUsername)) {
            $data_admin = [
                'items' => [$adminUsername],
                'attr' => ['active' => '1']
            ];
            mailcow_API_Call($params, 'edit/domain-admin', 'POST', $data_admin);
        }

        logModuleCall('mailcow', __FUNCTION__, ['domain' => $domain, 'admin' => $adminUsername], 'Successo', null, []);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, mailcow_sanitizeParamsForLog($params), $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Termina un account.
 *
 * @param array $params
 * @return string
 */
function mailcow_TerminateAccount(array $params)
{
    try {
        // Step 1: Termina l'amministratore del dominio, folosind câmpul intern
        $adminUsername = $params['customfields'][MAILCOW_INTERNAL_USERNAME_FIELD] ?? $params['username'];
        if (!empty($adminUsername)) {
            mailcow_API_Call($params, 'delete/domain-admin', 'POST', [$adminUsername]);
        }

        // Step 2: Termina il dominio e le sue caselle di posta
        $domain = $params['domain'];
        $mailboxes = mailcow_API_Call($params, 'get/mailbox/all/' . $domain, 'GET');

        if (!empty($mailboxes) && is_array($mailboxes)) {
            $usernamesToDelete = array_column($mailboxes, 'username');
            if (!empty($usernamesToDelete)) {
                mailcow_API_Call($params, 'delete/mailbox', 'POST', $usernamesToDelete);
            }
        }
        mailcow_API_Call($params, 'delete/domain', 'POST', [$domain]);

        // Step 3: Pulisce i dati di login dal servizio in WHMCS
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->update([
                'username' => '',
                'password' => '',
            ]);

        // Step 4: Pulisce il campo personalizzato interno
        mailcow_updateCustomFieldValue($params['serviceid'], MAILCOW_INTERNAL_USERNAME_FIELD, '');

        logModuleCall('mailcow', __FUNCTION__, ['domain' => $domain, 'admin' => $adminUsername], 'Successo, dati di login puliti.', null, []);
        return 'success';

    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, ['domain' => $domain], $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Modifica il pacchetto (utilizzato per upgrade/downgrade).
 *
 * @param array $params
 * @return string
 */
function mailcow_ChangePackage(array $params)
{
    try {
        // Modifica le opzioni del dominio
        $domain = $params['domain'];
        $configOptions = $params['configoptions'];

        $quota = (int) ($configOptions['Quota Totale Dominio'] ?? 5120);
        $maxAccounts = (int) ($configOptions['Max Caselle'] ?? 5);
        $maxAliases = (int) ($configOptions['Max Alias'] ?? 10);

        $data_domain = [
            'attr' => [
                'maxquota' => (string) $quota,
                'quota' => (string) $quota,
                'mailboxes' => (string) $maxAccounts,
                'aliases' => (string) $maxAliases,
            ],
            'items' => [$domain]
        ];
        mailcow_API_Call($params, 'edit/domain', 'POST', $data_domain);

        logModuleCall('mailcow', __FUNCTION__, ['domain' => $domain], 'Successo', null, []);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, mailcow_sanitizeParamsForLog($params), $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Modifica la password dell'amministratore di dominio.
 *
 * @param array $params
 * @return string
 */
function mailcow_ChangePassword(array $params)
{
    try {
        // Folosește câmpul intern pentru a găsi username-ul corect
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
            throw new Exception("Username o nuova password mancanti.");
        }

        logModuleCall('mailcow', __FUNCTION__, ['admin' => $adminUsername], 'Successo', null, []);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, mailcow_sanitizeParamsForLog($params), $e->getMessage(), null, []);
        return $e->getMessage();
    }
}

/**
 * Sincronizza il nuovo username da WHMCS a Mailcow.
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
            throw new Exception("Username-ul vechi (intern) nu a fost găsit. Imposibil de sincronizat.");
        }
        if (empty($newUsername)) {
            throw new Exception("Noul username nu poate fi gol.");
        }
        if ($oldUsername === $newUsername) {
            return "Success"; // Nu este necesară nicio acțiune
        }

        $data_sync = [
            'items' => [$oldUsername],
            'attr' => ['username_new' => $newUsername]
        ];
        mailcow_API_Call($params, 'edit/domain-admin', 'POST', $data_sync);

        // Actualizează câmpul intern cu noul username
        mailcow_updateCustomFieldValue($params['serviceid'], MAILCOW_INTERNAL_USERNAME_FIELD, $newUsername);

        logModuleCall('mailcow', __FUNCTION__, ['old' => $oldUsername, 'new' => $newUsername], 'Successo', null, []);
        return 'success';

    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, mailcow_sanitizeParamsForLog($params), $e->getMessage(), null, []);
        return $e->getMessage();
    }
}


/**
 * Testa la connessione al server.
 *
 * @param array $params
 * @return array
 */
function mailcow_TestConnection(array $params)
{
    $success = true;
    $errorMsg = '';
    try {
        mailcow_API_Call($params, 'get/status/vmail', 'GET');
    } catch (Exception $e) {
        $success = false;
        $errorMsg = $e->getMessage();
        logModuleCall('mailcow', __FUNCTION__, mailcow_sanitizeParamsForLog($params), $e->getMessage(), null, []);
    }
    return ['success' => $success, 'error' => $errorMsg];
}


