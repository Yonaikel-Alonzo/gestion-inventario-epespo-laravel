<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('responsable_id');
            $table->unsignedBigInteger('area_id');
            $table->date('fecha_asignacion');
            $table->string('categoria');
            $table->unsignedBigInteger('acta_id')->nullable();

            $table->timestamps();
            $table->foreign('responsable_id')
                  ->references('id')
                  ->on('responsables')
                  ->onDelete('cascade');

            $table->foreign('area_id')
                  ->references('id')
                  ->on('departamentos')
                  ->onDelete('cascade');
            $table->foreign('acta_id')
                  ->references('id')
                  ->on('actas')
                  ->onDelete('set null');
        });
        Schema::create('asignacion_producto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asignacion_id');
            $table->unsignedBigInteger('producto_id');
            $table->timestamps();

            $table->foreign('asignacion_id')
                  ->references('id')
                  ->on('asignaciones')
                  ->onDelete('cascade');

            $table->foreign('producto_id')
                  ->references('id')
                  ->on('productos')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignacion_producto');
        Schema::dropIfExists('asignaciones');
    }
};
