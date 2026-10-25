<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) { http_response_code(401); exit('Sesión requerida'); }
require_once 'config/database.php'; $db=getDBConnection();
function csvSafe($v){$v=(string)$v; if(preg_match('/^[=+\-@]/',$v))$v="'".$v; return $v;}
$sql='SELECT e.id,e.fecha,e.categoria,e.proveedor,e.descripcion,e.monto,e.metodo_pago,e.numero_factura,e.estado,u.nombre usuario FROM egresos e LEFT JOIN usuarios u ON u.id=e.usuario_id ORDER BY e.fecha DESC,e.id DESC';
$rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$fn='egresos_'.date('Ymd_His').'.csv'; header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename="'.$fn.'"');echo "\xEF\xBB\xBF";$o=fopen('php://output','w');
fputcsv($o,['ID','Fecha','Categoría','Proveedor','Descripción','Monto','Método de pago','N° factura','Estado','Registrado por'],';');
foreach($rows as $r){fputcsv($o,array_map('csvSafe',array_values($r)),';');}fclose($o);exit;
