<?php
// cliente/procesar_registro_mascota.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();

// Verificar que sea cliente
if (!isset($_SESSION['user_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] != 'cliente') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$conn = $database->conectar();

$cliente_id = $_SESSION['cliente_id'];

// Verificar que se envió el formulario
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: mis_mascotas.php");
    exit();
}

// Obtener y limpiar datos
$nombre = trim($_POST['nombre'] ?? '');
$especie_id = isset($_POST['especie_id']) ? (int)$_POST['especie_id'] : 0;
$raza_id = isset($_POST['raza_id']) && !empty($_POST['raza_id']) ? (int)$_POST['raza_id'] : null;
$fecha_nacimiento = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
$sexo = $_POST['sexo'] ?? '';
$color = trim($_POST['color'] ?? '');
$peso = !empty($_POST['peso']) ? (float)$_POST['peso'] : null;
$notas = trim($_POST['notas'] ?? '');

// Validaciones básicas
$errores = [];

if (empty($nombre)) {
    $errores[] = "El nombre de la mascota es obligatorio.";
}

if ($especie_id <= 0) {
    $errores[] = "Debes seleccionar una especie.";
} else {
    // Verificar que la especie exista
    $stmt = $conn->prepare("SELECT id FROM especies WHERE id = ? AND activo = 1");
    $stmt->execute([$especie_id]);
    if (!$stmt->fetch()) {
        $errores[] = "La especie seleccionada no es válida.";
    }
}

if (empty($sexo)) {
    $errores[] = "Debes seleccionar el sexo de la mascota.";
} elseif (!in_array($sexo, ['macho', 'hembra'])) {
    $errores[] = "El sexo seleccionado no es válido.";
}

// Si hay raza seleccionada, verificar que exista y corresponda a la especie
if ($raza_id) {
    $stmt = $conn->prepare("SELECT id FROM razas WHERE id = ? AND especie_id = ? AND activo = 1");
    $stmt->execute([$raza_id, $especie_id]);
    if (!$stmt->fetch()) {
        $errores[] = "La raza seleccionada no es válida para la especie elegida.";
    }
}

// Validar fecha de nacimiento (no puede ser futura)
if ($fecha_nacimiento) {
    $fecha_actual = date('Y-m-d');
    if ($fecha_nacimiento > $fecha_actual) {
        $errores[] = "La fecha de nacimiento no puede ser futura.";
    }
}

// Validar peso (no puede ser negativo)
if ($peso !== null && $peso < 0) {
    $errores[] = "El peso no puede ser negativo.";
}

// Si hay errores, redirigir con mensaje
if (!empty($errores)) {
    $mensaje_error = implode("\\n", $errores);
    header("Location: mis_mascotas.php?error=registro&mensaje=" . urlencode($mensaje_error));
    exit();
}

try {
    // Iniciar transacción
    $conn->beginTransaction();

    // Insertar la mascota
    $stmt = $conn->prepare("
        INSERT INTO mascotas (
            nombre, 
            especie_id, 
            raza, 
            fecha_nacimiento, 
            sexo, 
            color, 
            peso, 
            notas, 
            cliente_id,
            fecha_registro
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    // Obtener nombre de la raza si se seleccionó
    $raza_nombre = null;
    if ($raza_id) {
        $stmt_raza = $conn->prepare("SELECT nombre FROM razas WHERE id = ?");
        $stmt_raza->execute([$raza_id]);
        $raza = $stmt_raza->fetch(PDO::FETCH_ASSOC);
        $raza_nombre = $raza['nombre'];
    }

    $stmt->execute([
        $nombre,
        $especie_id,
        $raza_nombre,
        $fecha_nacimiento,
        $sexo,
        $color ?: null,
        $peso,
        $notas ?: null,
        $cliente_id
    ]);

    $mascota_id = $conn->lastInsertId();

    // Crear notificación para el cliente
    $stmt_notif = $conn->prepare("
        INSERT INTO notificaciones_cliente (
            cliente_id, 
            tipo, 
            titulo, 
            mensaje
        ) VALUES (?, 'promocion', ?, ?)
    ");
    
    $titulo = "¡Mascota registrada!";
    $mensaje = "Has registrado a $nombre exitosamente. Ya puedes agendar citas para tu mascota.";
    
    $stmt_notif->execute([$cliente_id, $titulo, $mensaje]);

    // Commit de la transacción
    $conn->commit();

    // Redirigir con éxito
    header("Location: mis_mascotas.php?success=registrada&id=" . $mascota_id);
    exit();

} catch (PDOException $e) {
    // Rollback en caso de error
    $conn->rollBack();
    
    // Registrar error para depuración
    error_log("Error al registrar mascota: " . $e->getMessage());
    
    // Redirigir con mensaje de error
    header("Location: mis_mascotas.php?error=registro&mensaje=" . urlencode("Error al registrar la mascota. Por favor intenta de nuevo."));
    exit();
}
?>