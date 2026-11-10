<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test berjalan di SQLite, yang tidak membatasi panjang nama. MySQL/MariaDB di server menolak
 * nama index dan constraint lebih dari 64 karakter, dan karena DDL MySQL tidak bisa di-rollback,
 * migration yang gagal di tengah meninggalkan tabel setengah jadi. Beri nama pendek, misal
 * $table->unique([...], 'nama_pendek_unique').
 */
class SkemaDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private const MYSQL_IDENTIFIER_LIMIT = 64;

    public function test_nama_index_tidak_melebihi_batas_mysql(): void
    {
        $terlaluPanjang = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND name NOT LIKE 'sqlite_%'"))
            ->pluck('name')
            ->filter(fn (string $name) => strlen($name) > self::MYSQL_IDENTIFIER_LIMIT)
            ->values()->all();

        $this->assertSame([], $terlaluPanjang);
    }

    /** Laravel menamai foreign key {tabel}_{kolom}_foreign bila tidak diberi nama. */
    public function test_nama_foreign_key_bawaan_tidak_melebihi_batas_mysql(): void
    {
        $terlaluPanjang = [];

        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
            foreach (DB::select("PRAGMA foreign_key_list(\"{$table->name}\")") as $foreignKey) {
                $name = strtolower("{$table->name}_{$foreignKey->from}_foreign");
                if (strlen($name) > self::MYSQL_IDENTIFIER_LIMIT) {
                    $terlaluPanjang[] = $name;
                }
            }
        }

        $this->assertSame([], $terlaluPanjang);
    }
}
