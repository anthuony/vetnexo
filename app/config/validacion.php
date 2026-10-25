<?php
// Las restricciones HTML se repiten en el servidor antes de escribir datos.
function av_validate_request(): void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')return;
    $numbers=['stock_actual_simple','stock_minimo_simple','stock_maximo_simple','precio_compra_simple','precio_venta_simple','precio','precio_compra','precio_venta','stock_actual','stock_minimo','stock_maximo','cantidad','cantidad_ajuste','peso','temperatura','frecuencia_cardiaca','frecuencia_respiratoria','costo','costo_base','costo_adicional','monto','porcentaje_iva','area','costo_estimado'];
    foreach($numbers as $key)if(isset($_POST[$key])&&$_POST[$key]!==''){
        $v=$_POST[$key];if(!is_scalar($v)||!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>99999999)av_fail('El campo '.str_replace('_',' ',$key).' debe ser un número válido mayor o igual a cero.');
        if((str_starts_with($key,'stock_')||str_starts_with($key,'cantidad'))&&floor((float)$v)!=(float)$v)av_fail('Las cantidades y existencias deben ser enteras.');
    }
    if(isset($_POST['stock_minimo'],$_POST['stock_maximo'])&&(float)$_POST['stock_minimo']>(float)$_POST['stock_maximo'])av_fail('El stock mínimo no puede superar al máximo.');
    if(isset($_POST['porcentaje_iva'])&&(float)$_POST['porcentaje_iva']>100)av_fail('Porcentaje de IVA no válido.');
    foreach(['fecha','fecha_nacimiento','fecha_cita','fecha_emision','fecha_pago','fecha_vacunacion','fecha_aplicacion','fecha_proxima','fecha_proxima_dosis','fecha_corte','fecha_programada','fecha_inspeccion','fecha_consulta'] as $key)if(!empty($_POST[$key])&&(!is_string($_POST[$key])||!av_valid_date($_POST[$key])))av_fail('El campo '.str_replace('_',' ',$key).' no contiene una fecha válida.');
    if(!empty($_POST['fecha_nacimiento'])&&$_POST['fecha_nacimiento']>date('Y-m-d'))av_fail('La fecha de nacimiento no puede estar en el futuro.');
    foreach(['hora_cita','hora_corte','hora_programada'] as $key)if(!empty($_POST[$key])&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$_POST[$key]))av_fail('Hora no válida.');
    foreach(['email','email_cliente'] as $key)if(!empty($_POST[$key])&&!filter_var($_POST[$key],FILTER_VALIDATE_EMAIL))av_fail('Ingrese un correo válido.');
    if(isset($_POST['presentaciones'])&&is_array($_POST['presentaciones']))foreach($_POST['presentaciones'] as &$p){
        if(!is_array($p))av_fail('Presentación no válida.');
        foreach(['precio_venta','precio_compra','stock_actual','stock_minimo','stock_maximo','rango_peso_min','rango_peso_max'] as $key){
            if(!isset($p[$key])||$p[$key]===''){$p[$key]=str_starts_with($key,'rango_')?null:0;continue;}
            if(!is_numeric($p[$key])||!is_finite((float)$p[$key])||(float)$p[$key]<0)av_fail('Los valores de las presentaciones deben ser positivos.');
            if(str_starts_with($key,'stock_')&&floor((float)$p[$key])!=(float)$p[$key])av_fail('El stock de las presentaciones debe ser entero.');
        }
        if($p['stock_minimo']>$p['stock_maximo'])av_fail('Revise los límites de stock de la presentación.');
        if($p['rango_peso_min']!==null&&$p['rango_peso_max']!==null&&$p['rango_peso_min']>$p['rango_peso_max'])av_fail('Revise el rango de peso de la presentación.');
    }
}
av_validate_request();
