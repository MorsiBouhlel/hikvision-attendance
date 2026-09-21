# Hikvision Attendance — Starter Symfony

Système de gestion de présence multi-pointeuses (DS-K1T341CMF ou compatibles ISAPI),
réception des pointages (empreinte + visage) en temps réel via webhook, calcul
automatique entrée/sortie par employé et par jour.

## Architecture

```
[Pointeuse 1] ─┐
[Pointeuse 2] ─┼─► POST /api/hikvision/webhook/{token}  ─► AttendanceEvent (raw)
[Pointeuse N] ─┘                                                │
                                                                  ▼
                                                  AttendanceService::dailySummary()
                                                                  │
                                                                  ▼
                                                  GET /api/attendance/today
```

- **Device** : une pointeuse (IP, identifiants admin, token webhook unique)
- **Employee** : un employé
- **DeviceEmployee** (pivot) : le `employeeNo` d'un employé *sur un device précis*
  (peut différer d'une pointeuse à l'autre si enregistré séparément)
- **AttendanceEvent** : chaque pointage brut reçu du webhook (dédupliqué par
  `serialNo`)

## Installation

```bash
composer create-project symfony/skeleton hikvision-attendance
cd hikvision-attendance
composer require symfony/orm-pack symfony/http-client doctrine/doctrine-migrations-bundle

# copier les fichiers de ce starter par-dessus
cp -r ../hikvision-attendance-symfony/src/* src/

# configurer DATABASE_URL dans .env
php bin/console doctrine:database:create
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

Ajoute dans `.env` (ou `.env.local`) :
```
APP_BASE_URL=http://ton-serveur.local:8000
```

Injecte-la dans `config/services.yaml` :
```yaml
services:
    App\Command\RegisterDeviceWebhookCommand:
        arguments:
            $appBaseUrl: '%env(APP_BASE_URL)%'
```

Les routes sont déclarées en attributs directement dans les contrôleurs — assure-toi
que `config/routes.yaml` importe bien les attributs (c'est le défaut dans le skeleton
Symfony récent) :
```yaml
controllers:
    resource:
        path: ../src/Controller/
        namespace: App\Controller
    type: attribute
```

## Utilisation

```bash
# Enregistrer le webhook sur chaque pointeuse (une fois par device)
php bin/console app:hikvision:register-webhook 1

# Synchroniser les employés depuis une pointeuse existante (optionnel)
php bin/console app:hikvision:sync-employees 1
```

Créer un device (le plus simple : une fixture, une commande maison, ou via
`doctrine:query:sql`) :
```sql
INSERT INTO devices (name, site, ip_address, port, admin_user, admin_password, webhook_token, is_active)
VALUES ('Entrée principale', 'Siège Tunis', '192.168.1.11', 80, 'admin', 'TON_MOT_DE_PASSE', 'genere-un-token-aleatoire', 1);
```

## Points d'attention

1. **Le serveur Symfony doit être joignable en HTTP depuis chaque pointeuse**
   (même réseau local ou VPN). Commence en HTTP interne, pas besoin de HTTPS
   pour un flux LAN.
2. **Format du payload webhook** : JSON pur ou multipart avec photo jointe
   selon le type d'événement (face vs empreinte vs carte). Le contrôleur gère
   les deux — mais **les noms de clés exacts dépendent de ton firmware**.
   Fais un pointage test, regarde `raw_payload` en base, et ajuste
   `HikvisionWebhookController::storeEvent()` si besoin.
3. **Idempotence** : chaque event a un `serialNo` — on fait un
   `findOneBy(['device' => ..., 'serialNo' => ...])` avant de persister pour
   éviter les doublons en cas de retry réseau côté device.
4. **Sécurité du endpoint webhook** : le token dans l'URL
   (`/api/hikvision/webhook/{token}`) sert de secret puisque les pointeuses
   ne savent pas faire d'auth Bearer sortante facilement. Garde-le aléatoire
   et non-devinable (c'est le cas par défaut, généré dans le constructeur de
   `Device`).
5. **Digest Auth maison** : `HikvisionDigestClient` implémente le handshake
   RFC 2617 à la main (Symfony HttpClient n'a pas de digest auth intégré,
   contrairement à Guzzle). Si tu préfères, le bundle
   `kriswallsmith/buzz` ou une lib dédiée peut remplacer cette implémentation.
