<?php
if (defined('AUTOVET_BOOTSTRAPPED')) return;
define('AUTOVET_BOOTSTRAPPED', true);
if (!defined('AUTOVET_ROOT')) define('AUTOVET_ROOT', dirname(__DIR__));
date_default_timezone_set('America/Caracas');
error_reporting(E_ALL);
ini_set('display_errors', '0'); ini_set('log_errors', '1');
if (!is_dir(AUTOVET_ROOT.'/_private')) mkdir(AUTOVET_ROOT.'/_private',0755,true);
ini_set('error_log', AUTOVET_ROOT.'/_private/php-errors.log');
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode','1');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
function av_base_url(): string {
    $script=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'/index.php');
    $file=str_replace('\\','/',$_SERVER['SCRIPT_FILENAME']??'');
    $root=str_replace('\\','/',AUTOVET_ROOT);
    $relative=str_starts_with($file,$root.'/')?substr($file,strlen($root)):'';
    return $relative && str_ends_with($script,$relative)?substr($script,0,-strlen($relative)):rtrim(dirname($script),'/.');
}
function av_url(string $path=''): string { return av_base_url().'/'.ltrim($path,'/'); }
function av_csrf_field(): string {return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($_SESSION['csrf_token']??'',ENT_QUOTES,'UTF-8').'">';}
function av_valid_date(string $s): bool {$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);return $d && $d->format('Y-m-d')===$s;}
function av_json_request(): bool {return str_contains($_SERVER['SCRIPT_NAME']??'','/api/') || str_contains(strtolower($_SERVER['HTTP_ACCEPT']??''),'application/json') || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest' || in_array(basename($_SERVER['SCRIPT_NAME']??''),['marcar_pagada.php','cancelar_factura.php','obtener_peluqueria.php']);}
function av_fail(string $message,int $code=422): never {
    http_response_code($code);
    if(av_json_request()){header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'error'=>$message,'message'=>$message],JSON_UNESCAPED_UNICODE);}
    else {echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Revise los datos</title><body style="font-family:Arial;padding:24px"><h1>Revise los datos</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><button onclick="history.back()">Volver al formulario</button></body></html>';}
    exit;
}
function av_head(): void {
    echo '<meta name="csrf-token" content="'.htmlspecialchars($_SESSION['csrf_token']??'',ENT_QUOTES,'UTF-8').'"><script src="'.htmlspecialchars(av_url('js/app-local.js'),ENT_QUOTES,'UTF-8').'" defer></script><link rel="stylesheet" href="'.htmlspecialchars(av_url('css/local.css'),ENT_QUOTES,'UTF-8').'">';
}
if(PHP_SAPI!=='cli' && in_array($_SERVER['REQUEST_METHOD']??'GET',['POST','PUT','PATCH','DELETE'],true)) {
    $token=$_POST['csrf_token']??$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if(!is_string($token) || !hash_equals($_SESSION['csrf_token']??'', $token) || $token==='') av_fail('La sesión del formulario expiró. Recargue la página e intente nuevamente.',403);
}
