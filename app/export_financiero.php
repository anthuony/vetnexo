<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) { http_response_code(401); exit('Sesión requerida'); }
require_once 'config/database.php'; $db=getDBConnection();
$start=$_GET['start_date']??date('Y-m-01');$end=$_GET['end_date']??date('Y-m-d');$day=$_GET['fecha_dia']??date('Y-m-d');$period=$_GET['periodo']??'month';
$valid=function($d){$x=DateTime::createFromFormat('Y-m-d',$d);return $x&&$x->format('Y-m-d')===$d;};
if($period==='day'){$start=$end=$day;} if(!$valid($start)||!$valid($end)||$start>$end){$start=date('Y-m-01');$end=date('Y-m-d');}
$q=$db->prepare("SELECT COALESCE(SUM(total),0) FROM facturas WHERE estado='paid' AND fecha_emision BETWEEN ? AND ?");$q->execute([$start.' 00:00:00',$end.' 23:59:59']);$facturas=(float)$q->fetchColumn();
$q=$db->prepare('SELECT COALESCE(SUM(monto),0) FROM ingresos_diarios WHERE fecha BETWEEN ? AND ?');$q->execute([$start,$end]);$ingresosDiarios=(float)$q->fetchColumn();
$q=$db->prepare("SELECT COALESCE(SUM(monto),0) FROM egresos WHERE estado='pagado' AND fecha BETWEEN ? AND ?");$q->execute([$start,$end]);$egresos=(float)$q->fetchColumn();
// Evita sumar ingresos_diarios con facturas como si fueran necesariamente fuentes independientes: se exportan separados.
$fact=$db->prepare("SELECT numero_factura,fecha_emision,total,metodo_pago FROM facturas WHERE estado='paid' AND fecha_emision BETWEEN ? AND ? ORDER BY fecha_emision");$fact->execute([$start.' 00:00:00',$end.' 23:59:59']);
$egr=$db->prepare("SELECT fecha,categoria,proveedor,descripcion,monto,metodo_pago,numero_factura FROM egresos WHERE estado='pagado' AND fecha BETWEEN ? AND ? ORDER BY fecha");$egr->execute([$start,$end]);
function safeCsv($v){$v=(string)$v;if(preg_match('/^[=+\-@]/',$v))$v="'".$v;return $v;}
header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename="finanzas_'.$start.'_a_'.$end.'.csv"');echo "\xEF\xBB\xBF";$o=fopen('php://output','w');
fputcsv($o,['REPORTE FINANCIERO',$start.' a '.$end],';');fputcsv($o,['Facturas cobradas',number_format($facturas,2,'.','')],';');fputcsv($o,['Ingresos diarios registrados',number_format($ingresosDiarios,2,'.','')],';');fputcsv($o,['Egresos pagados',number_format($egresos,2,'.','')],';');fputcsv($o,['Resultado sobre facturas cobradas',number_format($facturas-$egresos,2,'.','')],';');fputcsv($o,[], ';');
fputcsv($o,['FACTURAS COBRADAS'], ';');fputcsv($o,['Número','Fecha','Total','Método'], ';');foreach($fact as $r)fputcsv($o,array_map('safeCsv',array_values($r)),';');fputcsv($o,[], ';');
fputcsv($o,['EGRESOS PAGADOS'], ';');fputcsv($o,['Fecha','Categoría','Proveedor','Descripción','Monto','Método','Factura'], ';');foreach($egr as $r)fputcsv($o,array_map('safeCsv',array_values($r)),';');fclose($o);exit;
