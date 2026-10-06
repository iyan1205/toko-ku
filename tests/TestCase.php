<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tampilan memakai @vite; tanpa ini tes gagal ("Vite manifest not found") bila aset belum di-build.
        $this->withoutVite();
    }
}
