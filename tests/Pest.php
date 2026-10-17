<?php

use ClaudioDekker\Keystone\Tests\Fixtures\AppTestCase;
use ClaudioDekker\Keystone\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__, '../packages/*/tests');
pest()->extend(AppTestCase::class)->in('../packages/*/app-tests');
