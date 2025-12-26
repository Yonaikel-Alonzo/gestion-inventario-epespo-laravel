<?php

namespace App\Http\Controllers;

use App\Models\Asignacion;
use App\Models\Producto;
use App\Models\ProductoAsignacionActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AsignacionController extends Controller
{
    public function index()
    {
        $asignaciones = Asignacion::with(['responsable', 'area', 'productos'])
            ->orderBy('fecha_asignacion', 'desc')
            ->get();

        return response()->json($asignaciones);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'responsable_id'    => 'required|exists:responsables,id',
            'area_id'           => 'required|exists:departamentos,id',
            'fecha_asignacion'  => 'required|date',
            'categoria'         => 'required|string|max:255',
            'productos'         => 'required|array|min:1',
            'productos.*'       => 'exists:productos,id',
        ]);

        // Normalizar IDs
        $productoIds = array_values(array_unique(array_map('intval', $data['productos'])));

        return DB::transaction(function () use ($data, $productoIds) {

            // ✅ Lock real para evitar concurrencia (2 admins asignando a la vez)
            Producto::whereIn('id', $productoIds)->lockForUpdate()->get();

            // ✅ Si existe estado actual, ese producto ya está asignado (a alguien)
            $ocupados = ProductoAsignacionActual::with(['producto', 'responsable'])
                ->whereIn('producto_id', $productoIds)
                ->lockForUpdate()
                ->get();

            if ($ocupados->count() > 0) {
                $lista = $ocupados->map(function ($x) {
                    $p = $x->producto ? "{$x->producto->codigo} - {$x->producto->nombre}" : "producto_id {$x->producto_id}";
                    $r = $x->responsable ? trim($x->responsable->nombre . ' ' . $x->responsable->apellido) : "responsable_id {$x->responsable_id}";
                    return "{$p} (Asignado a: {$r})";
                })->implode(' | ');

                throw ValidationException::withMessages([
                    'productos' => ["No se puede asignar: hay bienes ya asignados. {$lista}"]
                ]);
            }

            $asignacion = Asignacion::create([
                'responsable_id'   => $data['responsable_id'],
                'area_id'          => $data['area_id'],
                'fecha_asignacion' => $data['fecha_asignacion'],
                'categoria'        => $data['categoria'],
                'acta_id'          => null,
            ]);

            $asignacion->productos()->sync($productoIds);

            // ✅ Crear estado actual (1 fila por producto). Si existe fila, unique(producto_id) fallaría.
            $rows = [];
            $now = now();
            foreach ($productoIds as $pid) {
                $rows[] = [
                    'producto_id'      => $pid,
                    'responsable_id'   => $data['responsable_id'],
                    'area_id'          => $data['area_id'],
                    'asignacion_id'    => $asignacion->id,
                    'fecha_asignacion' => $data['fecha_asignacion'],
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
            DB::table('producto_asignaciones_actuales')->insert($rows);

            // ✅ Movimiento (si tu helper existe)
            if (function_exists('registrarMovimiento')) {
                $asignacion->load(['responsable', 'area', 'productos']);

                $responsableNombre = $asignacion->responsable
                    ? trim($asignacion->responsable->nombre . ' ' . $asignacion->responsable->apellido)
                    : "ID {$asignacion->responsable_id}";

                $areaNombre = $asignacion->area
                    ? $asignacion->area->nombre . ($asignacion->area->ubicacion ? ' - ' . $asignacion->area->ubicacion : '')
                    : 'Sin área';

                $listaProductos = $asignacion->productos
                    ->map(fn ($p) => "{$p->codigo} - {$p->nombre}")
                    ->implode(', ');

                $descripcion = "Se asignaron los productos: {$listaProductos} al responsable {$responsableNombre} en el área {$areaNombre}.";
                registrarMovimiento('Creación de asignación', $descripcion);
            }

            return response()->json($asignacion->load(['responsable','area','productos']), 201);
        });
    }

    public function update(Request $request, $id)
    {
        $asignacion = Asignacion::with('productos')->findOrFail($id);

        $data = $request->validate([
            'responsable_id'    => 'required|exists:responsables,id',
            'area_id'           => 'required|exists:departamentos,id',
            'fecha_asignacion'  => 'required|date',
            'categoria'         => 'required|string|max:255',
            'productos'         => 'required|array|min:1',
            'productos.*'       => 'exists:productos,id',
        ]);

        $newIds = array_values(array_unique(array_map('intval', $data['productos'])));

        return DB::transaction(function () use ($asignacion, $data, $newIds) {

            $oldIds = $asignacion->productos->pluck('id')->map(fn ($x) => (int)$x)->all();

            $added   = array_values(array_diff($newIds, $oldIds));
            $removed = array_values(array_diff($oldIds, $newIds));
            $allIds  = array_values(array_unique(array_merge($oldIds, $newIds)));

            // ✅ Lock real
            Producto::whereIn('id', $allIds)->lockForUpdate()->get();

            // Bloquear estados actuales existentes
            $estados = ProductoAsignacionActual::whereIn('producto_id', $allIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('producto_id');

            // ✅ Validar: los agregados NO pueden pertenecer a otra asignación
            foreach ($added as $pid) {
                if (isset($estados[$pid]) && (int)$estados[$pid]->asignacion_id !== (int)$asignacion->id) {
                    throw ValidationException::withMessages([
                        'productos' => ["El producto_id {$pid} ya está asignado en otra asignación (id {$estados[$pid]->asignacion_id})."]
                    ]);
                }
            }

            // ✅ Validar: los que ya estaban, deben seguir perteneciendo a esta asignación (consistencia)
            foreach ($oldIds as $pid) {
                if (isset($estados[$pid]) && (int)$estados[$pid]->asignacion_id !== (int)$asignacion->id) {
                    throw ValidationException::withMessages([
                        'productos' => ["El producto_id {$pid} ya no pertenece a esta asignación. Refresca la página (estado cambió)."]
                    ]);
                }
            }

            // Actualizar asignación
            $asignacion->update([
                'responsable_id'   => $data['responsable_id'],
                'area_id'          => $data['area_id'],
                'fecha_asignacion' => $data['fecha_asignacion'],
                'categoria'        => $data['categoria'],
            ]);

            $asignacion->productos()->sync($newIds);

            // ✅ Eliminar estado de removidos solo si pertenecen a ESTA asignación
            if (count($removed) > 0) {
                ProductoAsignacionActual::whereIn('producto_id', $removed)
                    ->where('asignacion_id', $asignacion->id)
                    ->delete();
            }

            // ✅ Upsert/actualizar estado de los productos actuales de esta asignación
            foreach ($newIds as $pid) {
                ProductoAsignacionActual::updateOrCreate(
                    ['producto_id' => $pid],
                    [
                        'responsable_id'   => $data['responsable_id'],
                        'area_id'          => $data['area_id'],
                        'asignacion_id'    => $asignacion->id,
                        'fecha_asignacion' => $data['fecha_asignacion'],
                    ]
                );
            }

            if (function_exists('registrarMovimiento')) {
                $asignacion->load(['responsable','area','productos']);

                $responsableNombre = $asignacion->responsable
                    ? trim($asignacion->responsable->nombre . ' ' . $asignacion->responsable->apellido)
                    : "ID {$asignacion->responsable_id}";

                $areaNombre = $asignacion->area
                    ? $asignacion->area->nombre . ($asignacion->area->ubicacion ? ' - ' . $asignacion->area->ubicacion : '')
                    : 'Sin área';

                $listaProductos = $asignacion->productos
                    ->map(fn ($p) => "{$p->codigo} - {$p->nombre}")
                    ->implode(', ');

                $descripcion = "Se actualizaron las asignaciones de {$listaProductos} para el responsable {$responsableNombre} en el área {$areaNombre}.";
                registrarMovimiento('Actualización de asignación', $descripcion);
            }

            return response()->json($asignacion->load(['responsable','area','productos']));
        });
    }

    public function destroy($id)
    {
        $asignacion = Asignacion::with(['responsable', 'area'])->findOrFail($id);

        return DB::transaction(function () use ($asignacion) {

            // ✅ Eliminar estado actual asociado
            ProductoAsignacionActual::where('asignacion_id', $asignacion->id)->delete();

            $nombreResp = $asignacion->responsable
                ? trim($asignacion->responsable->nombre . ' ' . $asignacion->responsable->apellido)
                : "responsable ID {$asignacion->responsable_id}";

            $nombreArea = $asignacion->area
                ? trim($asignacion->area->nombre . ($asignacion->area->ubicacion ? ' - ' . $asignacion->area->ubicacion : ''))
                : "área ID {$asignacion->area_id}";

            $idAsign = $asignacion->id;
            $asignacion->delete();

            if (function_exists('registrarMovimiento')) {
                registrarMovimiento(
                    'Eliminación de asignación',
                    "Se eliminó la asignación {$idAsign} del responsable {$nombreResp} en el área {$nombreArea}."
                );
            }

            return response()->json(['message' => 'Asignación eliminada']);
        });
    }
}
