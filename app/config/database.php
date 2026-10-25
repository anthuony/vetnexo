<?php
require_once __DIR__ . '/bootstrap.php';
/**
 * config/database.php
 * Configuración centralizada para VetNexo.
 *
 * Para hosting: copie config/env.example.php como config/env.php y coloque los datos
 * reales de MySQL del panel de hosting/cPanel.
 */

if (!defined('AUTOVET_ROOT')) {
    define('AUTOVET_ROOT', dirname(__DIR__));
}

$defaultConfig = [
    'db_host' => getenv('AUTOVET_DB_HOST') ?: 'localhost',
    'db_port' => getenv('AUTOVET_DB_PORT') ?: '3306',
    'db_name' => getenv('AUTOVET_DB_NAME') ?: 'vetnexo',
    'db_user' => getenv('AUTOVET_DB_USER') ?: 'root',
    'db_pass' => getenv('AUTOVET_DB_PASS') ?: '',
    'app_env' => getenv('AUTOVET_APP_ENV') ?: 'production',
    'app_debug' => filter_var(getenv('AUTOVET_APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
    'app_url' => getenv('AUTOVET_APP_URL') ?: '',
];

$envFile = __DIR__ . '/env.php';
if (is_file($envFile)) {
    $envConfig = require $envFile;
    if (is_array($envConfig)) {
        $defaultConfig = array_merge($defaultConfig, $envConfig);
    }
}

foreach (['db_host','db_port','db_name','db_user','db_pass','app_env'] as $key) { $value=getenv('AUTOVET_'.strtoupper($key)); if($value!==false) $defaultConfig[$key]=$value; }
$GLOBALS['AUTOVET_CONFIG'] = $defaultConfig;

if (!$defaultConfig['app_debug']) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

if (!class_exists('Database')) {
    class Database {
        private string $host;
        private string $port;
        private string $db_name;
        private string $username;
        private string $password;
        private ?PDO $pdo = null;
        public ?PDO $conn = null;
        private ?mysqli $mysqli = null;

        public function __construct(?array $config = null) {
            $config = $config ?: ($GLOBALS['AUTOVET_CONFIG'] ?? []);
            $this->host = (string)($config['db_host'] ?? 'localhost');
            $this->port = (string)($config['db_port'] ?? '3306');
            $this->db_name = (string)($config['db_name'] ?? 'vetnexo');
            $this->username = (string)($config['db_user'] ?? 'root');
            $this->password = (string)($config['db_pass'] ?? '');
        }

        public function getConnection(): PDO { return $this->conectar(); }

        public function conectar(): PDO {
            if ($this->pdo instanceof PDO) {
                return $this->pdo;
            }

            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4";
            try {
                $this->pdo = new PDO($dsn, $this->username, $this->password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                 $this->conn = $this->pdo;
                $this->pdo->exec("SET time_zone = '-04:00'");
                return $this->pdo;
            } catch (PDOException $e) {
                error_log('[VetNexo][PDO] ' . $e->getMessage());
                if (!empty($GLOBALS['AUTOVET_CONFIG']['app_debug'])) {
                    throw $e;
                }
                http_response_code(500);
                die('No se pudo conectar con MySQL. Inicie MySQL y ejecute instalar.php. Revise config/env.php si cambió el puerto o la contraseña.');
            }
        }

        public function conectarMySQLi(): mysqli {
            if ($this->mysqli instanceof mysqli) {
                return $this->mysqli;
            }

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            try {
                $this->mysqli = new mysqli($this->host, $this->username, $this->password, $this->db_name, (int)$this->port);
                $this->mysqli->set_charset('utf8mb4');
                return $this->mysqli;
            } catch (mysqli_sql_exception $e) {
                error_log('[VetNexo][MySQLi] ' . $e->getMessage());
                if (!empty($GLOBALS['AUTOVET_CONFIG']['app_debug'])) {
                    throw $e;
                }
                http_response_code(500);
                die('No se pudo conectar con MySQL. Inicie MySQL y ejecute instalar.php. Revise config/env.php si cambió el puerto o la contraseña.');
            }
        }
    }
}

if (!function_exists('getDBConnection')) {
    function getDBConnection(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        $database = new Database();
        $pdo = $database->conectar();
        return $pdo;
    }
}

if (!function_exists('normalizarSesionAutoVet')) {
    function normalizarSesionAutoVet(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
            $_SESSION['usuario_id'] = $_SESSION['user_id'];
        }
        if (isset($_SESSION['usuario_id']) && !isset($_SESSION['user_id'])) {
            $_SESSION['user_id'] = $_SESSION['usuario_id'];
        }
        if (isset($_SESSION['nombre']) && !isset($_SESSION['usuario_nombre'])) {
            $_SESSION['usuario_nombre'] = $_SESSION['nombre'];
        }
        if (isset($_SESSION['usuario_nombre']) && !isset($_SESSION['nombre'])) {
            $_SESSION['nombre'] = $_SESSION['usuario_nombre'];
        }
        if (isset($_SESSION['rol']) && !isset($_SESSION['usuario_rol'])) {
            $_SESSION['usuario_rol'] = $_SESSION['rol'];
        }
        if (isset($_SESSION['usuario_rol']) && !isset($_SESSION['rol'])) {
            $_SESSION['rol'] = $_SESSION['usuario_rol'];
        }
        if (isset($_SESSION['email']) && !isset($_SESSION['usuario_email'])) {
            $_SESSION['usuario_email'] = $_SESSION['email'];
        }
        if (isset($_SESSION['usuario_email']) && !isset($_SESSION['email'])) {
            $_SESSION['email'] = $_SESSION['usuario_email'];
        }
    }
}

if (!function_exists('autovet_current_user_id')) {
    function autovet_current_user_id(): ?int {
        normalizarSesionAutoVet();
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}

if (!function_exists('autovet_require_login')) {
    function autovet_require_login(string $loginPath = 'login.php'): void {
        normalizarSesionAutoVet();
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . $loginPath);
            exit();
        }
    }
}

if (!function_exists('autovet_require_roles')) {
    function autovet_require_roles(array $roles, string $loginPath = 'login.php'): void {
        autovet_require_login($loginPath);
        $rol = $_SESSION['rol'] ?? '';
        if (!in_array($rol, $roles, true)) {
            http_response_code(403);
            die('Acceso no autorizado.');
        }
    }
}

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

normalizarSesionAutoVet();

// Compatibilidad con archivos antiguos que esperan $conn global.
if (!isset($conn) || !($conn instanceof PDO)) {
    try {
        $conn = getDBConnection();
    } catch (Throwable $e) {
        // La conexión se reporta dentro de Database::conectar().
        throw $e;
    }
}

require_once __DIR__.'/validacion.php';
