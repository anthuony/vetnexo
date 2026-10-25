<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) { header('Location: login.php'); exit; }
require_once 'config/database.php';
$db = getDBConnection();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) { $_SESSION['error']='Proveedor inválido.'; header('Location: proveedores.php'); exit; }
$stmt=$db->prepare('SELECT * FROM proveedores WHERE id=?'); $stmt->execute([$id]); $p=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) { $_SESSION['error']='Proveedor no encontrado.'; header('Location: proveedores.php'); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $nombre=trim($_POST['nombre']??''); $contacto=trim($_POST['contacto']??''); $telefono=trim($_POST['telefono']??'');
    $email=trim($_POST['email']??''); $direccion=trim($_POST['direccion']??''); $activo=isset($_POST['activo'])?1:0;
    if ($nombre==='') $error='El nombre del proveedor es obligatorio.';
    elseif ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) $error='El correo electrónico no es válido.';
    else {
        $q=$db->prepare('UPDATE proveedores SET nombre=?, contacto=?, telefono=?, email=?, direccion=?, activo=? WHERE id=?');
        $q->execute([$nombre,$contacto,$telefono,$email,$direccion,$activo,$id]);
        $_SESSION['success']='Proveedor actualizado correctamente.'; header('Location: proveedores.php'); exit;
    }
    $p=array_merge($p, compact('nombre','contacto','telefono','email','direccion','activo'));
}
?>
<!doctype html><html lang="es"><head>
<?php if(function_exists('av_head')) av_head(); ?><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Editar proveedor</title>
<style>body{font-family:Arial,sans-serif;background:#f5f7f6;margin:0;color:#26352d}.wrap{max-width:760px;margin:40px auto;padding:0 18px}.card{background:#fff;border:1px solid #e2e8e4;border-radius:16px;padding:28px;box-shadow:0 8px 30px #0000000d}h1{margin-top:0;color:#0f6c33}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.full{grid-column:1/-1}label{display:block;font-weight:600;margin-bottom:6px}input,textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cfd8d2;border-radius:8px}textarea{min-height:90px}.actions{display:flex;gap:10px;margin-top:22px}.btn{padding:11px 18px;border:0;border-radius:8px;text-decoration:none;cursor:pointer}.primary{background:#0f6c33;color:white}.secondary{background:#e9efeb;color:#26352d}.err{background:#fff1f0;color:#a61b12;padding:10px;border-radius:8px;margin-bottom:14px}@media(max-width:600px){.grid{grid-template-columns:1fr}.full{grid-column:1}}</style>    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head><body><div class="wrap"><div class="card"><h1>Editar proveedor</h1>
<?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="post"><?php echo av_csrf_field(); ?><input type="hidden" name="id" value="<?=$id?>"><div class="grid">
<div><label>Nombre *</label><input name="nombre" required value="<?=htmlspecialchars($p['nombre']??'')?>"></div>
<div><label>Contacto</label><input name="contacto" value="<?=htmlspecialchars($p['contacto']??'')?>"></div>
<div><label>Teléfono</label><input name="telefono" value="<?=htmlspecialchars($p['telefono']??'')?>"></div>
<div><label>Correo</label><input type="email" name="email" value="<?=htmlspecialchars($p['email']??'')?>"></div>
<div class="full"><label>Dirección</label><textarea name="direccion"><?=htmlspecialchars($p['direccion']??'')?></textarea></div>
<div class="full"><label><input style="width:auto" type="checkbox" name="activo" value="1" <?=!empty($p['activo'])?'checked':''?>> Proveedor activo</label></div></div>
<div class="actions"><button class="btn primary" type="submit">Guardar cambios</button><a class="btn secondary" href="proveedores.php">Cancelar</a></div></form></div></div></body></html>
