<?php

use App\Models\Movimiento;

/**
 * Registrar un evento en la tabla movimientos.
 *
 * @param string $accion        Tipo de acción (Creación, Actualización, Eliminación, etc.)
 * @param string $descripcion   Detalle del evento que ocurrió
 */
if (!function_exists('registrarMovimiento')) {
    function registrarMovimiento($accion, $descripcion)
    {
        Movimiento::create([
            'accion' => $accion,
            'descripcion' => $descripcion,
            'usuario' => auth()->user()->nombre ?? 'Sistema EPESPO',
            'fecha' => now(),
        ]);
    }
}
