<?php

use ClaudioDekker\Keystone\Tests\Fixtures\AppTestCase;
use ClaudioDekker\Keystone\Tests\TestCase;
use ClaudioDekker\Keystone\Tests\Workbench;

pest()->extend(TestCase::class)->in(__DIR__, '../packages/*/tests');
pest()->extend(Workbench::boot(getenv('KEYSTONE_ADAPTER') ?: null) ?? AppTestCase::class)->in('../packages/*/app-tests');
