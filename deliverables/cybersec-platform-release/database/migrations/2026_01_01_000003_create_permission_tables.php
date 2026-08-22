<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');

        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], function (Blueprint $table) use ($teams, $columnNames) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            if ($teams || config('permission.testing')) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                $table->index($columnNames['team_foreign_key']);
            }
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['model_has_permissions'], function (Blueprint $table) use ($teams, $tableNames, $columnNames) {
            $permissionForeignKey = $columnNames['permission_pivot_key'] ?? 'permission_id';
            $table->unsignedBigInteger($permissionForeignKey);
            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type']);
            $table->foreign($permissionForeignKey)->references('id')->on($tableNames['permissions'])->onDelete('cascade');
            if ($teams || config('permission.testing')) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                $table->index($columnNames['team_foreign_key']);
            }
            $table->primary([$permissionForeignKey, $columnNames['model_morph_key'], 'model_type'],
                'model_has_permissions_permission_model_type_primary');
        });

        Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use ($teams, $tableNames, $columnNames) {
            $roleForeignKey = $columnNames['role_pivot_key'] ?? 'role_id';
            $table->unsignedBigInteger($roleForeignKey);
            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type']);
            $table->foreign($roleForeignKey)->references('id')->on($tableNames['roles'])->onDelete('cascade');
            if ($teams || config('permission.testing')) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                $table->index($columnNames['team_foreign_key']);
            }
            $table->primary([$roleForeignKey, $columnNames['model_morph_key'], 'model_type'],
                'model_has_roles_role_model_type_primary');
        });

        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames, $columnNames) {
            $permissionForeignKey = $columnNames['permission_pivot_key'] ?? 'permission_id';
            $roleForeignKey = $columnNames['role_pivot_key'] ?? 'role_id';
            $table->unsignedBigInteger($permissionForeignKey);
            $table->unsignedBigInteger($roleForeignKey);
            $table->foreign($permissionForeignKey)->references('id')->on($tableNames['permissions'])->onDelete('cascade');
            $table->foreign($roleForeignKey)->references('id')->on($tableNames['roles'])->onDelete('cascade');
            $table->primary([$permissionForeignKey, $roleForeignKey], 'role_has_permissions_permission_id_role_id_primary');
        });
    }
    public function down(): void
    {
        $tableNames = config('permission.table_names');
        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);
        Schema::dropIfExists($tableNames['roles']);
        Schema::dropIfExists($tableNames['permissions']);
    }
};
