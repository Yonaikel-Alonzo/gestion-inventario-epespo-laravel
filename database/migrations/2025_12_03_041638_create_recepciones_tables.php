<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recepciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('responsable_id');
            $table->unsignedBigInteger('area_id');
            $table->date('fecha_devolucion');
            $table->string('categoria')->nullable();
            $table->timestamps();

            $table->foreign('responsable_id')
                ->references('id')->on('responsables')
                ->onDelete('cascade');

            $table->foreign('area_id')
                ->references('id')->on('departamentos')
                ->onDelete('cascade');
        });

        Schema::create('recepcion_producto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('recepcion_id');
            $table->unsignedBigInteger('producto_id');
            $table->timestamps();

            $table->foreign('recepcion_id')
                ->references('id')->on('recepciones')
                ->onDelete('cascade');

            $table->foreign('producto_id')
                ->references('id')->on('productos')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recepcion_producto');
        Schema::dropIfExists('recepciones');
    }
};
