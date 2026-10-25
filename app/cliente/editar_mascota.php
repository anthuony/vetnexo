<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();
if (empty($_SESSION['user_id']) || ($_SESSION['rol'] ?? '') !== 'cliente' || empty($_SESSION['cliente_id'])) {
    header('Location: ../login.php'); exit;
}
$db = getDBConnection();
$mascota_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$mascota_id) { header('Location: mis_mascotas.php'); exit; }
$stmt = $db->prepare("SELECT * FROM mascotas WHERE id = ? AND cliente_id = ?");
$stmt->execute([$mascota_id, (int)$_SESSION['cliente_id']]);
$mascota = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mascota) { http_response_code(404); die('Mascota no encontrada.'); }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $nombre=trim((string)($_POST['nombre']??''));
    $raza=trim((string)($_POST['raza']??''));
    $color=trim((string)($_POST['color']??''));
    $sexo=(string)($_POST['sexo']??'');
    $fecha=trim((string)($_POST['fecha_nacimiento']??''));
    $peso=str_replace(',','.',trim((string)($_POST['peso']??'')));
    if ($nombre==='') $error='El nombre es obligatorio.';
    elseif (!in_array($sexo,['macho','hembra'],true)) $error='Debe seleccionar el sexo.';
    elseif ($peso!=='' && (!is_numeric($peso) || (float)$peso<0)) $error='Peso inválido.';
    else {
        $upd=$db->prepare("UPDATE mascotas SET nombre=?, raza=?, color=?, sexo=?, fecha_nacimiento=?, peso=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND cliente_id=?");
        $upd->execute([$nombre,$raza?:null,$color?:null,$sexo,$fecha?:null,$peso!==''?(float)$peso:null,$mascota_id,(int)$_SESSION['cliente_id']]);
        $_SESSION['mensaje_success']='Mascota actualizada correctamente.';
        header('Location: ver_mascota.php?id='.$mascota_id); exit;
    }
}
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="es"><head>
<?php if(function_exists('av_head')) av_head(); ?><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Editar mascota</title>
<style>body{font-family:system-ui,sans-serif;background:#f4f7f5;margin:0;padding:24px;color:#24332a}.card{max-width:760px;margin:auto;background:#fff;border-radius:18px;padding:24px;box-shadow:0 10px 30px #00000012}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.full{grid-column:1/-1}label{display:block;font-weight:650;margin-bottom:6px}input,select{width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccd7cf;border-radius:10px}.actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px}.btn{padding:11px 18px;border-radius:10px;text-decoration:none;border:0;cursor:pointer}.primary{background:#0f6c33;color:#fff}.secondary{background:#eef3ef;color:#25402e}.error{background:#feecec;color:#9c1b1b;padding:12px;border-radius:10px;margin-bottom:16px}@media(max-width:650px){.grid{grid-template-columns:1fr}}</style>    <link rel="stylesheet" href="../css/app-optimized.css?v=20260812c">
</head><body>
<div class="card"><h1>Editar mascota</h1><p>Actualiza los datos básicos de <?php echo h($mascota['nombre']); ?>.</p><?php if($error):?><div class="error"><?php echo h($error);?></div><?php endif;?>
<form method="post"><?php echo av_csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$mascota_id;?>"><div class="grid">
<div><label>Nombre</label><input name="nombre" required value="<?php echo h($mascota['nombre']);?>"></div>
<div><label>Raza</label><input name="raza" value="<?php echo h($mascota['raza']??'');?>"></div>
<div><label>Color</label><input name="color" value="<?php echo h($mascota['color']??'');?>"></div>
<div><label>Sexo</label><select name="sexo" required><option value="macho" <?php echo ($mascota['sexo']??'')==='macho'?'selected':'';?>>Macho</option><option value="hembra" <?php echo ($mascota['sexo']??'')==='hembra'?'selected':'';?>>Hembra</option></select></div>
<div><label>Fecha de nacimiento</label><input type="date" name="fecha_nacimiento" value="<?php echo h($mascota['fecha_nacimiento']??'');?>"></div>
<div><label>Peso (kg)</label><input type="number" min="0" step="0.01" name="peso" value="<?php echo h($mascota['peso']??'');?>"></div>
</div><div class="actions"><a class="btn secondary" href="ver_mascota.php?id=<?php echo (int)$mascota_id;?>">Cancelar</a><button class="btn primary" type="submit">Guardar cambios</button></div></form></div></body></html>
