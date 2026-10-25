<?php
$av_invoice_id=(int)($_GET['id']??0);
if($av_invoice_id<1){header('Location: facturas.php');exit;}
require __DIR__.'/nuevafactura.php';
