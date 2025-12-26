<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asignacion extends Model
{
    use HasFactory;

    protected $table = 'asignaciones';

    protected $fillable = [
        'responsable_id',
        'area_id',
        'fecha_asignacion',
        'categoria',
        'acta_id',
    ];

    protected $casts = [
        'fecha_asignacion' => 'date',
    ];

    public function responsable()
    {
        return $this->belongsTo(Responsable::class, 'responsable_id');
    }

    public function area()
    {
        return $this->belongsTo(Departamento::class, 'area_id');
    }

    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'asignacion_producto');
    }

    public function acta()
    {
        return $this->belongsTo(Acta::class, 'acta_id');
    }
}
