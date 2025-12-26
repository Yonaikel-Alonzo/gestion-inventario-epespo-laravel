<?php

namespace App\Http\Controllers;

use App\Models\Recepcion;
use App\Models\Producto;
use App\Models\ProductoAsignacionActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecepcionController extends Controller
{
    private function nombreUsuarioActual(): string
    {
        try {
            $u = auth()->user();
            if (!$u) return 'Sistema';
            $nombre = trim(($u->nombre ?? '') . ' ' . ($u->apellido ?? ''));
            if ($nombre !== '') return $nombre;

            if (!empty($u->name)) return (string) $u->name;
            if (!empty($u->email)) return (string) $u->email;

            return 'Usuario';
        } catch (\Throwable $e) {
            return 'Sistema';
        }
    }
    private function registrarMovimiento(string $accion, string $descripcion): void
    {
        try {
            DB::table('movimientos')->insert([
                'accion'      => $accion,
                'descripcion' => $descripcion,
                'usuario'     => $this->nombreUsuarioActual(),
                'fecha'       => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function index()
    {
        $recepciones = Recepcion::with(['responsable', 'area', 'productos'])
            ->orderBy('fecha_devolucion', 'desc')
            ->get();

        return response()->json($recepciones);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'responsable_id'   => 'required|exists:responsables,id',
            'area_id'          => 'required|exists:departamentos,id',
            'fecha_devolucion' => 'required|date',
            'categoria'        => 'nullable|string|max:255',
            'productos'        => 'required|array|min:1',
            'productos.*'      => 'exists:productos,id',
        ]);

        $productoIds = array_values(array_unique(array_map('intval', $data['productos'])));

        return DB::transaction(function () use ($data, $productoIds) {
            Producto::whereIn('id', $productoIds)->lockForUpdate()->get();
            $rows = ProductoAsignacionActual::whereIn('producto_id', $productoIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('producto_id');

            $invalidos = [];
            foreach ($productoIds as $pid) {
                if (!isset($rows[$pid])) {
                    $invalidos[] = "producto_id {$pid} (no está asignado actualmente)";
                    continue;
                }
                if ((int)$rows[$pid]->responsable_id !== (int)$data['responsable_id']) {
                    $invalidos[] = "producto_id {$pid} (asignado a responsable_id {$rows[$pid]->responsable_id})";
                    continue;
                }
            }

            if (!empty($invalidos)) {
                throw ValidationException::withMessages([
                    'productos' => ['No se puede recepcionar: ' . implode(' | ', $invalidos)]
                ]);
            }

            $recepcion = Recepcion::create([
                'responsable_id'   => $data['responsable_id'],
                'area_id'          => $data['area_id'],
                'fecha_devolucion' => $data['fecha_devolucion'],
                'categoria'        => $data['categoria'] ?? null,
                'acta_id'          => null,
            ]);

            $recepcion->productos()->sync($productoIds);
            ProductoAsignacionActual::whereIn('producto_id', $productoIds)->delete();
            $recepcion->load(['responsable', 'area', 'productos']);
            $lista = $recepcion->productos
                ->map(fn ($p) => trim(($p->codigo ?? '') . ' - ' . ($p->nombre ?? '')))
                ->filter()
                ->values()
                ->implode(', ');

            $desc = "Recepción creada | Responsable: " .
                trim(($recepcion->responsable->nombre ?? '') . ' ' . ($recepcion->responsable->apellido ?? '')) .
                " | Área: " . ($recepcion->area->nombre ?? 'N/A') .
                " | Fecha: " . ($recepcion->fecha_devolucion ?? '') .
                " | Productos: " . ($lista ?: 'N/A');

            $this->registrarMovimiento('Recepción creada', $desc);

            return response()->json($recepcion, 201);
        });
    }

    public function update(Request $request, $id)
    {
        $recepcion = Recepcion::with(['productos', 'responsable', 'area'])->findOrFail($id);

        $data = $request->validate([
            'responsable_id'   => 'required|exists:responsables,id',
            'area_id'          => 'required|exists:departamentos,id',
            'fecha_devolucion' => 'required|date',
            'categoria'        => 'nullable|string|max:255',
            'productos'        => 'required|array|min:1',
            'productos.*'      => 'exists:productos,id',
        ]);
        $oldIds = $recepcion->productos->pluck('id')->map(fn ($x) => (int)$x)->all();
        $newIds = array_values(array_unique(array_map('intval', $data['productos'])));

        sort($oldIds);
        sort($newIds);

        if ($oldIds !== $newIds) {
            throw ValidationException::withMessages([
                'productos' => ['No se permite cambiar los productos de una recepción ya registrada.']
            ]);
        }

        return DB::transaction(function () use ($recepcion, $data) {
            $antesFecha = $recepcion->fecha_devolucion;
            $antesArea  = $recepcion->area_id;
            $antesCat   = $recepcion->categoria;

            $recepcion->update([
                'responsable_id'   => $data['responsable_id'],
                'area_id'          => $data['area_id'],
                'fecha_devolucion' => $data['fecha_devolucion'],
                'categoria'        => $data['categoria'] ?? null,
            ]);

            $recepcion->load(['responsable', 'area', 'productos']);

            $lista = $recepcion->productos
                ->map(fn ($p) => trim(($p->codigo ?? '') . ' - ' . ($p->nombre ?? '')))
                ->filter()
                ->values()
                ->implode(', ');

            $cambios = [];
            if ($antesFecha !== $recepcion->fecha_devolucion) $cambios[] = "Fecha: {$antesFecha} → {$recepcion->fecha_devolucion}";
            if ((string)$antesArea !== (string)$recepcion->area_id) $cambios[] = "Área ID: {$antesArea} → {$recepcion->area_id}";
            if ((string)$antesCat !== (string)$recepcion->categoria) $cambios[] = "Categoría: {$antesCat} → {$recepcion->categoria}";

            $desc = "Recepción actualizada (ID {$recepcion->id}) | Responsable: " .
                trim(($recepcion->responsable->nombre ?? '') . ' ' . ($recepcion->responsable->apellido ?? '')) .
                " | Área: " . ($recepcion->area->nombre ?? 'N/A') .
                " | Productos: " . ($lista ?: 'N/A') .
                (count($cambios) ? " | Cambios: " . implode(' | ', $cambios) : "");

            $this->registrarMovimiento('Recepción actualizada', $desc);

            return response()->json($recepcion);
        });
    }

    public function destroy($id)
    {
        $recepcion = Recepcion::with(['responsable', 'area', 'productos'])->findOrFail($id);

        return DB::transaction(function () use ($recepcion) {

            $lista = $recepcion->productos
                ->map(fn ($p) => trim(($p->codigo ?? '') . ' - ' . ($p->nombre ?? '')))
                ->filter()
                ->values()
                ->implode(', ');

            $desc = "Recepción eliminada (ID {$recepcion->id}) | Responsable: " .
                trim(($recepcion->responsable->nombre ?? '') . ' ' . ($recepcion->responsable->apellido ?? '')) .
                " | Área: " . ($recepcion->area->nombre ?? 'N/A') .
                " | Fecha: " . ($recepcion->fecha_devolucion ?? '') .
                " | Productos: " . ($lista ?: 'N/A');

            $recepcion->delete();

            $this->registrarMovimiento('Recepción eliminada', $desc);

            return response()->json(['message' => 'Recepción eliminada'], 200);
        });
    }
}
