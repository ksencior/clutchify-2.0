<?php
/* Discord Oauth v.4.1
 * This file contains the core functions of the oauth2 script.
 * @author : MarkisDev
 * @copyright : https://markis.dev
 */

# Starting session so we can store all the variables
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

# Setting the base url for API requests
$GLOBALS['base_url'] = "https://discord.com";

# Setting bot token for related requests
$GLOBALS['bot_token'] = null;

# A function to generate a random string to be used as state | (protection against CSRF)
function gen_state()
{
    $_SESSION['discord_oauth_state'] = bin2hex(random_bytes(16));
    return $_SESSION['discord_oauth_state'];
}

# A function to generate oAuth2 URL for logging in
function url($clientid, $redirect, $scope)
{
    $state = gen_state();

    return $GLOBALS['base_url'] . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $clientid,
        'redirect_uri' => $redirect,
        'scope' => $scope,
        'state' => $state
    ]);
}

# A function to initialize and store access token in SESSION to be used for other requests
function init($redirect_url, $client_id, $client_secret, $bot_token = null)
{
    if ($bot_token != null) {
        $GLOBALS['bot_token'] = $bot_token;
    }

    if (!isset($_GET['code'])) {
        throw new Exception('Brak kodu autoryzacji Discord.');
    }

    if (!isset($_GET['state']) || !check_state($_GET['state'])) {
        throw new Exception('Nieprawidłowy state OAuth. Spróbuj połączyć Discord ponownie.');
    }

    $code = $_GET['code'];

    $url = $GLOBALS['base_url'] . "/api/oauth2/token";

    $data = [
        "client_id" => $client_id,
        "client_secret" => $client_secret,
        "grant_type" => "authorization_code",
        "code" => $code,
        "redirect_uri" => $redirect_url
    ];

    $curl = curl_init();

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);

    curl_close($curl);

    if ($response === false) {
        throw new Exception('Błąd połączenia z Discord OAuth: ' . $curlError);
    }

    $results = json_decode($response, true);

    if (empty($results['access_token'])) {
        throw new Exception('Discord nie zwrócił access tokena.');
    }

    $_SESSION['access_token'] = $results['access_token'];

    unset($_SESSION['discord_oauth_state']);
}

# A function to get user information | (identify scope)
function get_user($email = null)
{
    $url = $GLOBALS['base_url'] . "/api/users/@me";
    $headers = array('Content-Type: application/x-www-form-urlencoded', 'Authorization: Bearer ' . $_SESSION['access_token']);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($curl);
    curl_close($curl);
    $results = json_decode($response, true);
    
    # Fetching email 
    if ($email == True) {
        $_SESSION['email'] = $results['email'];
    }

    return $results['id'];
}

# A function to verify if login is legit
function check_state($state)
{
    if (!isset($_SESSION['discord_oauth_state'])) {
        return false;
    }

    return hash_equals($_SESSION['discord_oauth_state'], $state);
}