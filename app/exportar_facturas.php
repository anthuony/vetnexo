<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

include 'config/database.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Obtener parámetros de filtro
$search = $_GET['search'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$estado = $_GET['estado'] ?? '';

// Construir consulta con filtros (similar a facturas.php)
try {
    $db = getDBConnection();
    
    $where_conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(f.numero_factura LIKE ? OR c.nombre LIKE ? OR c.documento_identidad LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if (!empty($fecha_desde)) {
        $where_conditions[] = "DATE(f.fecha_emision) >= ?";
        $params[] = $fecha_desde;
    }
    
    if (!empty($fecha_hasta)) {
        $where_conditions[] = "DATE(f.fecha_emision) <= ?";
        $params[] = $fecha_hasta;
    }
    
    if (!empty($estado)) {
        $where_conditions[] = "f.estado = ?";
        $params[] = $estado;
    }
    
    $where_sql = '';
    if (!empty($where_conditions)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    $sql = "
        SELECT 
            f.*,
            c.nombre as cliente_nombre,
            c.documento_identidad as cliente_identificacion,
            c.telefono as cliente_telefono,
            u.nombre as usuario_nombre,
            COUNT(d.id) as total_items,
            f.subtotal as subtotal,
            COALESCE(SUM(d.descuento), 0) as total_descuento,
            f.impuestos as iva,
            f.total as total
        FROM facturas f
        LEFT JOIN clientes c ON f.cliente_id = c.id
        LEFT JOIN usuarios u ON f.usuario_id = u.id
        LEFT JOIN factura_detalles d ON f.id = d.factura_id
        $where_sql
        GROUP BY f.id
        ORDER BY f.fecha_emision DESC, f.numero_factura DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $facturas = [];
}

// Configurar headers para descarga de Excel
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="facturas_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html>
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <table border="1">
        <thead>
            <tr>
                <th colspan="10" style="background:#4CAF50;color:white;font-size:16px;padding:10px;">
                    HISTORIAL DE FACTURAS - VETNEXO VETNEXO
                </th>
            </tr>
            <tr style="background:#E8F5E9;">
                <th>N° Factura</th>
                <th>Fecha</th>
                <th>Cliente</th>
                <th>Identificación</th>
                <th>Teléfono</th>
                <th>Items</th>
                <th>Subtotal</th>
                <th>IVA</th>
                <th>Total</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($facturas)): ?>
                <?php foreach ($facturas as $factura): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($factura['numero_factura']); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($factura['fecha_emision'])); ?></td>
                        <td><?php echo htmlspecialchars($factura['cliente_nombre'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($factura['cliente_identificacion'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($factura['cliente_telefono'] ?? ''); ?></td>
                        <td><?php echo $factura['total_items'] ?? 0; ?></td>
                        <td>$<?php echo number_format($factura['subtotal'] ?? 0, 2, ',', '.'); ?></td>
                        <td>$<?php echo number_format($factura['iva'] ?? 0, 2, ',', '.'); ?></td>
                        <td>$<?php echo number_format($factura['total'] ?? 0, 2, ',', '.'); ?></td>
                        <td>
                            <?php
                            switch ($factura['estado']) {
                                case 'pagada': echo 'PAGADA'; break;
                                case 'pending': echo 'PENDIENTE'; break;
                                case 'cancelled': echo 'CANCELADA'; break;
                                default: echo 'DESCONOCIDO';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" style="text-align:center;">No hay facturas para exportar</td>
                </tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr style="background:#F8FDF8;">
                <td colspan="6" style="text-align:right;font-weight:bold;">Total General:</td>
                <td style="font-weight:bold;">$<?php 
                    $total_subtotal = array_sum(array_column($facturas, 'subtotal'));
                    echo number_format($total_subtotal, 2, ',', '.');
                ?></td>
                <td style="font-weight:bold;">$<?php 
                    $total_iva = array_sum(array_column($facturas, 'iva'));
                    echo number_format($total_iva, 2, ',', '.');
                ?></td>
                <td style="font-weight:bold;">$<?php 
                    $total_general = array_sum(array_column($facturas, 'total'));
                    echo number_format($total_general, 2, ',', '.');
                ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>