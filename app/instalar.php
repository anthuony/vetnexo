<?php
require_once __DIR__.'/config/bootstrap.php';
require_once __DIR__.'/config/backup.php';
if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true))av_fail('Ejecute el instalador desde localhost en el equipo donde está Apache.',403);
$locked=is_file(__DIR__.'/_private/installed.lock');
if($locked){require_once __DIR__.'/config/auth.php';require_admin();}
$config=require __DIR__.'/config/env.php';
foreach(['db_host','db_port','db_name','db_user','db_pass'] as $key){$v=getenv('AUTOVET_'.strtoupper($key));if($v!==false)$config[$key]=$v;}
$checks=['PHP 8.1 o superior'=>version_compare(PHP_VERSION,'8.1','>=')];
foreach(['pdo_mysql','mysqli','mbstring','dom','fileinfo'] as $ext)$checks['Extensión '.$ext]=extension_loaded($ext);
foreach(['_private','uploads','uploads/comprobantes'] as $dir){if(!is_dir(__DIR__.'/'.$dir))mkdir(__DIR__.'/'.$dir,0755,true);$checks['Escritura en '.$dir]=is_writable(__DIR__.'/'.$dir);}
$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST')try{
    if(in_array(false,$checks,true))throw new RuntimeException('Habilite los requisitos indicados y reinicie Apache.');
    $name=trim($_POST['username']??'');$email=trim($_POST['email']??'');$password=$_POST['password']??'';
    if(!$locked && (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$name)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<8))throw new RuntimeException('Indique un usuario de 3 a 50 caracteres, correo válido y contraseña de al menos 8 caracteres.');
    if(!preg_match('/^[A-Za-z0-9_]+$/',$config['db_name']))throw new RuntimeException('Nombre de base de datos no válido.');
    $db=new PDO('mysql:host='.$config['db_host'].';port='.$config['db_port'].';charset=utf8mb4',$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $database='`'.$config['db_name'].'`';$db->exec('CREATE DATABASE IF NOT EXISTS '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->exec('USE '.$database);
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if($locked) throw new RuntimeException('VetNexo ya está instalado. Inicie sesión para continuar.');
    if($tables) throw new RuntimeException('Use una base de datos nueva y vacía para esta versión demo. No se importaron ni modificaron registros existentes.');
    $schema=file_get_contents(__DIR__.'/database/schema_clean_structure_only.sql');
    if($schema===false) throw new RuntimeException('No se encontró el esquema limpio de instalación.');
    av_execute_sql($db,$schema);
    if(!$locked){
        $q=$db->prepare('SELECT COUNT(*) FROM usuarios WHERE username=? OR email=?');$q->execute([$name,$email]);if($q->fetchColumn())throw new RuntimeException('Ese usuario o correo ya existe en los datos importados. Elija otro para conservar las cuentas originales.');
        $db->prepare("INSERT INTO usuarios(nombre,email,username,password_hash,rol,estado) VALUES(?,?,?,?,'admin','active')")->execute([$name,$email,$name,password_hash($password,PASSWORD_DEFAULT)]);
    }
    file_put_contents(__DIR__.'/_private/installed.lock',date('c'),LOCK_EX);
    $success='Instalación completada. Ya puede iniciar sesión'.(!$locked?' con el usuario que acaba de crear.':'.');$locked=true;
}catch(Throwable $e){error_log('[instalar] '.$e->getMessage());$error=$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'No se pudo completar la instalación. Verifique que MySQL esté iniciado, los datos en config/env.php y el registro _private/php-errors.log.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar VetNexo</title><?php av_head();?><style>body{background:#edf5ef;font:16px Arial;margin:0;color:#203b2e}main{max-width:760px;margin:32px auto;padding:24px;background:white;border-radius:18px}label{display:block;margin:14px 0 5px}input{width:100%;padding:12px;border:1px solid #a9b8ae;border-radius:8px}button,.button{display:inline-block;margin-top:20px;padding:13px 20px;background:#0f6c33;color:white;border:0;border-radius:8px;text-decoration:none;cursor:pointer}li{margin:8px 0}small{line-height:1.6}@media(max-width:600px){main{margin:12px;padding:20px}}</style></head><body><main><h1>VetNexo · Instalación local</h1><p>Inicie Apache y MySQL. La configuración predeterminada usa <strong>localhost:3306</strong>, base <strong>vetnexo</strong>, usuario <strong>root</strong> sin contraseña.</p><ul><?php foreach($checks as $label=>$ok):?><li><?= $ok?'✓':'✗' ?> <?= htmlspecialchars($label) ?></li><?php endforeach;?></ul>
<?php if($error):?><p class="av-error"><?= htmlspecialchars($error) ?></p><?php endif;?>
<?php if($success):?><p class="av-success"><?= htmlspecialchars($success) ?></p><a class="button" href="login.php">Iniciar sesión</a>
<?php else:?><form method="post"><?= av_csrf_field() ?><?php if(!$locked):?><h2>Cree su administrador</h2><label for="username">Usuario nuevo</label><input id="username" name="username" required pattern="[A-Za-z0-9_.-]{3,50}" autocomplete="username"><label for="email">Correo</label><input id="email" name="email" type="email" required autocomplete="email"><label for="password">Contraseña (8 caracteres o más)</label><input id="password" name="password" type="password" minlength="8" required autocomplete="new-password"><?php endif;?><p><small>La instalación solo admite una base de datos nueva y vacía. Nunca use esta demo para importar datos de una clínica real.</small></p><button type="submit"><?= $locked?'Actualizar estructura':'Instalar y crear administrador' ?></button></form><?php endif;?></main></body></html>
