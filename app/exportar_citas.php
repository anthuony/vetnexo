<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();
if(!isset($_SESSION['user_id'])&&!isset($_SESSION['usuario_id'])){http_response_code(401);exit('Sesión requerida');}
require_once 'config/database.php';$db=getDBConnection();
$sql="SELECT c.id,c.fecha_cita,c.hora_cita,c.estado,m.nombre mascota,cl.nombre cliente,s.nombre servicio,u.nombre veterinario,c.costo_total,c.notas FROM citas c LEFT JOIN mascotas m ON m.id=c.mascota_id LEFT JOIN clientes cl ON cl.id=c.cliente_id LEFT JOIN servicios s ON s.id=c.servicio_id LEFT JOIN usuarios u ON u.id=c.veterinario_id WHERE 1=1";$p=[];
if(!empty($_GET['estado'])){$sql.=' AND c.estado=?';$p[]=$_GET['estado'];}if(!empty($_GET['mascota_id'])){$sql.=' AND c.mascota_id=?';$p[]=(int)$_GET['mascota_id'];}if(!empty($_GET['cliente_id'])){$sql.=' AND c.cliente_id=?';$p[]=(int)$_GET['cliente_id'];}if(!empty($_GET['veterinario'])){$sql.=' AND c.veterinario_id=?';$p[]=(int)$_GET['veterinario'];}
if(!empty($_GET['tipo'])){$sql.=' AND s.categoria=?';$p[]=$_GET['tipo'];}if(!empty($_GET['mes'])&&preg_match('/^\d{4}-\d{2}$/',$_GET['mes'])){$sql.=" AND DATE_FORMAT(c.fecha_cita,'%Y-%m')=?";$p[]=$_GET['mes'];}if(!empty($_GET['ano'])&&preg_match('/^\d{4}$/',$_GET['ano'])){$sql.=' AND YEAR(c.fecha_cita)=?';$p[]=$_GET['ano'];}
if(!empty($_GET['fecha_inicio'])){$sql.=' AND DATE(c.fecha_cita)>=?';$p[]=$_GET['fecha_inicio'];}if(!empty($_GET['fecha_fin'])){$sql.=' AND DATE(c.fecha_cita)<=?';$p[]=$_GET['fecha_fin'];}
$bus=trim($_GET['buscar']??$_GET['busqueda']??'');if($bus!==''){$sql.=' AND (m.nombre LIKE ? OR cl.nombre LIKE ? OR s.nombre LIKE ?)';$x='%'.$bus.'%';array_push($p,$x,$x,$x);} $sql.=' ORDER BY c.fecha_cita DESC,c.hora_cita DESC';$st=$db->prepare($sql);$st->execute($p);
function sc($v){$v=(string)$v;if(preg_match('/^[=+\-@]/',$v))$v="'".$v;return $v;}header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename="citas_'.date('Ymd_His').'.csv"');echo "\xEF\xBB\xBF";$o=fopen('php://output','w');fputcsv($o,['ID','Fecha','Hora','Estado','Mascota','Cliente','Servicio','Veterinario','Costo','Notas'],';');while($r=$st->fetch(PDO::FETCH_ASSOC))fputcsv($o,array_map('sc',array_values($r)),';');fclose($o);exit;
