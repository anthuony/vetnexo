<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) { header('Location: login.php'); exit; }
require_once 'config/database.php'; $db=getDBConnection();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);
if(!$id){$_SESSION['error']='Cliente inválido.';header('Location:listadeclientes.php');exit;}
$stmt=$db->prepare('SELECT * FROM clientes WHERE id=?');$stmt->execute([$id]);$c=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$c){$_SESSION['error']='Cliente no encontrado.';header('Location:listadeclientes.php');exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $nombre=trim($_POST['nombre']??'');$documento_identidad=trim($_POST['documento_identidad']??'');$email=trim($_POST['email']??'');$telefono=trim($_POST['telefono']??'');$direccion=trim($_POST['direccion']??'');$notas=trim($_POST['notas']??'');
 if($nombre==='')$error='El nombre es obligatorio.'; elseif($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))$error='El correo no es válido.'; else{
  $q=$db->prepare('UPDATE clientes SET nombre=?, documento_identidad=?, email=?, telefono=?, direccion=?, notas=? WHERE id=?');$q->execute([$nombre,$documento_identidad,$email,$telefono,$direccion,$notas,$id]);
  $_SESSION['success']='Cliente actualizado correctamente.';header('Location:listadeclientes.php');exit;
 }
 $c=array_merge($c,compact('nombre','documento_identidad','email','telefono','direccion','notas'));
}
?>
<!doctype html><html lang="es"><head>
<?php if(function_exists('av_head')) av_head(); ?><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Editar cliente</title><style>body{font-family:Arial;background:#f6f7f6;color:#26352d;margin:0}.w{max-width:780px;margin:40px auto;padding:18px}.c{background:#fff;padding:28px;border-radius:16px;box-shadow:0 8px 30px #0000000d}h1{color:#0f6c33}.g{display:grid;grid-template-columns:1fr 1fr;gap:15px}.f{grid-column:1/-1}label{font-weight:600;display:block;margin-bottom:6px}input,textarea{width:100%;padding:11px;border:1px solid #ccd7d0;border-radius:8px;box-sizing:border-box}textarea{min-height:90px}.a{display:flex;gap:10px;margin-top:20px}.b{padding:11px 17px;border:0;border-radius:8px;text-decoration:none}.p{background:#0f6c33;color:#fff}.s{background:#e9efeb;color:#26352d}.e{padding:10px;background:#fff0ef;color:#a42319;border-radius:8px}@media(max-width:600px){.g{grid-template-columns:1fr}.f{grid-column:1}}</style>    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head><body><div class="w"><div class="c"><h1>Editar cliente</h1><?php if($error):?><p class="e"><?=htmlspecialchars($error)?></p><?php endif;?><form method="post"><?php echo av_csrf_field(); ?><input type="hidden" name="id" value="<?=$id?>"><div class="g"><div><label>Nombre *</label><input required name="nombre" value="<?=htmlspecialchars($c['nombre']??'')?>"></div><div><label>Documento</label><input name="documento_identidad" value="<?=htmlspecialchars($c['documento_identidad']??'')?>"></div><div><label>Teléfono</label><input name="telefono" value="<?=htmlspecialchars($c['telefono']??'')?>"></div><div><label>Correo</label><input type="email" name="email" value="<?=htmlspecialchars($c['email']??'')?>"></div><div class="f"><label>Dirección</label><textarea name="direccion"><?=htmlspecialchars($c['direccion']??'')?></textarea></div><div class="f"><label>Notas</label><textarea name="notas"><?=htmlspecialchars($c['notas']??'')?></textarea></div></div><div class="a"><button class="b p">Guardar cambios</button><a class="b s" href="listadeclientes.php">Cancelar</a></div></form></div></div></body></html>
