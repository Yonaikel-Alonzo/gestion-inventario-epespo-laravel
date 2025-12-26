<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Notifications\ResetPasswordCodeNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordResetController extends Controller
{
    public function forgot(Request $request)
    {
        $request->validate([
            'correo' => ['required', 'email'],
        ]);

        $correo = $request->correo;

        $msg = 'Si el correo existe, te enviaremos un código para restablecer tu contraseña.';

        $user = Usuario::where('correo', $correo)->first();
        if (!$user) {
            return response()->json(['message' => $msg]);
        }

        $code = (string) random_int(100000, 999999);

        DB::table('password_reset_codes')->updateOrInsert(
            ['correo' => $correo],
            [
                'code_hash'  => Hash::make($code),
                'expires_at' => Carbon::now()->addMinutes(10),
                'attempts'   => 0,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $user->notify(new ResetPasswordCodeNotification($code));

        return response()->json(['message' => $msg]);
    }

    // 2) Verificar código + actualizar contraseña
    public function reset(Request $request)
    {
        $request->validate([
            'correo' => ['required', 'email'],
            'codigo' => ['required', 'digits:6'],
            'contrasena' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $correo = $request->correo;

        $row = DB::table('password_reset_codes')->where('correo', $correo)->first();

        if (!$row) {
            return response()->json(['message' => 'Código inválido o expirado'], 422);
        }

        if (Carbon::parse($row->expires_at)->isPast()) {
            DB::table('password_reset_codes')->where('correo', $correo)->delete();
            return response()->json(['message' => 'Código expirado. Solicita uno nuevo.'], 422);
        }

        if ($row->attempts >= 5) {
            return response()->json(['message' => 'Demasiados intentos. Solicita un nuevo código.'], 429);
        }

        if (!Hash::check($request->codigo, $row->code_hash)) {
            DB::table('password_reset_codes')->where('correo', $correo)->update([
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
            return response()->json(['message' => 'Código incorrecto'], 422);
        }

        $user = Usuario::where('correo', $correo)->first();
        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        // Tu mutator en Usuario encripta contrasena, así que asigna en texto plano
        $user->contrasena = $request->contrasena;
        $user->save();

        DB::table('password_reset_codes')->where('correo', $correo)->delete();

        return response()->json(['message' => 'Contraseña actualizada correctamente']);
    }
}
