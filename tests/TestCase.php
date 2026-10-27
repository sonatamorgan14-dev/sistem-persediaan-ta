<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Dipanggil RefreshDatabase sebelum `migrate:fresh`. `DB_URL` di `.env`
     * pernah menimpa koneksi SQLite uji sehingga database pengembangan ikut
     * terhapus; uji berhenti di sini kalau itu terulang.
     */
    protected function beforeRefreshingDatabase()
    {
        $connection = $this->app['db']->connection();

        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Uji menolak berjalan di database %s "%s"; seharusnya SQLite :memory:. Periksa DB_URL di phpunit.xml.',
                $connection->getDriverName(),
                $connection->getDatabaseName(),
            ));
        }
    }
}
