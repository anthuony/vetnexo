<?php
// config/funciones.php

if (!function_exists('getRolColor')) {
    function getRolColor($rol) {
        switch($rol) {
            case 'admin':
                return '#F44336';
            case 'veterinarian':
                return '#2196F3';
            case 'assistant':
                return '#FF9800';
            case 'reception':
                return '#4CAF50';
            default:
                return '#9E9E9E';
        }
    }
}

if (!function_exists('getRolTexto')) {
    function getRolTexto($rol) {
        switch($rol) {
            case 'admin':
                return 'Administrador';
            case 'veterinarian':
                return 'Veterinario';
            case 'assistant':
                return 'Asistente';
            case 'reception':
                return 'Recepcionista';
            case 'cliente':
                return 'Cliente';
            default:
                return ucfirst($rol);
        }
    }
}

if (!function_exists('getEstadoCitaClass')) {
    function getEstadoCitaClass($estado) {
        switch($estado) {
            case 'scheduled':
                return 'warning';
            case 'confirmed':
                return 'info';
            case 'in_progress':
                return 'primary';
            case 'completed':
                return 'success';
            case 'cancelled':
                return 'danger';
            case 'no_show':
                return 'secondary';
            case 'proxima':
                return 'info';
            default:
                return 'secondary';
        }
    }
}

if (!function_exists('getEstadoCitaTexto')) {
    function getEstadoCitaTexto($estado) {
        switch($estado) {
            case 'scheduled':
                return 'Pendiente';
            case 'confirmed':
                return 'Confirmada';
            case 'in_progress':
                return 'En progreso';
            case 'completed':
                return 'Completada';
            case 'cancelled':
                return 'Cancelada';
            case 'no_show':
                return 'No asistió';
            case 'proxima':
                return 'Próxima';
            default:
                return ucfirst($estado);
        }
    }
}

if (!function_exists('calcularEdad')) {
    function calcularEdad($fecha_nacimiento) {
        if (!$fecha_nacimiento) return 'No registrada';
        
        $nacimiento = new DateTime($fecha_nacimiento);
        $hoy = new DateTime();
        $diferencia = $hoy->diff($nacimiento);
        
        if ($diferencia->y > 0) {
            return $diferencia->y . ' año' . ($diferencia->y != 1 ? 's' : '');
        } elseif ($diferencia->m > 0) {
            return $diferencia->m . ' mes' . ($diferencia->m != 1 ? 'es' : '');
        } else {
            return $diferencia->d . ' día' . ($diferencia->d != 1 ? 's' : '');
        }
    }
}
?>