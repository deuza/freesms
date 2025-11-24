<?php
// Chargement de la configuration
require_once '/etc/sms-config/config.php';

$response = ['success' => false, 'message' => '', 'show_button' => false];

// Fonction de rate limiting simple
function check_rate_limit($ip) {
    $rate_file = '/tmp/sms_rate_' . md5($ip);
    $max_attempts = 10; // 10 SMS max
    $time_window = 3600; // Par heure
    
    if (file_exists($rate_file)) {
        $data = json_decode(file_get_contents($rate_file), true);
        $recent_attempts = array_filter($data, function($timestamp) use ($time_window) {
            return (time() - $timestamp) < $time_window;
        });
        
        if (count($recent_attempts) >= $max_attempts) {
            return false;
        }
        $data[] = time();
    } else {
        $data = [time()];
    }
    
    file_put_contents($rate_file, json_encode($data));
    return true;
}

// Fonction de validation et sanitization
function validate_sender($sender) {
    // Retire newlines et caractères de contrôle
    $sender = preg_replace('/[\r\n\t\0\x0B]/', '', $sender);
    // Limite aux caractères alphanumériques + espaces + quelques ponctuations safe
    $sender = preg_replace('/[^a-zA-Z0-9\sàâäéèêëïîôùûüçÀÂÄÉÈÊËÏÎÔÙÛÜÇ\-_\.]/', '', $sender);
    return trim($sender);
}

function validate_message($message) {
    // Retire caractères de contrôle sauf newline
    $message = preg_replace('/[\r\t\0\x0B]/', '', $message);
    return trim($message);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_ip = $_SERVER['REMOTE_ADDR'];
    
    // Rate limiting
    if (!check_rate_limit($client_ip)) {
        $response['message'] = 'Trop de tentatives. Réessayez dans 1 heure.';
    } else {
        // Récupération et validation des données
        $sender = isset($_POST['sender']) ? validate_sender($_POST['sender']) : '';
        $message = isset($_POST['message']) ? validate_message($_POST['message']) : '';
        $recaptcha_response = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
        
        // Validation stricte côté serveur
        if (empty($sender)) {
            $response['message'] = 'Expéditeur invalide ou vide.';
        } elseif (strlen($sender) > 50) {
            $response['message'] = 'Nom expéditeur trop long (max 50 caractères).';
        } elseif (empty($message)) {
            $response['message'] = 'Message vide.';
        } elseif (strlen($message) > 918) {
            $response['message'] = 'Message trop long (max 918 caractères).';
        } elseif (empty($recaptcha_response)) {
            $response['message'] = 'Veuillez valider le CAPTCHA.';
        } else {
            // Vérification du reCAPTCHA
            $verify_url = "https://www.google.com/recaptcha/api/siteverify";
            $verify_data = [
                'secret' => RECAPTCHA_SECRET,
                'response' => $recaptcha_response,
                'remoteip' => $client_ip
            ];
            
            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-type: application/x-www-form-urlencoded',
                    'content' => http_build_query($verify_data)
                ]
            ];
            
            $context = stream_context_create($options);
            $verify_response = file_get_contents($verify_url, false, $context);
            $captcha_result = json_decode($verify_response);
            
            if (!$captcha_result->success) {
                $response['message'] = 'Échec de la vérification CAPTCHA.';
            } else {
                // Construction du message complet
                $full_message = "De: " . $sender . "\n" . $message;
                
                // Encodage URL
                $encoded_msg = urlencode($full_message);
                
                // Envoi via API Free Mobile
                $api_url = "https://smsapi.free-mobile.fr/sendmsg?user=" . FREE_USER . "&pass=" . FREE_PASS . "&msg=" . $encoded_msg;
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                // Gestion des codes retour
                switch ($http_code) {
                    case 200:
                        $response['success'] = true;
                        $response['message'] = 'SMS envoyé avec succès !';
                        $response['show_button'] = true;
                        break;
                    case 400:
                        $response['message'] = 'Erreur : paramètre manquant.';
                        break;
                    case 402:
                        $response['message'] = 'Erreur : trop de SMS envoyés.';
                        break;
                    case 403:
                        $response['message'] = 'Erreur : service non activé.';
                        break;
                    case 500:
                        $response['message'] = 'Erreur serveur. Réessayez plus tard.';
                        break;
                    default:
                        $response['message'] = 'Erreur inconnue (code ' . $http_code . ').';
                }
            }
        }
    }
}

// Génération position aléatoire pour le bouton (si succès)
$button_top = rand(20, 60); // % de la zone définie
$button_left = rand(20, 70); // % de la zone définie
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Envoyer un SMS à DeuZa</title>
    <style>
        body {
            background-color: #1a1a1a;
            color: #ff3333;
            font-family: 'Arial', sans-serif;
            text-align: center;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        h1 {
            font-size: 3em;
            color: #ff0000;
            text-shadow: 2px 2px 4px #000;
        }
        .message {
            padding: 15px;
            margin: 20px auto;
            width: 50%;
            max-width: 500px;
            border-radius: 10px;
            font-weight: bold;
        }
        .success {
            background-color: #2d5016;
            border: 2px solid #4caf50;
            color: #4caf50;
        }
        .error {
            background-color: #5a1616;
            border: 2px solid #ff0000;
            color: #ff0000;
        }
        form {
            background-color: #1a1a1a;
            padding: 30px;
            border-radius: 15px;
            border: 5px solid #fff;
            box-shadow: 0px 0px 15px 5px #ff0000;
            display: inline-block;
            width: 50%;
            max-width: 500px;
            margin: 20px auto;
            text-align: left;
        }
        textarea, input[type="text"] {
            width: 90%;
            background-color: #444;
            color: #ffcccc;
            border: 3px solid #ff0000;
            padding: 8px;
            border-radius: 10px;
            font-size: 1em;
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 10px;
        }
        button {
            background-color: #ff0000;
            color: #fff;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 10px;
            font-size: 1em;
            font-weight: bold;
            transition: 0.3s;
        }
        button:hover {
            background-color: #cc0000;
        }
        .captcha-container {
            margin: 20px 0;
        }
        .char-counter {
            color: #4caf50;
            font-size: 0.9em;
            margin-top: -15px;
            margin-bottom: 15px;
            text-align: right;
            width: 90%;
        }
        .random-button-zone {
            position: relative;
            height: 300px;
            width: 50%;
            max-width: 500px;
            margin: 20px auto;
            border: 2px dashed #ff0000;
            border-radius: 15px;
        }
        .random-button {
            position: absolute;
            top: <?php echo $button_top; ?>%;
            left: <?php echo $button_left; ?>%;
            transform: translate(-50%, -50%);
        }
        footer {
            background-color: #333;
            color: #fff;
            padding: 10px;
            text-align: center;
            margin-top: auto;
        }
    </style>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>
    <h1>M'envoyer un SMS</h1>
    
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($response['message'])): ?>
        <div class="message <?php echo $response['success'] ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($response['message']); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($response['show_button']): ?>
        <div class="random-button-zone">
            <div class="random-button">
                <button onclick="window.location.reload()">Envoyer un nouveau SMS</button>
            </div>
        </div>
    <?php else: ?>
        <form method="POST">
            <label for="sender">Expéditeur :</label>
            <input type="text" id="sender" name="sender" maxlength="50" placeholder="Nom de l'expéditeur" required><br>
            
            <label for="message">Message :</label>
            <textarea id="message" name="message" rows="10" maxlength="918" placeholder="Votre message..." required></textarea>
            <div class="char-counter">
                <span id="charCount">918</span> caractères restants
            </div>
            <br>
            
            <div class="captcha-container">
                <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITEKEY; ?>"></div>
            </div>
            
            <button type="submit">Envoyer</button>
        </form>
    <?php endif; ?>
    
    <footer>
               -=- Site sous licence Creative Commons Version 1.0 -=- <br>
			-=-    By DeuZa    -=-
    </footer>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const textarea = document.getElementById('message');
        const charCount = document.getElementById('charCount');
        const maxLength = 918;
        
        if (textarea && charCount) {
            textarea.addEventListener('input', function() {
                const remaining = maxLength - this.value.length;
                charCount.textContent = remaining;
                
                // Change la couleur quand ça devient critique
                if (remaining < 50) {
                    charCount.style.color = '#ff0000';
                } else if (remaining < 150) {
                    charCount.style.color = '#ff9900';
                } else {
                    charCount.style.color = '#4caf50';
                }
            });
        }
    });
    </script>
</body>
</html>
