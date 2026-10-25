<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

include 'config/database.php';

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

try {
    $db = getDBConnection();
    
    // Obtener datos para exportar
    $sql = "
        (SELECT 
            'factura' as tipo,
            DATE(f.fecha_emision) as fecha,
            CONCAT('Factura ', f.numero_factura) as origen,
            fd.descripcion,
            fd.subtotal as monto,
            CASE 
                WHEN s.categoria = 'grooming' THEN 'peluqueria'
                WHEN s.categoria IN ('consultation', 'vaccination', 'surgery', 'emergency', 'other') THEN 'servicios_veterinarios'
                WHEN s.categoria = 'laboratory' THEN 'laboratorio'
                ELSE 'servicios_veterinarios'
            END as categoria
        FROM factura_detalles fd
        LEFT JOIN servicios s ON fd.servicio_id = s.id
        LEFT JOIN facturas f ON fd.factura_id = f.id
        WHERE DATE(f.fecha_emision) BETWEEN ? AND ?
        AND f.estado IN ('paid', 'completed')
        AND fd.servicio_id IS NOT NULL)
        
        UNION ALL
        
        (SELECT 
            'factura' as tipo,
            DATE(f.fecha_emision) as fecha,
            CONCAT('Factura ', f.numero_factura) as origen,
            fd.descripcion,
            fd.subtotal as monto,
            'tienda' as categoria
        FROM factura_detalles fd
        LEFT JOIN productos p ON fd.producto_id = p.id
        LEFT JOIN facturas f ON fd.factura_id = f.id
        WHERE DATE(f.fecha_emision) BETWEEN ? AND ?
        AND f.estado IN ('paid', 'completed')
        AND fd.producto_id IS NOT NULL)
        
        UNION ALL
        
        (SELECT 
            'manual' as tipo,
            fecha,
            'Manual' as origen,
            descripcion,
            monto,
            categoria
        FROM ingresos_diarios 
        WHERE fecha BETWEEN ? AND ?)
        
        ORDER BY fecha DESC, tipo
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);
    $ingresos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Configurar headers para descarga Excel
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="ingresos_' . $fecha_inicio . '_' . $fecha_fin . '.xls"');
    
    // Crear contenido Excel
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>Fecha</th>";
    echo "<th>Tipo</th>";
    echo "<th>Origen</th>";
    echo "<th>Categoría</th>";
    echo "<th>Descripción</th>";
    echo "<th>Monto</th>";
    echo "</tr>";
    
    $total = 0;
    foreach ($ingresos as $ingreso) {
        echo "<tr>";
        echo "<td>" . $ingreso['fecha'] . "</td>";
        echo "<td>" . $ingreso['tipo'] . "</td>";
        echo "<td>" . $ingreso['origen'] . "</td>";
        echo "<td>" . $ingreso['categoria'] . "</td>";
        echo "<td>" . $ingreso['descripcion'] . "</td>";
        echo "<td>" . number_format($ingreso['monto'], 2) . "</td>";
        echo "</tr>";
        $total += $ingreso['monto'];
    }
    
    echo "<tr>";
    echo "<td colspan='5'><strong>TOTAL</strong></td>";
    echo "<td><strong>" . number_format($total, 2) . "</strong></td>";
    echo "</tr>";
    
    echo "</table>";
    
} catch (PDOException $e) {
    echo "Error al exportar datos: " . $e->getMessage();
}