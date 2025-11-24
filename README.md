# SMS Gateway pour Free Mobile

Interface web PHP permettant d'envoyer des SMS via l'API Free Mobile sans exposer son numéro de téléphone.  
Le formulaire est protégé par reCAPTCHA v2 pour éviter le spam.

Merci de bien vouloir lire l'ensemble de ce document AVANT de vous lancer dans l'installation.

## À quoi ça sert

Ce script permet de créer un point d'entrée web public pour recevoir des SMS sur un mobile Free sans que l'expéditeur ait besoin de connaître le numéro de téléphone du destinataire. 

Cas d'usage typiques :
- Formulaire de contact "envoyez-moi un SMS" sur un site personnel
- Point de contact asynchrone sans diffuser son numéro de téléphone
- Service de notification accessible publiquement

Le message reçu est automatiquement préfixé par "De: [nom expéditeur]" pour identifier l'origine du message.

## Pré-requis système

### Serveur

- Serveur Linux (Debian/Ubuntu recommandé)
- Apache2 avec support PHP
- PHP 7.4 ou supérieur
- Extension PHP curl
- Certbot pour les certificats SSL (reCAPTCHA impose HTTPS en production)

Installation des dépendances sur Debian/Ubuntu :
```bash
apt install apache2 php php-curl certbot python3-certbot-apache
```

### Services externes

**Compte Free Mobile** : 
L'option "Notifications par SMS" doit être activée dans l'interface abonné Free (Gérer mon compte > Mes Options > Notifications par SMS). 
Cette option fournit un identifiant utilisateur et une clé API nécessaires au fonctionnement du script.

**Clé reCAPTCHA v2** : À obtenir sur https://www.google.com/recaptcha/admin
- Type : reCAPTCHA v2 avec case à cocher "Je ne suis pas un robot"
- Domaine : Déclarer le domaine qui hébergera le formulaire (exemple : sms.example.com)

## Installation simplifié

Si vous faites déjà tourner un site web et que vous souhaitez utiliser le script simplement dans un répertoire l'installation plus simple.

### 1. Création du répertoire web
```bash
mkdir /var/www/html/sms
chown -R www-data:www-data /var/www/html/sms
```

Placer le fichier `index.php` dans ce répertoire.

## Installation détaillé

### 1. Création du répertoire web
```bash
mkdir -p /var/www/sms
chown -R www-data:www-data /var/www/sms
```

Placer le fichier `index.php` dans ce répertoire.

### 2. Configuration du VirtualHost Apache2

Créer le fichier `/etc/apache2/sites-available/sms.example.com.conf` :
```apache
<VirtualHost *:80>
    ServerName sms.example.com
    ServerAdmin admin@example.com
    
    DocumentRoot /var/www/sms
    
    <Directory /var/www/sms>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/sms-error.log
    CustomLog ${APACHE_LOG_DIR}/sms-access.log combined
</VirtualHost>
```

**Note** : Pour binder sur une IP spécifique, remplacer `*:80` par `ADRESSE_IP:80`.

Activer le site :
```bash
a2ensite sms.example.com
systemctl reload apache2
```

### 3. Configuration DNS

Créer un enregistrement A pointant vers l'IP du serveur :
```
sms.example.com.  IN  A  XXX.XXX.XXX.XXX
```

Attendre la propagation DNS (quelques minutes à quelques heures selon les cas).

### 4. Génération du certificat SSL
```bash
certbot --apache -d sms.example.com
```

Certbot modifiera automatiquement le VirtualHost pour activer HTTPS et forcer la redirection depuis HTTP.

## Configuration

Pour éviter d'exposer les credentials dans le webroot, créer un fichier de configuration séparé.

### Étape 1 : Créer le fichier de configuration
```bash
mkdir -p /etc/sms-config
nano /etc/sms-config/config.php
```

Contenu de `/etc/sms-config/config.php` :
```php
<?php
// Configuration API Free Mobile
define('FREE_USER', 'IDENTIFIANT_FREE');
define('FREE_PASS', 'CLE_API_FREE');

// Configuration reCAPTCHA
define('RECAPTCHA_SITEKEY', 'SITEKEY_RECAPTCHA');
define('RECAPTCHA_SECRET', 'SECRET_RECAPTCHA');
?>
```

### Étape 2 : Sécuriser les permissions
```bash
chown root:www-data /etc/sms-config/config.php
chmod 640 /etc/sms-config/config.php
```

Ces permissions garantissent que seuls root et le processus Apache (www-data) peuvent lire le fichier.

## Fonctionnement technique

### Workflow

1. **Affichage du formulaire** : L'utilisateur accède à la page et remplit le formulaire (nom d'expéditeur et message texte)
2. **Validation reCAPTCHA** : Le visiteur valide le challenge reCAPTCHA
3. **Soumission** : Le formulaire est envoyé en POST avec les données et le token reCAPTCHA
4. **Vérification serveur** : Le script PHP vérifie le token auprès de l'API Google
5. **Envoi SMS** : Si la vérification réussit, le message est transmis à l'API Free Mobile
6. **Retour utilisateur** : Un message de succès ou d'erreur est affiché

### Architecture de sécurité

**Protection anti-spam** : Le reCAPTCHA v2 avec validation serveur empêche les soumissions automatisées. 
La validation côté serveur est cruciale car une validation uniquement JavaScript peut être contournée.

Bien entendu ce système peut être facilement bypasser pour envoyer un nombre massif de SMS (pas très grave en soit mais dérangeant).
La solution consistera à s'orienter vers une authentification plus poussée avec, par exemple, un fichier .htaccess dans le répertoire du script :


**Gestion des erreurs API** : Le script gère tous les codes retour de l'API Free Mobile :
- 200 : SMS envoyé avec succès
- 400 : Paramètre manquant dans la requête
- 402 : Trop de SMS envoyés en trop peu de temps (rate limiting)
- 403 : Service non activé ou credentials incorrects
- 500 : Erreur serveur Free Mobile

**Limitation de longueur** : Le champ message est limité à 918 caractères côté HTML (limite approximative de 6 SMS). Le préfixe "De: [expéditeur]" est ajouté automatiquement et compte dans cette limite.

### Communication avec les APIs

**API reCAPTCHA** : Requête POST vers `https://www.google.com/recaptcha/api/siteverify` avec les paramètres :
- `secret` : Clé secrète reCAPTCHA
- `response` : Token généré par le widget JavaScript
- `remoteip` : IP du client (optionnel mais recommandé)

**API Free Mobile** : Requête GET vers `https://smsapi.free-mobile.fr/sendmsg` avec les paramètres :
- `user` : Identifiant utilisateur Free
- `pass` : Clé API Free
- `msg` : Message encodé en URL (percent-encoding)

Le script utilise curl pour ces deux appels. L'extension PHP curl est donc obligatoire.

## Partage et distribution

Pour distribuer ce script, il est recommandé de fournir un fichier `config.example.php` :
```php
<?php
// Configuration API Free Mobile
define('FREE_USER', 'VOTRE_IDENTIFIANT_FREE');
define('FREE_PASS', 'VOTRE_CLE_API_FREE');

// Configuration reCAPTCHA
define('RECAPTCHA_SITEKEY', 'VOTRE_SITEKEY');
define('RECAPTCHA_SECRET', 'VOTRE_SECRET');
?>
```

Les utilisateurs copient ce fichier vers `/etc/sms-config/config.php` et renseignent leurs propres valeurs.

## Dépannage

### Erreur "Call to undefined function curl_init()"

L'extension PHP curl n'est pas installée :
```bash
apt install php-curl
systemctl restart apache2
```

### Erreur "ERREUR pour le propriétaire du site : Type de clé non valide"

La clé reCAPTCHA utilisée est de type v3 au lieu de v2. Créer une nouvelle clé de type v2 avec case à cocher sur https://www.google.com/recaptcha/admin.

### Erreur "ERREUR pour le propriétaire du site : Clé de site non valide"

Vérifier que :
- La clé de site (SITEKEY) est correctement renseignée dans le HTML
- Le domaine déclaré dans la console Google reCAPTCHA correspond exactement au domaine utilisé
- Le cache navigateur a été vidé après modification des clés

### Erreur 403 de l'API Free Mobile

- Vérifier que l'option "Notifications par SMS" est bien activée dans l'interface Free
- Vérifier l'identifiant utilisateur et la clé API
- Tester l'envoi manuel via curl pour isoler le problème :
```bash
curl "https://smsapi.free-mobile.fr/sendmsg?user=XXXXX&pass=XXXXX&msg=test"
```

## Limitations connues

**Rate limiting basique** : Le système actuel limite à 10 SMS par heure par IP. Cette protection est basique et utilise des fichiers temporaires en `/tmp/`. Pour une protection plus robuste, voir la section TODO ci-dessous.

**Pas de protection CSRF** : Le formulaire n'implémente pas de tokens CSRF. Pour un usage personnel avec peu de trafic, le reCAPTCHA suffit généralement.

**Stockage des rate limits** : Les fichiers en `/tmp/` peuvent être supprimés au redémarrage du serveur. Les limites sont alors réinitialisées.

**Caractères autorisés** : Le champ expéditeur accepte uniquement les caractères alphanumériques (ASCII + accents français), espaces, tirets, underscores et points. Les émojis et caractères Unicode étendus sont filtrés.

## TODO / Améliorations futures

- [ ] Implémenter un rate limiting avec SQLite pour persistance
- [ ] Ajouter des tokens CSRF pour protection supplémentaire
- [ ] Logger les tentatives d'envoi avec horodatage et IP
- [ ] Implémenter un système de blacklist IP
- [ ] Ajouter une interface d'administration basique
- [ ] Support des émojis dans le champ expéditeur
- [ ] Monitoring et alertes en cas d'abus détecté
- [ ] Tests automatisés (PHPUnit)

## Sécurité

**ATTENTION** : Ce script expose un point d'entrée public pour envoyer des SMS. Bien que protégé par reCAPTCHA, il est recommandé de :
- Surveiller les logs Apache pour détecter des abus
- Mettre en place un rate limiting supplémentaire si nécessaire (mod_evasive, fail2ban)
- Ne jamais commiter les fichiers de configuration contenant les credentials dans un dépôt public
- Régénérer les clés API après tout partage public du code

**Note** : Free Mobile impose ses propres limites de débit sur l'API. En cas d'abus, le service peut temporairement bloquer l'envoi de SMS (erreur 402).

## Licence

Creative Commons Version 1.0
