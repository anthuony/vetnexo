<?php
require_once __DIR__ . '/bootstrap.php';
/**
 * Controles de acceso compartidos de VetNexo.
 * Mantiene compatibilidad con las variables de sesión antiguas y actuales.
 */

if (!function_exists('auth_start_session')) {
    function auth_start_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    function auth_normalize_role(?string $role): string
    {
        $role = strtolower(trim((string)$role));
        $map = [
            'veterinario' => 'veterinarian',
            'veterinarian' => 'veterinarian',
            'asistente' => 'assistant',
            'assistant' => 'assistant',
            'recepcion' => 'reception',
            'recepción' => 'reception',
            'recepcionista' => 'reception',
            'reception' => 'reception',
            'administrador' => 'admin',
            'admin' => 'admin',
            'cliente' => 'cliente',
            'client' => 'cliente',
        ];
        return $map[$role] ?? $role;
    }

    function auth_current_role(): string
    {
        auth_start_session();
        return auth_normalize_role($_SESSION['rol'] ?? $_SESSION['usuario_rol'] ?? '');
    }

    function auth_user_id(): int
    {
        auth_start_session();
        return (int)($_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? 0);
    }

    function auth_fail(int $status, string $message, bool $json = false): never
    {
        http_response_code($status);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        } else {
            $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $prefix = av_url();
            if ($status === 401) {
                header('Location: ' . $prefix . 'login.php');
            } else {
                echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Acceso denegado</title>'
                    . '<body style="font-family:Arial;padding:40px"><h1>Acceso denegado</h1><p>'
                    . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
                    . '</p><p><a href="' . $prefix . 'dashboard.php">Volver</a></p></body></html>';
            }
        }
        exit;
    }

    function require_login(bool $json = false): void
    {
        if (auth_user_id() > 0) {
            require_once __DIR__.'/database.php';
            $q=getDBConnection()->prepare('SELECT id,estado,rol,nombre,email,cliente_id FROM usuarios WHERE id=?');
            $q->execute([auth_user_id()]); $u=$q->fetch();
            if(!$u || $u['estado']!=='active'){unset($_SESSION['user_id'],$_SESSION['usuario_id'],$_SESSION['rol'],$_SESSION['usuario_rol'],$_SESSION['cliente_id']);}
            else {$_SESSION['rol']=$_SESSION['usuario_rol']=$u['rol'];$_SESSION['nombre']=$_SESSION['usuario_nombre']=$u['nombre'];$_SESSION['cliente_id']=$u['cliente_id'];}
        }
        if (auth_user_id() <= 0) {
            auth_fail(401, 'Debe iniciar sesión.', $json);
        }
    }

    function require_staff(bool $json = false): void
    {
        require_login($json);
        $role = auth_current_role();
        if (!in_array($role, ['admin', 'veterinarian', 'assistant', 'reception'], true)) {
            auth_fail(403, 'No tiene permisos para acceder a este módulo.', $json);
        }
    }

    function require_admin(bool $json = false): void
    {
        require_login($json);
        if (auth_current_role() !== 'admin') {
            auth_fail(403, 'Este módulo requiere permisos de administrador.', $json);
        }
    }
}
