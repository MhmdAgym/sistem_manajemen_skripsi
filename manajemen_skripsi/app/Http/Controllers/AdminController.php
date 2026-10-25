<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    private function checkAdmin(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json(['message' => 'Hanya admin yang memiliki izin mengakses endpoint ini.'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($deny = $this->checkAdmin($request)) return $deny;

        $admin = Admin::with('user')->get();
        return response()->json($admin);
    }

    public function store(Request $request)
    {
        if ($deny = $this->checkAdmin($request)) return $deny;

        $validatedData = $request->validate([
            'nama_admin' => 'required|string|max:255',
            'users_id'   => 'required|exists:users,id',
        ]);

        $admin = Admin::create($validatedData);

        return response()->json([
            'message' => 'Data admin berhasil ditambahkan',
            'data'    => $admin
        ], 201);
    }

    public function show(Request $request, $id)
    {
        if ($deny = $this->checkAdmin($request)) return $deny;

        $admin = Admin::with('user')->find($id);

        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 404);
        }

        return response()->json($admin);
    }

    public function update(Request $request, $id)
    {
        if ($deny = $this->checkAdmin($request)) return $deny;

        $admin = Admin::find($id);

        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'nama_admin' => 'sometimes|required|string|max:255',
            'users_id'   => 'sometimes|required|exists:users,id',
        ]);

        $admin->update($validatedData);

        return response()->json([
            'message' => 'Data admin berhasil diperbarui',
            'data'    => $admin
        ]);
    }

    public function destroy(Request $request, $id)
    {
        if ($deny = $this->checkAdmin($request)) return $deny;

        $admin = Admin::find($id);

        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 404);
        }

        $admin->delete();

        return response()->json(['message' => 'Data admin berhasil dihapus']);
    }
}
